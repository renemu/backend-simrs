<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Doctor model matching table `doctors`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $department_id
 * @property string $sip_number
 * @property string $full_name
 * @property string|null $specialization
 * @property string|null $phone
 * @property float|string $consultation_fee
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 *
 * @property-read Department|null $department
 * @property-read User|null $user
 * @property-read DoctorSchedule[] $schedules
 */
class Doctor extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%doctors}}';
    }

    public function rules(): array
    {
        return [
            [['department_id', 'sip_number', 'full_name'], 'required'],
            [['user_id', 'department_id', 'created_by', 'updated_by'], 'integer'],
            [['sip_number'], 'string', 'max' => 64],
            [['sip_number'], 'unique'],
            [['user_id'], 'unique', 'skipOnEmpty' => true],
            [['full_name', 'specialization'], 'string', 'max' => 191],
            [['phone'], 'string', 'max' => 32],
            [['consultation_fee'], 'number', 'min' => 0],
            [['consultation_fee'], 'default', 'value' => 0],
            [['is_active'], 'boolean'],
            [['is_active'], 'default', 'value' => true],
            [['created_at', 'updated_at'], 'safe'],
            [
                ['department_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => Department::class,
                'targetAttribute' => ['department_id' => 'id'],
            ],
            [
                ['user_id'],
                'exist',
                'skipOnError' => true,
                'skipOnEmpty' => true,
                'targetClass' => User::class,
                'targetAttribute' => ['user_id' => 'id'],
            ],
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if (is_string($this->sip_number)) {
            $this->sip_number = strtoupper(trim($this->sip_number));
        }
        if (is_string($this->full_name)) {
            $this->full_name = trim($this->full_name);
        }
        if (is_string($this->specialization)) {
            $trimmed = trim($this->specialization);
            $this->specialization = $trimmed !== '' ? $trimmed : null;
        }

        return true;
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

    public function getDepartment(): ActiveQuery
    {
        return $this->hasOne(Department::class, ['id' => 'department_id']);
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getSchedules(): ActiveQuery
    {
        return $this->hasMany(DoctorSchedule::class, ['doctor_id' => 'id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'user_id',
            'department_id',
            'sip_number',
            'full_name',
            'specialization',
            'phone',
            'consultation_fee' => fn(self $m) => (float) $m->consultation_fee,
            'is_active' => fn(self $m) => (bool) $m->is_active,
            'department' => fn(self $m) => $m->department ? [
                'id' => $m->department->id,
                'code' => $m->department->code,
                'name' => $m->department->name,
                'registration_fee' => (float) $m->department->registration_fee,
            ] : null,
            'user' => fn(self $m) => $m->user ? [
                'id' => $m->user->id,
                'email' => $m->user->email,
                'name' => $m->user->name,
            ] : null,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
