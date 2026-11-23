<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * LabResult model matching table `lab_results` (FR-06.03, FR-06.04, External Modality Integration).
 *
 * @property int $id
 * @property int $lab_order_id
 * @property string $result_summary
 * @property array|string|null $result_details
 * @property string|null $reference_notes
 * @property string $result_source
 * @property string|null $external_study_uid
 * @property string|null $modality_device
 * @property string|null $external_completed_at
 * @property int|null $recorded_by
 * @property string $recorded_at
 * @property string|null $updated_at
 */
class LabResult extends ActiveRecord
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EXTERNAL_GATEWAY = 'external_gateway';

    public const ALLOWED_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_EXTERNAL_GATEWAY,
    ];

    public static function tableName(): string
    {
        return '{{%lab_results}}';
    }

    public function rules(): array
    {
        return [
            [['lab_order_id', 'result_summary'], 'required'],
            [['lab_order_id', 'recorded_by'], 'integer'],
            [['lab_order_id'], 'unique'],
            [['result_summary', 'reference_notes'], 'string'],
            [['result_source'], 'in', 'range' => self::ALLOWED_SOURCES],
            [['result_source'], 'default', 'value' => self::SOURCE_MANUAL],
            [['external_study_uid', 'modality_device'], 'string', 'max' => 128],
            [['result_details', 'external_completed_at', 'recorded_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        if ($insert && empty($this->recorded_at)) {
            $this->recorded_at = $now;
        }
        $this->updated_at = $now;

        if (is_string($this->result_details) && $this->result_details !== '') {
            $decoded = json_decode($this->result_details, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->result_details = $decoded;
            }
        }
        return true;
    }

    public function getLabOrder(): ActiveQuery
    {
        return $this->hasOne(LabOrder::class, ['id' => 'lab_order_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'lab_order_id',
            'result_summary',
            'result_details' => function (self $m) {
                if (is_array($m->result_details)) {
                    return $m->result_details;
                }
                if (is_string($m->result_details) && $m->result_details !== '') {
                    $decoded = json_decode($m->result_details, true);
                    return is_array($decoded) ? $decoded : null;
                }
                return null;
            },
            'reference_notes',
            'result_source',
            'external_study_uid',
            'modality_device',
            'external_completed_at',
            'recorded_by',
            'recorded_at',
            'updated_at',
        ];
    }
}
