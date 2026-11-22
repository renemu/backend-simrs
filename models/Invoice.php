<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;

/**
 * Invoice model matching table `invoices` (FR-07, BR-09, BR-10, BR-12).
 *
 * @property int $id
 * @property string $invoice_number
 * @property int $visit_id
 * @property int $patient_id
 * @property int $revision_number
 * @property int|null $superseded_by_id
 * @property float|string $total_amount
 * @property float|string $paid_amount
 * @property float|string $balance_amount
 * @property string $payment_status
 * @property string $status
 * @property string|null $notes
 * @property int|null $issued_by
 * @property string $issued_at
 * @property string|null $updated_at
 *
 * @property-read InvoiceItem[] $items
 * @property-read Payment[] $payments
 * @property-read Visit|null $visit
 * @property-read Patient|null $patient
 */
class Invoice extends ActiveRecord
{
    public const PAY_UNPAID = 'unpaid';
    public const PAY_PARTIAL = 'partially_paid';
    public const PAY_PAID = 'paid';

    public const STATUS_ISSUED = 'issued';
    public const STATUS_REVISED = 'revised';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%invoices}}';
    }

    public function rules(): array
    {
        return [
            [['invoice_number', 'visit_id', 'patient_id'], 'required'],
            [['visit_id', 'patient_id', 'revision_number', 'superseded_by_id', 'issued_by'], 'integer'],
            [['invoice_number'], 'string', 'max' => 32],
            [['invoice_number'], 'unique'],
            [['total_amount', 'paid_amount', 'balance_amount'], 'number', 'min' => 0],
            [['payment_status'], 'in', 'range' => [self::PAY_UNPAID, self::PAY_PARTIAL, self::PAY_PAID]],
            [['status'], 'in', 'range' => [self::STATUS_ISSUED, self::STATUS_REVISED, self::STATUS_CANCELLED]],
            [['notes'], 'string'],
            [['issued_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if ($insert && empty($this->issued_at)) {
            $this->issued_at = $now;
        }
        $this->updated_at = $now;
        return true;
    }

    public static function generateNumber(): string
    {
        $prefix = 'INV-' . date('Ymd') . '-';
        $count = (int) static::find()->where(['like', 'invoice_number', $prefix . '%', false])->count() + 1;
        do {
            $candidate = sprintf('%s%04d', $prefix, $count);
            $exists = static::find()->where(['invoice_number' => $candidate])->exists();
            $count++;
        } while ($exists);
        return $candidate;
    }

    /**
     * Collects all billable line items for a visit from authoritative backend records (BR-09).
     *
     * @return array<int, array{item_type: string, reference_id: int|null, description: string, quantity: int, unit_price: float, line_total: float}>
     */
    public static function collectVisitChargeItems(Visit $visit): array
    {
        $items = [];

        $regFee = (float) $visit->registration_fee_snapshot;
        if ($regFee > 0) {
            $deptName = $visit->department !== null ? $visit->department->name : 'Rawat Jalan';
            $items[] = [
                'item_type' => 'registration',
                'reference_id' => $visit->id,
                'description' => sprintf('Biaya Pendaftaran — %s', $deptName),
                'quantity' => 1,
                'unit_price' => $regFee,
                'line_total' => $regFee,
            ];
        }

        $consultFee = (float) $visit->consultation_fee_snapshot;
        if ($consultFee > 0) {
            $doctorName = $visit->doctor !== null ? $visit->doctor->full_name : 'Dokter';
            $items[] = [
                'item_type' => 'consultation',
                'reference_id' => $visit->doctor_id,
                'description' => sprintf('Konsultasi Dokter — %s', $doctorName),
                'quantity' => 1,
                'unit_price' => $consultFee,
                'line_total' => $consultFee,
            ];
        }

        // Active medical procedures
        $procedures = VisitProcedure::find()
            ->where(['visit_id' => $visit->id, 'status' => VisitProcedure::STATUS_ACTIVE])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($procedures as $vp) {
            $qty = (int) $vp->quantity;
            $unit = (float) $vp->applied_tariff;
            $items[] = [
                'item_type' => 'procedure',
                'reference_id' => $vp->id,
                'description' => sprintf('Tindakan: %s', $vp->procedure_name_snapshot),
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => $unit * $qty,
            ];
        }

        // Non-cancelled lab orders
        $labOrders = LabOrder::find()
            ->where(['visit_id' => $visit->id])
            ->andWhere(['<>', 'status', LabOrder::STATUS_CANCELLED])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($labOrders as $lo) {
            $tariff = (float) $lo->tariff_snapshot;
            if ($tariff > 0) {
                $items[] = [
                    'item_type' => 'laboratory',
                    'reference_id' => $lo->id,
                    'description' => sprintf('Laboratorium: %s (%s)', $lo->test_name, $lo->order_number),
                    'quantity' => 1,
                    'unit_price' => $tariff,
                    'line_total' => $tariff,
                ];
            }
        }

        // Dispensed prescriptions
        $prescriptions = Prescription::find()
            ->with('items')
            ->where(['visit_id' => $visit->id, 'status' => Prescription::STATUS_DISPENSED])
            ->orderBy(['id' => SORT_ASC])
            ->all();
        foreach ($prescriptions as $rx) {
            foreach ($rx->items as $rxItem) {
                $qty = (int) $rxItem->quantity;
                $unit = (float) $rxItem->unit_price_snapshot;
                $items[] = [
                    'item_type' => 'medicine',
                    'reference_id' => $rxItem->id,
                    'description' => sprintf('Obat: %s (%s %s)', $rxItem->medicine_name_snapshot, $qty, $rxItem->unit_snapshot),
                    'quantity' => $qty,
                    'unit_price' => $unit,
                    'line_total' => $unit * $qty,
                ];
            }
        }

        return $items;
    }

    /**
     * Generates a consolidated invoice for a visit (FR-07.01, FR-07.02).
     *
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    public static function generateForVisitAtomic(int $visitId, ?string $notes, ?User $actor): self
    {
        $visit = Visit::find()->with(['patient', 'department', 'doctor'])->where(['id' => $visitId])->one();
        if (!$visit) {
            throw new NotFoundHttpException("Visit with ID $visitId not found.");
        }
        if ($visit->status === Visit::STATUS_CANCELLED) {
            throw new ConflictHttpException('Cannot generate an invoice for a cancelled visit.');
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();

        try {
            $existing = static::findOne(['visit_id' => $visit->id, 'status' => self::STATUS_ISSUED]);
            if ($existing !== null) {
                throw new ConflictHttpException(
                    sprintf('Visit %s already has an active invoice (%s). Use revise endpoint if clinical items changed.', $visit->visit_number, $existing->invoice_number)
                );
            }

            $chargeItems = self::collectVisitChargeItems($visit);
            $total = 0.0;
            foreach ($chargeItems as $ci) {
                $total += $ci['line_total'];
            }

            $invoice = new self();
            $invoice->invoice_number = self::generateNumber();
            $invoice->visit_id = $visit->id;
            $invoice->patient_id = $visit->patient_id;
            $invoice->revision_number = 1;
            $invoice->total_amount = $total;
            $invoice->paid_amount = 0.0;
            $invoice->balance_amount = $total;
            $invoice->payment_status = $total <= 0 ? self::PAY_PAID : self::PAY_UNPAID;
            $invoice->status = self::STATUS_ISSUED;
            $invoice->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $invoice->issued_by = $actor?->id;
            $invoice->save(false);

            foreach ($chargeItems as $ci) {
                $item = new InvoiceItem();
                $item->invoice_id = $invoice->id;
                $item->item_type = $ci['item_type'];
                $item->reference_id = $ci['reference_id'];
                $item->description = $ci['description'];
                $item->quantity = $ci['quantity'];
                $item->unit_price = $ci['unit_price'];
                $item->line_total = $ci['line_total'];
                $item->save(false);
            }

            $tx->commit();

            return static::find()
                ->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient'])
                ->where(['id' => $invoice->id])
                ->one();
        } catch (\Throwable $e) {
            if ($tx->isActive) {
                $tx->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Explicitly revises an unpaid/partially-paid invoice when procedures/medicines change after billing (BR-12).
     * Never silently mutates historical invoice lines: marks old invoice `revised` and creates revision_number + 1.
     *
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     */
    public static function reviseAtomic(int $invoiceId, ?string $reason, ?User $actor): self
    {
        $db = Yii::$app->db;
        $tx = $db->beginTransaction();

        try {
            $oldRow = $db->createCommand(
                'SELECT id, visit_id, patient_id, invoice_number, revision_number, status, payment_status, paid_amount FROM {{%invoices}} WHERE id = :id FOR UPDATE',
                [':id' => $invoiceId]
            )->queryOne();

            if (!$oldRow) {
                throw new NotFoundHttpException("Invoice with ID $invoiceId not found.");
            }
            if ($oldRow['status'] !== self::STATUS_ISSUED) {
                throw new ConflictHttpException(sprintf('Invoice %s cannot be revised because its status is "%s".', $oldRow['invoice_number'], $oldRow['status']));
            }
            if ($oldRow['payment_status'] === self::PAY_PAID) {
                throw new ConflictHttpException(sprintf('Invoice %s is already fully paid and locked from silent revision.', $oldRow['invoice_number']));
            }

            $visit = Visit::find()->with(['patient', 'department', 'doctor'])->where(['id' => (int) $oldRow['visit_id']])->one();
            $chargeItems = self::collectVisitChargeItems($visit);
            $newTotal = 0.0;
            foreach ($chargeItems as $ci) {
                $newTotal += $ci['line_total'];
            }

            $existingPaid = (float) $oldRow['paid_amount'];
            if ($newTotal < $existingPaid) {
                throw new ConflictHttpException(
                    sprintf('Revised total (%.2f) is lower than already paid amount (%.2f). Requires manual refund/adjustment.', $newTotal, $existingPaid)
                );
            }

            $now = date('Y-m-d H:i:s');
            // 1. Mark old invoice as revised first to release partial unique index on visit_id
            $db->createCommand()->update(
                '{{%invoices}}',
                ['status' => self::STATUS_REVISED, 'updated_at' => $now],
                ['id' => (int) $oldRow['id']]
            )->execute();

            // 2. Create revised invoice
            $newBalance = max(0.0, $newTotal - $existingPaid);
            $newPayStatus = $newBalance <= 0
                ? self::PAY_PAID
                : ($existingPaid > 0 ? self::PAY_PARTIAL : self::PAY_UNPAID);

            $newInvoice = new self();
            $newInvoice->invoice_number = self::generateNumber();
            $newInvoice->visit_id = (int) $oldRow['visit_id'];
            $newInvoice->patient_id = (int) $oldRow['patient_id'];
            $newInvoice->revision_number = (int) $oldRow['revision_number'] + 1;
            $newInvoice->total_amount = $newTotal;
            $newInvoice->paid_amount = $existingPaid;
            $newInvoice->balance_amount = $newBalance;
            $newInvoice->payment_status = $newPayStatus;
            $newInvoice->status = self::STATUS_ISSUED;
            $newInvoice->notes = trim(sprintf('Revisi dari %s. %s', $oldRow['invoice_number'], (string) $reason));
            $newInvoice->issued_by = $actor?->id;
            $newInvoice->save(false);

            foreach ($chargeItems as $ci) {
                $item = new InvoiceItem();
                $item->invoice_id = $newInvoice->id;
                $item->item_type = $ci['item_type'];
                $item->reference_id = $ci['reference_id'];
                $item->description = $ci['description'];
                $item->quantity = $ci['quantity'];
                $item->unit_price = $ci['unit_price'];
                $item->line_total = $ci['line_total'];
                $item->save(false);
            }

            // 3. Re-link previous partial payments to active revised invoice & record superseded_by_id
            $db->createCommand()->update(
                '{{%payments}}',
                ['invoice_id' => $newInvoice->id],
                ['invoice_id' => (int) $oldRow['id']]
            )->execute();

            $db->createCommand()->update(
                '{{%invoices}}',
                ['superseded_by_id' => $newInvoice->id, 'updated_at' => $now],
                ['id' => (int) $oldRow['id']]
            )->execute();

            $tx->commit();

            return static::find()
                ->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient'])
                ->where(['id' => $newInvoice->id])
                ->one();
        } catch (\Throwable $e) {
            if ($tx->isActive) {
                $tx->rollBack();
            }
            throw $e;
        }
    }

    public function getItems(): ActiveQuery
    {
        return $this->hasMany(InvoiceItem::class, ['invoice_id' => 'id'])->orderBy(['id' => SORT_ASC]);
    }

    public function getPayments(): ActiveQuery
    {
        return $this->hasMany(Payment::class, ['invoice_id' => 'id'])->orderBy(['paid_at' => SORT_ASC, 'id' => SORT_ASC]);
    }

    public function getVisit(): ActiveQuery
    {
        return $this->hasOne(Visit::class, ['id' => 'visit_id']);
    }

    public function getPatient(): ActiveQuery
    {
        return $this->hasOne(Patient::class, ['id' => 'patient_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'invoice_number',
            'visit_id',
            'patient_id',
            'revision_number' => fn(self $m) => (int) $m->revision_number,
            'superseded_by_id',
            'total_amount' => fn(self $m) => (float) $m->total_amount,
            'paid_amount' => fn(self $m) => (float) $m->paid_amount,
            'balance_amount' => fn(self $m) => (float) $m->balance_amount,
            'payment_status',
            'status',
            'notes',
            'issued_by',
            'issued_at',
            'updated_at',
            'patient' => fn(self $m) => $m->patient ? [
                'id' => $m->patient->id,
                'medical_record_number' => $m->patient->medical_record_number,
                'full_name' => $m->patient->full_name,
                'phone' => $m->patient->phone,
                'address' => $m->patient->address,
            ] : null,
            'visit' => fn(self $m) => $m->visit ? [
                'id' => $m->visit->id,
                'visit_number' => $m->visit->visit_number,
                'visit_date' => $m->visit->visit_date,
                'department_name' => $m->visit->department?->name,
                'doctor_name' => $m->visit->doctor?->full_name,
            ] : null,
            'items' => fn(self $m) => $m->items,
            'payments' => fn(self $m) => $m->payments,
        ];
    }
}
