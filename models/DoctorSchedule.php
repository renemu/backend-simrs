<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * DoctorSchedule model matching table `doctor_schedules` (BR-03).
 *
 * @property int $id
 * @property int $doctor_id
 * @property int $department_id
 * @property int $day_of_week 0=Sunday .. 6=Saturday
 * @property string $start_time
 * @property string $end_time
 * @property int $max_quota
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 *
 * @property-read Doctor|null $doctor
 * @property-read Department|null $department
 */
class DoctorSchedule extends ActiveRecord
{
    public const DAY_LABELS = [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    public static function tableName(): string
    {
        return '{{%doctor_schedules}}';
    }

    public function rules(): array
    {
        return [
            [['doctor_id', 'department_id', 'day_of_week', 'start_time', 'end_time'], 'required'],
            [['doctor_id', 'department_id', 'created_by', 'updated_by'], 'integer'],
            [['day_of_week'], 'integer', 'min' => 0, 'max' => 6],
            [['max_quota'], 'integer', 'min' => 1, 'max' => 500],
            [['max_quota'], 'default', 'value' => 30],
            [['start_time', 'end_time'], 'match', 'pattern' => '/^\d{2}:\d{2}(:\d{2})?$/', 'message' => 'Time must be in HH:MM or HH:MM:SS format.'],
            [['end_time'], 'validateTimeRange'],
            [['start_time'], 'validateNoOverlap'],
            [['is_active'], 'boolean'],
            [['is_active'], 'default', 'value' => true],
            [['created_at', 'updated_at'], 'safe'],
            [
                ['doctor_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => Doctor::class,
                'targetAttribute' => ['doctor_id' => 'id'],
            ],
            [
                ['department_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => Department::class,
                'targetAttribute' => ['department_id' => 'id'],
            ],
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if (is_string($this->start_time) && strlen(trim($this->start_time)) === 5) {
            $this->start_time = trim($this->start_time) . ':00';
        }
        if (is_string($this->end_time) && strlen(trim($this->end_time)) === 5) {
            $this->end_time = trim($this->end_time) . ':00';
        }

        return true;
    }

    public function validateTimeRange(string $attribute): void
    {
        if ($this->hasErrors('start_time') || $this->hasErrors('end_time')) {
            return;
        }

        if (strtotime('1970-01-01 ' . $this->start_time) >= strtotime('1970-01-01 ' . $this->end_time)) {
            $this->addError($attribute, 'End time must be after start time.');
        }
    }

    /**
     * Prevents overlapping active schedules for the same doctor on the same day.
     */
    public function validateNoOverlap(string $attribute): void
    {
        if ($this->hasErrors() || !$this->is_active) {
            return;
        }

        $query = static::find()
            ->where([
                'doctor_id' => $this->doctor_id,
                'day_of_week' => $this->day_of_week,
                'is_active' => true,
            ])
            ->andWhere(['<', 'start_time', $this->end_time])
            ->andWhere(['>', 'end_time', $this->start_time]);

        if ($this->id !== null) {
            $query->andWhere(['<>', 'id', $this->id]);
        }

        if ($query->exists()) {
            $this->addError($attribute, 'Schedule overlaps with another active practice schedule for this doctor on the same day.');
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

    public function getDoctor(): ActiveQuery
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }

    public function getDepartment(): ActiveQuery
    {
        return $this->hasOne(Department::class, ['id' => 'department_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'doctor_id',
            'department_id',
            'day_of_week' => fn(self $m) => (int) $m->day_of_week,
            'day_label' => fn(self $m) => self::DAY_LABELS[(int) $m->day_of_week] ?? (string) $m->day_of_week,
            'start_time' => fn(self $m) => substr((string) $m->start_time, 0, 5),
            'end_time' => fn(self $m) => substr((string) $m->end_time, 0, 5),
            'max_quota' => fn(self $m) => (int) $m->max_quota,
            'is_active' => fn(self $m) => (bool) $m->is_active,
            'doctor' => fn(self $m) => $m->doctor ? [
                'id' => $m->doctor->id,
                'full_name' => $m->doctor->full_name,
                'sip_number' => $m->doctor->sip_number,
                'specialization' => $m->doctor->specialization,
            ] : null,
            'department' => fn(self $m) => $m->department ? [
                'id' => $m->department->id,
                'code' => $m->department->code,
                'name' => $m->department->name,
            ] : null,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
