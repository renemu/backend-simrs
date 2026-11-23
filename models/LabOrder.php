<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * LabOrder model matching table `lab_orders` (FR-06, BR-08, External Modality Integration).
 *
 * @property int $id
 * @property string $order_number
 * @property int $visit_id
 * @property int $patient_id
 * @property int|null $procedure_id
 * @property string $test_name
 * @property string $modality_type
 * @property string|null $body_part
 * @property bool $contrast_used
 * @property float|string $tariff_snapshot
 * @property string $status
 * @property string $integration_status
 * @property string|null $external_system
 * @property string|null $external_reference_id
 * @property string|null $correlation_id
 * @property string|null $dispatched_at
 * @property string|null $last_synced_at
 * @property string|null $integration_error
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

    public const MODALITY_LAB_CLINICAL = 'lab_clinical';
    public const MODALITY_CT_SCAN = 'ct_scan';
    public const MODALITY_XRAY = 'xray';
    public const MODALITY_MRI = 'mri';
    public const MODALITY_USG = 'usg';
    public const MODALITY_EKG = 'ekg';
    public const MODALITY_OTHER = 'other';

    public const ALLOWED_MODALITIES = [
        self::MODALITY_LAB_CLINICAL,
        self::MODALITY_CT_SCAN,
        self::MODALITY_XRAY,
        self::MODALITY_MRI,
        self::MODALITY_USG,
        self::MODALITY_EKG,
        self::MODALITY_OTHER,
    ];

    public const MODALITY_LABELS = [
        self::MODALITY_LAB_CLINICAL => 'Lab Klinis (LIS)',
        self::MODALITY_CT_SCAN => 'CT-Scan',
        self::MODALITY_XRAY => 'X-Ray / Rontgen',
        self::MODALITY_MRI => 'MRI',
        self::MODALITY_USG => 'USG',
        self::MODALITY_EKG => 'EKG',
        self::MODALITY_OTHER => 'Penunjang Lainnya',
    ];

    public const INTEGRATION_LOCAL_ONLY = 'local_only';
    public const INTEGRATION_DISPATCHED = 'dispatched';
    public const INTEGRATION_SYNCED = 'synced';
    public const INTEGRATION_FAILED = 'failed';

    public const ALLOWED_INTEGRATION_STATUSES = [
        self::INTEGRATION_LOCAL_ONLY,
        self::INTEGRATION_DISPATCHED,
        self::INTEGRATION_SYNCED,
        self::INTEGRATION_FAILED,
    ];

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
            [['modality_type'], 'in', 'range' => self::ALLOWED_MODALITIES],
            [['modality_type'], 'default', 'value' => self::MODALITY_LAB_CLINICAL],
            [['body_part'], 'string', 'max' => 120],
            [['contrast_used'], 'boolean'],
            [['contrast_used'], 'default', 'value' => false],
            [['external_system'], 'string', 'max' => 64],
            [['external_reference_id'], 'string', 'max' => 128],
            [['correlation_id'], 'string', 'max' => 64],
            [['tariff_snapshot'], 'number', 'min' => 0],
            [['tariff_snapshot'], 'default', 'value' => 0],
            [['status'], 'in', 'range' => [self::STATUS_REQUESTED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED]],
            [['status'], 'default', 'value' => self::STATUS_REQUESTED],
            [['integration_status'], 'in', 'range' => self::ALLOWED_INTEGRATION_STATUSES],
            [['integration_status'], 'default', 'value' => self::INTEGRATION_LOCAL_ONLY],
            [['notes', 'integration_error'], 'string'],
            [['dispatched_at', 'last_synced_at', 'created_at', 'updated_at'], 'safe'],
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

    /**
     * Infers modality_type from procedure code / test name when not explicitly provided.
     */
    public static function inferModality(?string $code, ?string $testName): string
    {
        $haystack = strtolower(trim(($code ?? '') . ' ' . ($testName ?? '')));
        if ($haystack === '') {
            return self::MODALITY_LAB_CLINICAL;
        }
        if (str_contains($haystack, 'ct-scan') || str_contains($haystack, 'ct scan') || str_contains($haystack, 'rad-ct') || preg_match('/\bct\b/', $haystack)) {
            return self::MODALITY_CT_SCAN;
        }
        if (str_contains($haystack, 'x-ray') || str_contains($haystack, 'xray') || str_contains($haystack, 'rontgen') || str_contains($haystack, 'radiografi')) {
            return self::MODALITY_XRAY;
        }
        if (str_contains($haystack, 'mri')) {
            return self::MODALITY_MRI;
        }
        if (str_contains($haystack, 'usg') || str_contains($haystack, 'ultrasound') || str_contains($haystack, 'ultrasonografi')) {
            return self::MODALITY_USG;
        }
        if (str_contains($haystack, 'ekg') || str_contains($haystack, 'ecg') || str_contains($haystack, 'elektrokardiografi')) {
            return self::MODALITY_EKG;
        }

        return self::MODALITY_LAB_CLINICAL;
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
            'modality_type',
            'modality_label' => fn(self $m) => self::MODALITY_LABELS[$m->modality_type] ?? $m->modality_type,
            'body_part',
            'contrast_used' => fn(self $m) => (bool) $m->contrast_used,
            'tariff_snapshot' => fn(self $m) => (float) $m->tariff_snapshot,
            'status',
            'integration_status',
            'external_system',
            'external_reference_id',
            'correlation_id',
            'dispatched_at',
            'last_synced_at',
            'integration_error',
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
