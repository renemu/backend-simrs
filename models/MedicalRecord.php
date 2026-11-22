<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * MedicalRecord model matching table `medical_records` (FR-03, BR-05).
 *
 * @property int $id
 * @property int $visit_id
 * @property int $patient_id
 * @property int $doctor_id
 * @property string $chief_complaint
 * @property string|null $anamnesis
 * @property array|string|null $vital_signs
 * @property string|null $physical_exam
 * @property string $primary_diagnosis
 * @property string|null $secondary_diagnoses
 * @property string|null $clinical_notes
 * @property int $version
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 */
class MedicalRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%medical_records}}';
    }

    public function rules(): array
    {
        return [
            [['visit_id', 'patient_id', 'doctor_id', 'chief_complaint', 'primary_diagnosis'], 'required'],
            [['visit_id', 'patient_id', 'doctor_id', 'version', 'created_by', 'updated_by'], 'integer'],
            [['visit_id'], 'unique'],
            [['chief_complaint', 'anamnesis', 'physical_exam', 'secondary_diagnoses', 'clinical_notes'], 'string'],
            [['primary_diagnosis'], 'string', 'max' => 255],
            [['vital_signs', 'created_at', 'updated_at'], 'safe'],
            [['version'], 'default', 'value' => 1],
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

        if (is_string($this->vital_signs) && $this->vital_signs !== '') {
            $decoded = json_decode($this->vital_signs, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->vital_signs = $decoded;
            }
        }

        return true;
    }

    public function getVisit(): ActiveQuery
    {
        return $this->hasOne(Visit::class, ['id' => 'visit_id']);
    }

    public function getDoctor(): ActiveQuery
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'visit_id',
            'patient_id',
            'doctor_id',
            'chief_complaint',
            'anamnesis',
            'vital_signs' => function (self $m) {
                if (is_array($m->vital_signs)) {
                    return $m->vital_signs;
                }
                if (is_string($m->vital_signs) && $m->vital_signs !== '') {
                    $decoded = json_decode($m->vital_signs, true);
                    return is_array($decoded) ? $decoded : null;
                }
                return null;
            },
            'physical_exam',
            'primary_diagnosis',
            'secondary_diagnoses',
            'clinical_notes',
            'version' => fn(self $m) => (int) $m->version,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
