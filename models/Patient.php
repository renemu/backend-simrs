<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Patient model matching table `patients`.
 *
 * @property int $id
 * @property string $medical_record_number
 * @property string|null $national_id
 * @property string $full_name
 * @property string $birth_date
 * @property string $gender
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $blood_type
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 */
class Patient extends ActiveRecord
{
    public const GENDER_MALE = 'male';
    public const GENDER_FEMALE = 'female';

    public static function tableName(): string
    {
        return '{{%patients}}';
    }

    public function rules(): array
    {
        return [
            [['full_name', 'birth_date', 'gender'], 'required'],
            [['medical_record_number', 'national_id', 'phone'], 'string', 'max' => 32],
            [['full_name'], 'string', 'max' => 191],
            [['address'], 'string'],
            [['blood_type'], 'string', 'max' => 5],
            [['blood_type'], 'in', 'range' => ['A', 'B', 'AB', 'O', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], 'skipOnEmpty' => true],
            [['gender'], 'in', 'range' => [self::GENDER_MALE, self::GENDER_FEMALE]],
            [['birth_date'], 'date', 'format' => 'php:Y-m-d'],
            [['birth_date'], 'validateBirthDateNotFuture'],
            [['medical_record_number'], 'unique'],
            [
                ['national_id'],
                'unique',
                'skipOnEmpty' => true,
                'message' => 'National ID (NIK) is already registered to another patient.',
            ],
            [['is_active'], 'boolean'],
            [['is_active'], 'default', 'value' => true],
            [['created_by', 'updated_by'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if (is_string($this->full_name)) {
            $this->full_name = trim($this->full_name);
        }
        if (is_string($this->national_id)) {
            $trimmed = trim($this->national_id);
            $this->national_id = $trimmed !== '' ? $trimmed : null;
        }
        if (is_string($this->phone)) {
            $trimmed = trim($this->phone);
            $this->phone = $trimmed !== '' ? $trimmed : null;
        }
        if (is_string($this->blood_type)) {
            $trimmed = strtoupper(trim($this->blood_type));
            $this->blood_type = $trimmed !== '' ? $trimmed : null;
        }
        if (is_string($this->medical_record_number)) {
            $this->medical_record_number = strtoupper(trim($this->medical_record_number));
        }

        if ($this->isNewRecord && empty($this->medical_record_number)) {
            $this->medical_record_number = self::generateMedicalRecordNumber();
        }

        return true;
    }

    public function validateBirthDateNotFuture(string $attribute): void
    {
        if (!$this->hasErrors($attribute) && !empty($this->$attribute)) {
            if (strtotime((string) $this->$attribute) > time()) {
                $this->addError($attribute, 'Birth date cannot be in the future.');
            }
        }
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
     * Generates a unique medical record number with format RM-YYYYMM-XXXX.
     */
    public static function generateMedicalRecordNumber(): string
    {
        $prefix = 'RM-' . date('Ym') . '-';
        $latest = static::find()
            ->select('medical_record_number')
            ->where(['like', 'medical_record_number', $prefix . '%', false])
            ->orderBy(['medical_record_number' => SORT_DESC])
            ->scalar();

        $nextSeq = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $matches)) {
            $nextSeq = (int) $matches[1] + 1;
        }

        do {
            $candidate = sprintf('%s%04d', $prefix, $nextSeq);
            $exists = static::find()->where(['medical_record_number' => $candidate])->exists();
            $nextSeq++;
        } while ($exists);

        return $candidate;
    }

    public function fields(): array
    {
        return [
            'id',
            'medical_record_number',
            'national_id',
            'full_name',
            'birth_date',
            'gender',
            'phone',
            'address',
            'blood_type',
            'is_active' => fn(self $m) => (bool) $m->is_active,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
