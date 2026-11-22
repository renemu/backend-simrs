<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Procedure;
use app\models\User;
use app\models\UserLog;

/**
 * ProcedureController provides RESTful CRUD for `procedures` (tindakan & tarif medis) (FR-01.06).
 */
class ProcedureController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'master-procedures',
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
     * GET /api/procedures
     * Query: search, category, is_active, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Procedure::find();

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'code', $search],
                ['ilike', 'name', $search],
            ]);
        }

        $category = trim((string) $request->get('category', ''));
        if ($category !== '') {
            $query->andWhere(['category' => strtolower($category)]);
        }

        $isActive = $request->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        $total = (int) (clone $query)->count();
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 20)));

        $items = $query
            ->orderBy(['id' => SORT_ASC])
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
     * GET /api/procedures/<id>
     */
    public function actionView(int $id): array
    {
        return [
            'success' => true,
            'data' => $this->findModel($id),
        ];
    }

    /**
     * POST /api/procedures
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $procedure = new Procedure();
        $this->populate($procedure, $body);
        $procedure->created_by = $actor?->id;
        $procedure->updated_by = $actor?->id;

        if (!$procedure->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $procedure->getErrors(),
            ];
        }

        UserLog::record('create', 'master-procedures', sprintf('Created procedure %s (%s)', $procedure->name, $procedure->code), $actor, $procedure->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Procedure created successfully.',
            'data' => $procedure,
        ];
    }

    /**
     * PUT/PATCH /api/procedures/<id>
     */
    public function actionUpdate(int $id): array
    {
        $procedure = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $this->populate($procedure, $body);
        $procedure->updated_by = $actor?->id;

        if (!$procedure->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $procedure->getErrors(),
            ];
        }

        UserLog::record('update', 'master-procedures', sprintf('Updated procedure %s (%s)', $procedure->name, $procedure->code), $actor, $procedure->id);

        return [
            'success' => true,
            'message' => 'Procedure updated successfully.',
            'data' => $procedure,
        ];
    }

    /**
     * DELETE /api/procedures/<id>
     */
    public function actionDelete(int $id): array
    {
        $procedure = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        if (!$procedure->is_active) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Procedure is already inactive.',
            ];
        }

        $procedure->is_active = false;
        $procedure->updated_by = $actor?->id;
        $procedure->save(false, ['is_active', 'updated_by', 'updated_at']);

        UserLog::record('delete', 'master-procedures', sprintf('Deactivated procedure %s (%s)', $procedure->name, $procedure->code), $actor, $procedure->id);

        return [
            'success' => true,
            'message' => 'Procedure deactivated successfully.',
            'data' => $procedure,
        ];
    }

    protected function findModel(int $id): Procedure
    {
        $model = Procedure::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException("Procedure with ID $id not found.");
        }
        return $model;
    }

    private function populate(Procedure $procedure, array $body): void
    {
        foreach (['code', 'name', 'category', 'description'] as $field) {
            if (array_key_exists($field, $body)) {
                $procedure->$field = $body[$field];
            }
        }
        if (array_key_exists('tariff', $body)) {
            $procedure->tariff = $body['tariff'];
        }
        if (array_key_exists('is_active', $body)) {
            $procedure->is_active = (bool) $body['is_active'];
        }
    }
}
