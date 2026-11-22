<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Medicine;
use app\models\User;
use app\models\UserLog;

/**
 * MedicineController provides RESTful CRUD for `medicines` (obat, stok & harga) (FR-01.07).
 */
class MedicineController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'master-medicines',
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
     * GET /api/medicines
     * Query: search, low_stock, is_active, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Medicine::find();

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'code', $search],
                ['ilike', 'name', $search],
                ['ilike', 'unit', $search],
            ]);
        }

        $lowStock = $request->get('low_stock');
        if ($lowStock !== null && $lowStock !== '' && filter_var($lowStock, FILTER_VALIDATE_BOOLEAN)) {
            $query->andWhere('stock <= min_stock');
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
     * GET /api/medicines/<id>
     */
    public function actionView(int $id): array
    {
        return [
            'success' => true,
            'data' => $this->findModel($id),
        ];
    }

    /**
     * POST /api/medicines
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $medicine = new Medicine();
        $this->populate($medicine, $body);
        $medicine->created_by = $actor?->id;
        $medicine->updated_by = $actor?->id;

        if (!$medicine->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $medicine->getErrors(),
            ];
        }

        UserLog::record(
            'create',
            'master-medicines',
            sprintf('Created medicine %s (%s), initial stock=%d', $medicine->name, $medicine->code, $medicine->stock),
            $actor,
            $medicine->id
        );

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Medicine created successfully.',
            'data' => $medicine,
        ];
    }

    /**
     * PUT/PATCH /api/medicines/<id>
     */
    public function actionUpdate(int $id): array
    {
        $medicine = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $oldStock = (int) $medicine->stock;
        $this->populate($medicine, $body);
        $medicine->updated_by = $actor?->id;

        if (!$medicine->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $medicine->getErrors(),
            ];
        }

        UserLog::record(
            'update',
            'master-medicines',
            sprintf('Updated medicine %s (%s), stock %d -> %d', $medicine->name, $medicine->code, $oldStock, (int) $medicine->stock),
            $actor,
            $medicine->id
        );

        return [
            'success' => true,
            'message' => 'Medicine updated successfully.',
            'data' => $medicine,
        ];
    }

    /**
     * DELETE /api/medicines/<id>
     */
    public function actionDelete(int $id): array
    {
        $medicine = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        if (!$medicine->is_active) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Medicine is already inactive.',
            ];
        }

        $medicine->is_active = false;
        $medicine->updated_by = $actor?->id;
        $medicine->save(false, ['is_active', 'updated_by', 'updated_at']);

        UserLog::record('delete', 'master-medicines', sprintf('Deactivated medicine %s (%s)', $medicine->name, $medicine->code), $actor, $medicine->id);

        return [
            'success' => true,
            'message' => 'Medicine deactivated successfully.',
            'data' => $medicine,
        ];
    }

    protected function findModel(int $id): Medicine
    {
        $model = Medicine::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException("Medicine with ID $id not found.");
        }
        return $model;
    }

    private function populate(Medicine $medicine, array $body): void
    {
        foreach (['code', 'name', 'unit'] as $field) {
            if (array_key_exists($field, $body)) {
                $medicine->$field = $body[$field];
            }
        }
        if (array_key_exists('stock', $body)) {
            $medicine->stock = (int) $body['stock'];
        }
        if (array_key_exists('min_stock', $body)) {
            $medicine->min_stock = (int) $body['min_stock'];
        }
        if (array_key_exists('unit_price', $body)) {
            $medicine->unit_price = $body['unit_price'];
        }
        if (array_key_exists('is_active', $body)) {
            $medicine->is_active = (bool) $body['is_active'];
        }
    }
}
