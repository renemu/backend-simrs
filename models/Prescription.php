<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;

/**
 * Prescription model matching table `prescriptions` (FR-05, BR-07 atomic stock dispensing).
 *
 * @property int $id
 * @property string $prescription_number
 * @property int $visit_id
 * @property int $patient_id
 * @property int $doctor_id
 * @property string $status
 * @property string|null $notes
 * @property int|null $dispensed_by
 * @property string|null $dispensed_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 *
 * @property-read PrescriptionItem[] $items
 * @property-read Patient|null $patient
 * @property-read Doctor|null $doctor
 * @property-read Visit|null $visit
 */
class Prescription extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPENSED = 'dispensed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%prescriptions}}';
    }

    public function rules(): array
    {
        return [
            [['prescription_number', 'visit_id', 'patient_id', 'doctor_id'], 'required'],
            [['visit_id', 'patient_id', 'doctor_id', 'dispensed_by', 'created_by', 'updated_by'], 'integer'],
            [['prescription_number'], 'string', 'max' => 32],
            [['prescription_number'], 'unique'],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_DISPENSED, self::STATUS_CANCELLED]],
            [['status'], 'default', 'value' => self::STATUS_PENDING],
            [['notes'], 'string'],
            [['dispensed_at', 'created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if ($insert && empty($this->created_at)) {
            $this->created_at = $now;
        }
        $this->updated_at = $now;
        return true;
    }

    public static function generateNumber(): string
    {
        $prefix = 'RX-' . date('Ymd') . '-';
        $count = (int) static::find()->where(['like', 'prescription_number', $prefix . '%', false])->count() + 1;
        do {
            $candidate = sprintf('%s%04d', $prefix, $count);
            $exists = static::find()->where(['prescription_number' => $candidate])->exists();
            $count++;
        } while ($exists);
        return $candidate;
    }

    /**
     * Atomically validates & deducts medicine stock with row-level locks (SELECT ... FOR UPDATE),
     * records stock movements, and marks the prescription as dispensed (BR-07).
     *
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    public static function dispenseAtomic(int $prescriptionId, ?User $actor): self
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            // 1. Row-level lock on prescription to prevent double-dispense
            $lockedRow = $db->createCommand(
                'SELECT id, status, prescription_number FROM {{%prescriptions}} WHERE id = :id FOR UPDATE',
                [':id' => $prescriptionId]
            )->queryOne();

            if (!$lockedRow) {
                throw new NotFoundHttpException("Prescription with ID $prescriptionId not found.");
            }

            if ($lockedRow['status'] !== self::STATUS_PENDING) {
                throw new ConflictHttpException(
                    sprintf('Prescription %s cannot be dispensed because its status is "%s".', $lockedRow['prescription_number'], $lockedRow['status'])
                );
            }

            // 2. Fetch items and aggregate required quantity per medicine_id (sorted ASC to avoid deadlocks)
            $items = PrescriptionItem::find()
                ->where(['prescription_id' => $prescriptionId])
                ->orderBy(['medicine_id' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            if (empty($items)) {
                throw new ConflictHttpException('Cannot dispense a prescription with no medicine items.');
            }

            $requiredByMed = [];
            foreach ($items as $item) {
                $medId = (int) $item->medicine_id;
                $requiredByMed[$medId] = ($requiredByMed[$medId] ?? 0) + (int) $item->quantity;
            }
            ksort($requiredByMed);

            $now = date('Y-m-d H:i:s');

            // 3. Lock each medicine row FOR UPDATE, verify non-negative stock, deduct atomically, and log movement
            foreach ($requiredByMed as $medId => $requiredQty) {
                $medRow = $db->createCommand(
                    'SELECT id, name, stock, is_active FROM {{%medicines}} WHERE id = :id FOR UPDATE',
                    [':id' => $medId]
                )->queryOne();

                if (!$medRow) {
                    throw new ConflictHttpException("Medicine ID $medId no longer exists.");
                }

                $currentStock = (int) $medRow['stock'];
                if ($currentStock < $requiredQty) {
                    throw new ConflictHttpException(
                        sprintf(
                            'Insufficient stock for "%s": requested %d, available %d.',
                            $medRow['name'],
                            $requiredQty,
                            $currentStock
                        )
                    );
                }

                $newStock = $currentStock - $requiredQty;
                $affected = $db->createCommand(
                    'UPDATE {{%medicines}} SET stock = stock - :qty, updated_by = :actor, updated_at = :now WHERE id = :id AND stock >= :qty',
                    [
                        ':qty' => $requiredQty,
                        ':actor' => $actor?->id,
                        ':now' => $now,
                        ':id' => $medId,
                    ]
                )->execute();

                if ($affected !== 1) {
                    throw new ConflictHttpException(
                        sprintf('Concurrent stock conflict for "%s". Please retry.', $medRow['name'])
                    );
                }

                $movement = new MedicineStockMovement();
                $movement->medicine_id = $medId;
                $movement->movement_type = MedicineStockMovement::TYPE_DISPENSE;
                $movement->quantity_delta = -$requiredQty;
                $movement->stock_before = $currentStock;
                $movement->stock_after = $newStock;
                $movement->reference_type = 'prescription';
                $movement->reference_id = $prescriptionId;
                $movement->notes = sprintf('Dispensed via %s', $lockedRow['prescription_number']);
                $movement->actor_id = $actor?->id;
                $movement->created_at = $now;
                $movement->save(false);
            }

            // 4. Mark prescription as dispensed
            $db->createCommand()->update(
                '{{%prescriptions}}',
                [
                    'status' => self::STATUS_DISPENSED,
                    'dispensed_by' => $actor?->id,
                    'dispensed_at' => $now,
                    'updated_by' => $actor?->id,
                    'updated_at' => $now,
                ],
                ['id' => $prescriptionId]
            )->execute();

            $transaction->commit();

            /** @var self $prescription */
            $prescription = static::find()
                ->with(['items.medicine', 'patient', 'doctor', 'visit'])
                ->where(['id' => $prescriptionId])
                ->one();

            return $prescription;
        } catch (\Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }

    public function getItems(): ActiveQuery
    {
        return $this->hasMany(PrescriptionItem::class, ['prescription_id' => 'id'])->orderBy(['id' => SORT_ASC]);
    }

    public function getPatient(): ActiveQuery
    {
        return $this->hasOne(Patient::class, ['id' => 'patient_id']);
    }

    public function getDoctor(): ActiveQuery
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }

    public function getVisit(): ActiveQuery
    {
        return $this->hasOne(Visit::class, ['id' => 'visit_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'prescription_number',
            'visit_id',
            'patient_id',
            'doctor_id',
            'status',
            'notes',
            'dispensed_by',
            'dispensed_at',
            'patient' => fn(self $m) => $m->patient ? [
                'id' => $m->patient->id,
                'medical_record_number' => $m->patient->medical_record_number,
                'full_name' => $m->patient->full_name,
            ] : null,
            'doctor' => fn(self $m) => $m->doctor ? [
                'id' => $m->doctor->id,
                'full_name' => $m->doctor->full_name,
            ] : null,
            'visit' => fn(self $m) => $m->visit ? [
                'id' => $m->visit->id,
                'visit_number' => $m->visit->visit_number,
                'visit_date' => $m->visit->visit_date,
            ] : null,
            'items' => fn(self $m) => $m->items,
            'total_amount' => function (self $m): float {
                $sum = 0.0;
                foreach ($m->items as $item) {
                    $sum += (float) $item->unit_price_snapshot * (int) $item->quantity;
                }
                return $sum;
            },
            'created_at',
            'updated_at',
        ];
    }
}
