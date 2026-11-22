<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Doctor;
use app\models\User;
use app\models\UserLog;

/**
 * DoctorController provides RESTful CRUD for `doctors` master data (FR-01.03).
 */
class DoctorController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'master-doctors',
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
     * GET /api/doctors
     * Query: search, department_id, is_active, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Doctor::find()->with(['department', 'user']);

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'full_name', $search],
                ['ilike', 'sip_number', $search],
                ['ilike', 'specialization', $search],
            ]);
        }

        $departmentId = $request->get('department_id');
        if ($departmentId !== null && $departmentId !== '') {
            $query->andWhere(['department_id' => (int) $departmentId]);
        }

        $isActive = $request->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        return $this->paginateQuery($query, ['id' => SORT_ASC]);
    }

    /**
     * GET /api/doctors/<id>
     */
    public function actionView(int $id): array
    {
        return [
            'success' => true,
            'data' => $this->findModel($id),
        ];
    }

    /**
     * POST /api/doctors
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $doctor = new Doctor();
        $this->populate($doctor, $body);
        $doctor->created_by = $actor?->id;
        $doctor->updated_by = $actor?->id;

        if (!$doctor->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $doctor->getErrors(),
            ];
        }

        $doctor->refresh();
        UserLog::record('create', 'master-doctors', sprintf('Created doctor %s (%s)', $doctor->full_name, $doctor->sip_number), $actor, $doctor->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Doctor created successfully.',
            'data' => $doctor,
        ];
    }

    /**
     * PUT/PATCH /api/doctors/<id>
     */
    public function actionUpdate(int $id): array
    {
        $doctor = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $this->populate($doctor, $body);
        $doctor->updated_by = $actor?->id;

        if (!$doctor->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $doctor->getErrors(),
            ];
        }

        $doctor->refresh();
        UserLog::record('update', 'master-doctors', sprintf('Updated doctor %s (%s)', $doctor->full_name, $doctor->sip_number), $actor, $doctor->id);

        return [
            'success' => true,
            'message' => 'Doctor updated successfully.',
            'data' => $doctor,
        ];
    }

    /**
     * DELETE /api/doctors/<id>
     */
    public function actionDelete(int $id): array
    {
        $doctor = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        if (!$doctor->is_active) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Doctor is already inactive.',
            ];
        }

        $doctor->is_active = false;
        $doctor->updated_by = $actor?->id;
        $doctor->save(false, ['is_active', 'updated_by', 'updated_at']);

        UserLog::record('delete', 'master-doctors', sprintf('Deactivated doctor %s (%s)', $doctor->full_name, $doctor->sip_number), $actor, $doctor->id);

        return [
            'success' => true,
            'message' => 'Doctor deactivated successfully.',
            'data' => $doctor,
        ];
    }

    protected function findModel(int $id): Doctor
    {
        $model = Doctor::find()->with(['department', 'user'])->where(['id' => $id])->one();
        if (!$model) {
            throw new NotFoundHttpException("Doctor with ID $id not found.");
        }
        return $model;
    }

    private function populate(Doctor $doctor, array $body): void
    {
        foreach (['sip_number', 'full_name', 'specialization', 'phone'] as $field) {
            if (array_key_exists($field, $body)) {
                $doctor->$field = $body[$field];
            }
        }
        if (array_key_exists('department_id', $body)) {
            $doctor->department_id = $body['department_id'] !== null && $body['department_id'] !== '' ? (int) $body['department_id'] : null;
        }
        if (array_key_exists('user_id', $body)) {
            $doctor->user_id = $body['user_id'] !== null && $body['user_id'] !== '' ? (int) $body['user_id'] : null;
        }
        if (array_key_exists('consultation_fee', $body)) {
            $doctor->consultation_fee = $body['consultation_fee'];
        }
        if (array_key_exists('is_active', $body)) {
            $doctor->is_active = (bool) $body['is_active'];
        }
    }
}
