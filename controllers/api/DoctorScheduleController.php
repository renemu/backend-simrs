<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Doctor;
use app\models\DoctorSchedule;
use app\models\User;
use app\models\UserLog;

/**
 * DoctorScheduleController provides RESTful CRUD for `doctor_schedules` (FR-01.05, BR-03).
 */
class DoctorScheduleController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'master-schedules',
            'actions' => [
                'index' => 'read',
                'view' => 'read',
                'create' => 'create',
                'update' => 'update',
                'delete' => 'delete',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/doctor-schedules
     * Query: doctor_id, department_id, day_of_week, is_active, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = DoctorSchedule::find()->with(['doctor', 'department']);

        $doctorId = $request->get('doctor_id');
        if ($doctorId !== null && $doctorId !== '') {
            $query->andWhere(['doctor_id' => (int) $doctorId]);
        }

        $departmentId = $request->get('department_id');
        if ($departmentId !== null && $departmentId !== '') {
            $query->andWhere(['department_id' => (int) $departmentId]);
        }

        $dayOfWeek = $request->get('day_of_week');
        if ($dayOfWeek !== null && $dayOfWeek !== '') {
            $query->andWhere(['day_of_week' => (int) $dayOfWeek]);
        }

        $isActive = $request->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        $total = (int) (clone $query)->count();
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 30)));

        $items = $query
            ->orderBy(['day_of_week' => SORT_ASC, 'start_time' => SORT_ASC, 'id' => SORT_ASC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return [
            'success' => true,
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * GET /api/doctor-schedules/<id>
     */
    public function actionView(int $id): array
    {
        return [
            'success' => true,
            'data' => $this->findModel($id),
        ];
    }

    /**
     * POST /api/doctor-schedules
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $schedule = new DoctorSchedule();
        $this->populate($schedule, $body);
        $schedule->created_by = $actor?->id;
        $schedule->updated_by = $actor?->id;

        if (!$schedule->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $schedule->getErrors(),
            ];
        }

        $schedule->refresh();
        UserLog::record(
            'create',
            'master-schedules',
            sprintf('Created schedule #%d for doctor_id=%d day=%d (%s-%s)', $schedule->id, $schedule->doctor_id, $schedule->day_of_week, $schedule->start_time, $schedule->end_time),
            $actor,
            $schedule->id
        );

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Doctor schedule created successfully.',
            'data' => $schedule,
        ];
    }

    /**
     * PUT/PATCH /api/doctor-schedules/<id>
     */
    public function actionUpdate(int $id): array
    {
        $schedule = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $this->populate($schedule, $body);
        $schedule->updated_by = $actor?->id;

        if (!$schedule->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $schedule->getErrors(),
            ];
        }

        $schedule->refresh();
        UserLog::record(
            'update',
            'master-schedules',
            sprintf('Updated schedule #%d for doctor_id=%d', $schedule->id, $schedule->doctor_id),
            $actor,
            $schedule->id
        );

        return [
            'success' => true,
            'message' => 'Doctor schedule updated successfully.',
            'data' => $schedule,
        ];
    }

    /**
     * DELETE /api/doctor-schedules/<id>
     */
    public function actionDelete(int $id): array
    {
        $schedule = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        if (!$schedule->is_active) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Schedule is already inactive.',
            ];
        }

        $schedule->is_active = false;
        $schedule->updated_by = $actor?->id;
        $schedule->save(false, ['is_active', 'updated_by', 'updated_at']);

        UserLog::record('delete', 'master-schedules', sprintf('Deactivated schedule #%d', $schedule->id), $actor, $schedule->id);

        return [
            'success' => true,
            'message' => 'Doctor schedule deactivated successfully.',
            'data' => $schedule,
        ];
    }

    protected function findModel(int $id): DoctorSchedule
    {
        $model = DoctorSchedule::find()->with(['doctor', 'department'])->where(['id' => $id])->one();
        if (!$model) {
            throw new NotFoundHttpException("Doctor schedule with ID $id not found.");
        }
        return $model;
    }

    private function populate(DoctorSchedule $schedule, array $body): void
    {
        if (array_key_exists('doctor_id', $body)) {
            $schedule->doctor_id = $body['doctor_id'] !== null && $body['doctor_id'] !== '' ? (int) $body['doctor_id'] : null;
        }
        if (array_key_exists('department_id', $body)) {
            $schedule->department_id = $body['department_id'] !== null && $body['department_id'] !== '' ? (int) $body['department_id'] : null;
        } elseif ($schedule->doctor_id && !$schedule->department_id) {
            $doctor = Doctor::findOne($schedule->doctor_id);
            if ($doctor) {
                $schedule->department_id = $doctor->department_id;
            }
        }
        if (array_key_exists('day_of_week', $body)) {
            $schedule->day_of_week = (int) $body['day_of_week'];
        }
        if (array_key_exists('start_time', $body)) {
            $schedule->start_time = (string) $body['start_time'];
        }
        if (array_key_exists('end_time', $body)) {
            $schedule->end_time = (string) $body['end_time'];
        }
        if (array_key_exists('max_quota', $body)) {
            $schedule->max_quota = (int) $body['max_quota'];
        }
        if (array_key_exists('is_active', $body)) {
            $schedule->is_active = (bool) $body['is_active'];
        }
    }
}
