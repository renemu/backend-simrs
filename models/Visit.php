<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\IntegrityException;
use yii\web\ConflictHttpException;
use yii\web\UnprocessableEntityHttpException;

/**
 * Visit model matching table `visits` (FR-02, BR-02, BR-03, BR-04).
 *
 * @property int $id
 * @property string $visit_number
 * @property int $patient_id
 * @property int $department_id
 * @property int $doctor_id
 * @property int $schedule_id
 * @property string $visit_date
 * @property int $queue_number
 * @property string $queue_status
 * @property string $status
 * @property float|string $registration_fee_snapshot
 * @property float|string $consultation_fee_snapshot
 * @property string|null $notes
 * @property int|null $registered_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 *
 * @property-read Patient|null $patient
 * @property-read Department|null $department
 * @property-read Doctor|null $doctor
 * @property-read DoctorSchedule|null $schedule
 * @property-read MedicalRecord|null $medicalRecord
 * @property-read VisitProcedure[] $visitProcedures
 * @property-read Prescription[] $prescriptions
 * @property-read LabOrder[] $labOrders
 */
class Visit extends ActiveRecord
{
    public const QUEUE_WAITING = 'waiting';
    public const QUEUE_CALLED = 'called';
    public const QUEUE_IN_EXAMINATION = 'in_examination';
    public const QUEUE_COMPLETED = 'completed';
    public const QUEUE_CANCELLED = 'cancelled';

    public const STATUS_REGISTERED = 'registered';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%visits}}';
    }

    public function rules(): array
    {
        return [
            [['visit_number', 'patient_id', 'department_id', 'doctor_id', 'schedule_id', 'visit_date', 'queue_number'], 'required'],
            [['patient_id', 'department_id', 'doctor_id', 'schedule_id', 'queue_number', 'registered_by', 'created_by', 'updated_by'], 'integer'],
            [['queue_number'], 'integer', 'min' => 1],
            [['visit_number'], 'string', 'max' => 32],
            [['visit_number'], 'unique'],
            [['visit_date'], 'date', 'format' => 'php:Y-m-d'],
            [['queue_status'], 'in', 'range' => [self::QUEUE_WAITING, self::QUEUE_CALLED, self::QUEUE_IN_EXAMINATION, self::QUEUE_COMPLETED, self::QUEUE_CANCELLED]],
            [['status'], 'in', 'range' => [self::STATUS_REGISTERED, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_CANCELLED]],
            [['registration_fee_snapshot', 'consultation_fee_snapshot'], 'number', 'min' => 0],
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

    /**
     * Atomically validates schedule + quota, prevents duplicate visit (DB unique index),
     * and allocates the next sequential queue_number using a PostgreSQL transaction advisory lock.
     *
     * @throws UnprocessableEntityHttpException
     * @throws ConflictHttpException
     */
    public static function registerAtomic(
        int $patientId,
        int $departmentId,
        int $doctorId,
        int $scheduleId,
        string $visitDate,
        ?string $notes,
        ?User $actor
    ): self {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
            throw new UnprocessableEntityHttpException('Invalid visit_date format. Expected YYYY-MM-DD.');
        }

        $patient = Patient::findOne(['id' => $patientId, 'is_active' => true]);
        if (!$patient) {
            throw new UnprocessableEntityHttpException('Active patient not found.');
        }

        $department = Department::findOne(['id' => $departmentId, 'is_active' => true]);
        if (!$department) {
            throw new UnprocessableEntityHttpException('Active department not found.');
        }

        $doctor = Doctor::findOne(['id' => $doctorId, 'is_active' => true]);
        if (!$doctor) {
            throw new UnprocessableEntityHttpException('Active doctor not found.');
        }

        $schedule = DoctorSchedule::findOne([
            'id' => $scheduleId,
            'doctor_id' => $doctorId,
            'department_id' => $departmentId,
            'is_active' => true,
        ]);
        if (!$schedule) {
            throw new UnprocessableEntityHttpException('Selected practice schedule is invalid or inactive for this doctor and department.');
        }

        // BR-03: Validate schedule day_of_week matches visit_date
        $dayOfWeek = (int) date('w', strtotime($visitDate));
        if ((int) $schedule->day_of_week !== $dayOfWeek) {
            $expectedDay = DoctorSchedule::DAY_LABELS[(int) $schedule->day_of_week] ?? (string) $schedule->day_of_week;
            $actualDay = DoctorSchedule::DAY_LABELS[$dayOfWeek];
            throw new UnprocessableEntityHttpException(
                sprintf('Selected schedule is for %s, but visit date %s falls on %s.', $expectedDay, $visitDate, $actualDay)
            );
        }

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            // Serialize queue allocation per (department_id, visit_date) within the transaction
            $lockKey = crc32(sprintf('simrs_queue:%d:%s', $departmentId, $visitDate));
            $db->createCommand('SELECT pg_advisory_xact_lock(:key)', [':key' => $lockKey])->execute();

            // Check quota for this schedule on this date
            $bookedCount = (int) static::find()
                ->where([
                    'schedule_id' => $scheduleId,
                    'visit_date' => $visitDate,
                ])
                ->andWhere(['<>', 'status', self::STATUS_CANCELLED])
                ->count();

            if ($bookedCount >= (int) $schedule->max_quota) {
                throw new ConflictHttpException(
                    sprintf('Schedule quota (%d) for this doctor on %s is full.', (int) $schedule->max_quota, $visitDate)
                );
            }

            // Compute next atomic queue number per department & date
            $maxQueue = (int) $db->createCommand(
                'SELECT COALESCE(MAX(queue_number), 0) FROM {{%visits}} WHERE department_id = :dept AND visit_date = :vdate',
                [':dept' => $departmentId, ':vdate' => $visitDate]
            )->queryScalar();
            $nextQueue = $maxQueue + 1;

            // Generate visit number VIS-YYYYMMDD-XXXX
            $dateCompact = str_replace('-', '', $visitDate);
            $visitCountDate = (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%visits}} WHERE visit_date = :vdate',
                [':vdate' => $visitDate]
            )->queryScalar();
            $visitNumber = sprintf('VIS-%s-%04d', $dateCompact, $visitCountDate + 1);
            while (static::find()->where(['visit_number' => $visitNumber])->exists()) {
                $visitCountDate++;
                $visitNumber = sprintf('VIS-%s-%04d', $dateCompact, $visitCountDate + 1);
            }

            $visit = new self();
            $visit->visit_number = $visitNumber;
            $visit->patient_id = $patientId;
            $visit->department_id = $departmentId;
            $visit->doctor_id = $doctorId;
            $visit->schedule_id = $scheduleId;
            $visit->visit_date = $visitDate;
            $visit->queue_number = $nextQueue;
            $visit->queue_status = self::QUEUE_WAITING;
            $visit->status = self::STATUS_REGISTERED;
            $visit->registration_fee_snapshot = (float) $department->registration_fee;
            $visit->consultation_fee_snapshot = (float) $doctor->consultation_fee;
            $visit->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $visit->registered_by = $actor?->id;
            $visit->created_by = $actor?->id;
            $visit->updated_by = $actor?->id;

            if (!$visit->save()) {
                $firstErrors = $visit->getFirstErrors();
                throw new UnprocessableEntityHttpException(reset($firstErrors) ?: 'Failed to register visit.');
            }

            $transaction->commit();
            $visit->refresh();
            return $visit;
        } catch (IntegrityException $e) {
            $transaction->rollBack();
            // PostgreSQL SQLSTATE 23505 = unique_violation (duplicate registration or queue)
            if (str_contains($e->getMessage(), 'uq_visits_active_patient_dept_date')) {
                throw new ConflictHttpException(
                    'Duplicate registration blocked: patient already has an active visit in this department on the selected date.'
                );
            }
            if (str_contains($e->getMessage(), '23505')) {
                throw new ConflictHttpException('Concurrent registration conflict detected. Please retry.');
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }

    public function getPatient(): ActiveQuery
    {
        return $this->hasOne(Patient::class, ['id' => 'patient_id']);
    }

    public function getDepartment(): ActiveQuery
    {
        return $this->hasOne(Department::class, ['id' => 'department_id']);
    }

    public function getDoctor(): ActiveQuery
    {
        return $this->hasOne(Doctor::class, ['id' => 'doctor_id']);
    }

    public function getSchedule(): ActiveQuery
    {
        return $this->hasOne(DoctorSchedule::class, ['id' => 'schedule_id']);
    }

    public function getMedicalRecord(): ActiveQuery
    {
        return $this->hasOne(MedicalRecord::class, ['visit_id' => 'id']);
    }

    public function getVisitProcedures(): ActiveQuery
    {
        return $this->hasMany(VisitProcedure::class, ['visit_id' => 'id'])->orderBy(['id' => SORT_ASC]);
    }

    public function getPrescriptions(): ActiveQuery
    {
        return $this->hasMany(Prescription::class, ['visit_id' => 'id'])->orderBy(['id' => SORT_DESC]);
    }

    public function getLabOrders(): ActiveQuery
    {
        return $this->hasMany(LabOrder::class, ['visit_id' => 'id'])->orderBy(['id' => SORT_DESC]);
    }

    public function fields(): array
    {
        return [
            'id',
            'visit_number',
            'patient_id',
            'department_id',
            'doctor_id',
            'schedule_id',
            'visit_date',
            'queue_number' => fn(self $m) => (int) $m->queue_number,
            'queue_code' => fn(self $m) => sprintf('%s-%03d', $m->department !== null ? $m->department->code : 'Q', (int) $m->queue_number),
            'queue_status',
            'status',
            'registration_fee_snapshot' => fn(self $m) => (float) $m->registration_fee_snapshot,
            'consultation_fee_snapshot' => fn(self $m) => (float) $m->consultation_fee_snapshot,
            'notes',
            'patient' => fn(self $m) => $m->patient ? [
                'id' => $m->patient->id,
                'medical_record_number' => $m->patient->medical_record_number,
                'full_name' => $m->patient->full_name,
                'gender' => $m->patient->gender,
                'birth_date' => $m->patient->birth_date,
                'phone' => $m->patient->phone,
            ] : null,
            'department' => fn(self $m) => $m->department ? [
                'id' => $m->department->id,
                'code' => $m->department->code,
                'name' => $m->department->name,
            ] : null,
            'doctor' => fn(self $m) => $m->doctor ? [
                'id' => $m->doctor->id,
                'full_name' => $m->doctor->full_name,
                'specialization' => $m->doctor->specialization,
            ] : null,
            'schedule' => fn(self $m) => $m->schedule ? [
                'id' => $m->schedule->id,
                'day_of_week' => (int) $m->schedule->day_of_week,
                'day_label' => DoctorSchedule::DAY_LABELS[(int) $m->schedule->day_of_week] ?? '',
                'start_time' => substr((string) $m->schedule->start_time, 0, 5),
                'end_time' => substr((string) $m->schedule->end_time, 0, 5),
            ] : null,
            'created_at',
            'updated_at',
        ];
    }
}
