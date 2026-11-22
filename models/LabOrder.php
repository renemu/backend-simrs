<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * LabOrder model matching table `lab_orders` (FR-06, BR-08).
 *
 * @property int $id
 * @property string $order_number
 * @property int $visit_id
 * @property int $patient_id
 * @property int|null $procedure_id
 * @property string $test_name
 * @property float|string $tariff_snapshot
 * @property string $status
 * @property string $integration_status
 * @property string|null $correlation_id
 * @property string|null $notes
 * @property int|null $requested_by
 * @property string $created_at
 * @property string|null $updated_at
 *
 * @property-read LabResult|null $result
 * @property-read Patient|null $patient
 * @property-read Visit|null $visit
 */
class LabOrder extends ActiveRecord
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%lab_orders}}';
    }

    public function rules(): array
    {
        return [
            [['order_number', 'visit_id', 'patient_id', 'test_name'], 'required'],
            [['visit_id', 'patient_id', 'procedure_id', 'requested_by'], 'integer'],
            [['order_number'], 'string', 'max' => 32],
            [['order_number'], 'unique'],
            [['test_name'], 'string', 'max' => 191],
            [['correlation_id'], 'string', 'max' => 64],
            [['tariff_snapshot'], 'number', 'min' => 0],
            [['tariff_snapshot'], 'default', 'value' => 0],
            [['status'], 'in', 'range' => [self::STATUS_REQUESTED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED]],
            [['status'], 'default', 'value' => self::STATUS_REQUESTED],
            [['integration_status'], 'in', 'range' => ['local_only', 'synced', 'failed']],
            [['integration_status'], 'default', 'value' => 'local_only'],
            [['notes'], 'string'],
            [['created_at', 'updated_at'], 'safe'],
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

    public static function generateOrderNumber(): string
    {
        $prefix = 'LAB-' . date('Ymd') . '-';
        $count = (int) static::find()->where(['like', 'order_number', $prefix . '%', false])->count() + 1;
        do {
            $candidate = sprintf('%s%04d', $prefix, $count);
            $exists = static::find()->where(['order_number' => $candidate])->exists();
            $count++;
        } while ($exists);
        return $candidate;
    }

    public function getResult(): ActiveQuery
    {
        return $this->hasOne(LabResult::class, ['lab_order_id' => 'id']);
    }

    public function getPatient(): ActiveQuery
    {
        return $this->hasOne(Patient::class, ['id' => 'patient_id']);
    }

    public function getVisit(): ActiveQuery
    {
        return $this->hasOne(Visit::class, ['id' => 'visit_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'order_number',
            'visit_id',
            'patient_id',
            'procedure_id',
            'test_name',
            'tariff_snapshot' => fn(self $m) => (float) $m->tariff_snapshot,
            'status',
            'integration_status',
            'correlation_id',
            'notes',
            'patient' => fn(self $m) => $m->patient ? [
                'id' => $m->patient->id,
                'medical_record_number' => $m->patient->medical_record_number,
                'full_name' => $m->patient->full_name,
            ] : null,
            'visit' => fn(self $m) => $m->visit ? [
                'id' => $m->visit->id,
                'visit_number' => $m->visit->visit_number,
                'visit_date' => $m->visit->visit_date,
            ] : null,
            'result' => fn(self $m) => $m->result,
            'created_at',
            'updated_at',
        ];
    }
}
