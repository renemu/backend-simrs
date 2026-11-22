<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Patient;
use app\models\User;
use app\models\UserLog;

/**
 * PatientController provides RESTful CRUD for `patients` master data (FR-01.01, FR-01.02).
 */
class PatientController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'master-patients',
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
     * GET /api/patients
     * Query: search (MRN/NIK/name/phone), gender, is_active, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Patient::find();

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'medical_record_number', $search],
                ['ilike', 'national_id', $search],
                ['ilike', 'full_name', $search],
                ['ilike', 'phone', $search],
            ]);
        }

        $gender = trim((string) $request->get('gender', ''));
        if ($gender !== '') {
            $query->andWhere(['gender' => $gender]);
        }

        $isActive = $request->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        $total = (int) (clone $query)->count();
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 20)));

        $items = $query
            ->orderBy(['id' => SORT_DESC])
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
     * GET /api/patients/<id>
     */
    public function actionView(int $id): array
    {
        return [
            'success' => true,
            'data' => $this->findModel($id),
        ];
    }

    /**
     * POST /api/patients
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $patient = new Patient();
        $this->populate($patient, $body);
        $patient->created_by = $actor?->id;
        $patient->updated_by = $actor?->id;

        if (!$patient->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $patient->getErrors(),
            ];
        }

        UserLog::record(
            'create',
            'master-patients',
            sprintf('Created patient %s (%s)', $patient->full_name, $patient->medical_record_number),
            $actor,
            $patient->id
        );

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Patient created successfully.',
            'data' => $patient,
        ];
    }

    /**
     * PUT/PATCH /api/patients/<id>
     */
    public function actionUpdate(int $id): array
    {
        $patient = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        // Preserve stable medical_record_number once assigned (BR-01)
        unset($body['medical_record_number']);

        $this->populate($patient, $body);
        $patient->updated_by = $actor?->id;

        if (!$patient->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $patient->getErrors(),
            ];
        }

        UserLog::record(
            'update',
            'master-patients',
            sprintf('Updated patient %s (%s)', $patient->full_name, $patient->medical_record_number),
            $actor,
            $patient->id
        );

        return [
            'success' => true,
            'message' => 'Patient updated successfully.',
            'data' => $patient,
        ];
    }

    /**
     * DELETE /api/patients/<id>
     * Deactivates patient record (soft deactivate) to preserve medical record history.
     */
    public function actionDelete(int $id): array
    {
        $patient = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        if (!$patient->is_active) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Patient is already inactive.',
            ];
        }

        $patient->is_active = false;
        $patient->updated_by = $actor?->id;
        $patient->save(false, ['is_active', 'updated_by', 'updated_at']);

        UserLog::record(
            'delete',
            'master-patients',
            sprintf('Deactivated patient %s (%s)', $patient->full_name, $patient->medical_record_number),
            $actor,
            $patient->id
        );

        return [
            'success' => true,
            'message' => 'Patient deactivated successfully.',
            'data' => $patient,
        ];
    }

    protected function findModel(int $id): Patient
    {
        $model = Patient::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException("Patient with ID $id not found.");
        }
        return $model;
    }

    private function populate(Patient $patient, array $body): void
    {
        foreach (['medical_record_number', 'national_id', 'full_name', 'birth_date', 'gender', 'phone', 'address', 'blood_type'] as $field) {
            if (array_key_exists($field, $body)) {
                $patient->$field = $body[$field];
            }
        }
        if (array_key_exists('is_active', $body)) {
            $patient->is_active = (bool) $body['is_active'];
        }
    }
}
