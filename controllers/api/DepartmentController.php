<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Department;
use app\models\Doctor;
use app\models\User;
use app\models\UserLog;

/**
 * DepartmentController provides RESTful CRUD for `departments` (poliklinik / service units) (FR-01.04).
 */
class DepartmentController extends BaseApiController
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
     * GET /api/departments
     * Query: search, is_active, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Department::find();

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'code', $search],
                ['ilike', 'name', $search],
            ]);
        }

        $isActive = $request->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        return $this->paginateQuery($query, ['id' => SORT_ASC], 50);
    }

    /**
     * GET /api/departments/<id>
     */
    public function actionView(int $id): array
    {
        return [
            'success' => true,
            'data' => $this->findModel($id),
        ];
    }

    /**
     * POST /api/departments
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $dept = new Department();
        $this->populate($dept, $body);
        $dept->created_by = $actor?->id;
        $dept->updated_by = $actor?->id;

        if (!$dept->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $dept->getErrors(),
            ];
        }

        UserLog::record('create', 'master-doctors', sprintf('Created department %s (%s)', $dept->name, $dept->code), $actor, $dept->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Department created successfully.',
            'data' => $dept,
        ];
    }

    /**
     * PUT/PATCH /api/departments/<id>
     */
    public function actionUpdate(int $id): array
    {
        $dept = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $this->populate($dept, $body);
        $dept->updated_by = $actor?->id;

        if (!$dept->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $dept->getErrors(),
            ];
        }

        UserLog::record('update', 'master-doctors', sprintf('Updated department %s (%s)', $dept->name, $dept->code), $actor, $dept->id);

        return [
            'success' => true,
            'message' => 'Department updated successfully.',
            'data' => $dept,
        ];
    }

    /**
     * DELETE /api/departments/<id>
     * Deactivates department if no active doctors are assigned.
     */
    public function actionDelete(int $id): array
    {
        $dept = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $activeDoctors = (int) Doctor::find()->where(['department_id' => $dept->id, 'is_active' => true])->count();
        if ($activeDoctors > 0) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => sprintf('Department "%s" still has %d active doctor(s).', $dept->name, $activeDoctors),
            ];
        }

        $dept->is_active = false;
        $dept->updated_by = $actor?->id;
        $dept->save(false, ['is_active', 'updated_by', 'updated_at']);

        UserLog::record('delete', 'master-doctors', sprintf('Deactivated department %s (%s)', $dept->name, $dept->code), $actor, $dept->id);

        return [
            'success' => true,
            'message' => 'Department deactivated successfully.',
            'data' => $dept,
        ];
    }

    protected function findModel(int $id): Department
    {
        $model = Department::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException("Department with ID $id not found.");
        }
        return $model;
    }

    private function populate(Department $dept, array $body): void
    {
        foreach (['code', 'name', 'description'] as $field) {
            if (array_key_exists($field, $body)) {
                $dept->$field = $body[$field];
            }
        }
        if (array_key_exists('registration_fee', $body)) {
            $dept->registration_fee = $body['registration_fee'];
        }
        if (array_key_exists('is_active', $body)) {
            $dept->is_active = (bool) $body['is_active'];
        }
    }
}
