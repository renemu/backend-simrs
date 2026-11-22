<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\LabOrder;
use app\models\LabResult;
use app\models\Procedure;
use app\models\User;
use app\models\UserLog;
use app\models\Visit;

/**
 * LabOrderController manages laboratory requests and result entry (FR-06, BR-08).
 */
class LabOrderController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'laboratory',
            'actions' => [
                'index' => 'read',
                'view' => 'read',
                'create' => 'create',
                'update-status' => 'update',
                'save-result' => 'update',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/lab-orders
     * Query: status, visit_id, patient_id, search, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = LabOrder::find()->with(['patient', 'visit', 'result']);

        $status = trim((string) $request->get('status', ''));
        if ($status !== '') {
            $query->andWhere(['{{%lab_orders}}.status' => $status]);
        }

        $visitId = $request->get('visit_id');
        if ($visitId !== null && $visitId !== '') {
            $query->andWhere(['visit_id' => (int) $visitId]);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->joinWith(['patient p'], false);
            $query->andWhere([
                'or',
                ['ilike', '{{%lab_orders}}.order_number', $search],
                ['ilike', '{{%lab_orders}}.test_name', $search],
                ['ilike', 'p.full_name', $search],
                ['ilike', 'p.medical_record_number', $search],
            ]);
        }

        return $this->paginateQuery($query, ['id' => SORT_DESC]);
    }

    /**
     * GET /api/lab-orders/<id>
     */
    public function actionView(int $id): array
    {
        $model = LabOrder::find()->with(['patient', 'visit', 'result'])->where(['id' => $id])->one();
        if (!$model) {
            throw new NotFoundHttpException("Lab order with ID $id not found.");
        }
        return [
            'success' => true,
            'data' => $model,
        ];
    }

    /**
     * POST /api/visits/<visitId>/lab-orders
     * Body: { procedure_id?, test_name?, tariff?, notes? }
     */
    public function actionCreate(int $visitId): array
    {
        $visit = Visit::findOne($visitId);
        if (!$visit) {
            throw new NotFoundHttpException("Visit with ID $visitId not found.");
        }
        if ($visit->status === Visit::STATUS_CANCELLED) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Cannot create lab order for a cancelled visit.',
            ];
        }

        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $order = new LabOrder();
        $order->order_number = LabOrder::generateOrderNumber();
        $order->visit_id = $visit->id;
        $order->patient_id = $visit->patient_id;
        $order->status = LabOrder::STATUS_REQUESTED;
        $order->integration_status = 'local_only';
        $order->correlation_id = bin2hex(random_bytes(12));
        $order->notes = isset($body['notes']) ? trim((string) $body['notes']) : null;
        $order->requested_by = $actor?->id;

        if (!empty($body['procedure_id'])) {
            $proc = Procedure::findOne(['id' => (int) $body['procedure_id'], 'is_active' => true]);
            if (!$proc) {
                Yii::$app->response->statusCode = 422;
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Unprocessable Entity',
                    'message' => 'Selected laboratory procedure not found.',
                ];
            }
            $order->procedure_id = $proc->id;
            $order->test_name = $proc->name;
            $order->tariff_snapshot = (float) $proc->tariff;
        } else {
            $order->test_name = trim((string) ($body['test_name'] ?? ''));
            $order->tariff_snapshot = isset($body['tariff']) ? (float) $body['tariff'] : 0.0;
        }

        if (!$order->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $order->getErrors(),
            ];
        }

        $fresh = LabOrder::find()->with(['patient', 'visit', 'result'])->where(['id' => $order->id])->one();
        UserLog::record('create', 'laboratory', sprintf('Requested lab %s (%s) for visit %s', $order->order_number, $order->test_name, $visit->visit_number), $actor, $order->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Laboratory order created successfully.',
            'data' => $fresh,
        ];
    }

    /**
     * PATCH /api/lab-orders/<id>/status
     */
    public function actionUpdateStatus(int $id): array
    {
        $order = LabOrder::findOne($id);
        if (!$order) {
            throw new NotFoundHttpException("Lab order with ID $id not found.");
        }

        $body = $this->getBodyParams();
        $nextStatus = trim((string) ($body['status'] ?? ''));
        $allowed = [LabOrder::STATUS_REQUESTED, LabOrder::STATUS_IN_PROGRESS, LabOrder::STATUS_COMPLETED, LabOrder::STATUS_CANCELLED];

        if (!in_array($nextStatus, $allowed, true)) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Invalid lab order status.',
            ];
        }

        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;
        $order->status = $nextStatus;
        $order->save(false, ['status', 'updated_at']);

        UserLog::record('update_status', 'laboratory', sprintf('Updated lab order %s status -> %s', $order->order_number, $nextStatus), $actor, $order->id);

        return [
            'success' => true,
            'message' => 'Laboratory order status updated.',
            'data' => LabOrder::find()->with(['patient', 'visit', 'result'])->where(['id' => $order->id])->one(),
        ];
    }

    /**
     * POST/PUT /api/lab-orders/<id>/result
     * Body: { result_summary, result_details?, reference_notes? }
     */
    public function actionSaveResult(int $id): array
    {
        $order = LabOrder::findOne($id);
        if (!$order) {
            throw new NotFoundHttpException("Lab order with ID $id not found.");
        }
        if ($order->status === LabOrder::STATUS_CANCELLED) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Cannot record result for a cancelled lab order.',
            ];
        }

        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            $result = LabResult::findOne(['lab_order_id' => $order->id]) ?? new LabResult();
            $result->lab_order_id = $order->id;
            $result->result_summary = trim((string) ($body['result_summary'] ?? ''));
            if (array_key_exists('result_details', $body)) {
                $result->result_details = $body['result_details'];
            }
            if (array_key_exists('reference_notes', $body)) {
                $result->reference_notes = trim((string) $body['reference_notes']) ?: null;
            }
            $result->recorded_by = $actor?->id;

            if (!$result->save()) {
                $tx->rollBack();
                Yii::$app->response->statusCode = 422;
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Unprocessable Entity',
                    'message' => 'Validation failed.',
                    'errors' => $result->getErrors(),
                ];
            }

            $order->status = LabOrder::STATUS_COMPLETED;
            $order->save(false, ['status', 'updated_at']);

            $tx->commit();

            UserLog::record('save_result', 'laboratory', sprintf('Saved lab result for order %s', $order->order_number), $actor, $order->id);

            return [
                'success' => true,
                'message' => 'Laboratory result saved and order completed.',
                'data' => LabOrder::find()->with(['patient', 'visit', 'result'])->where(['id' => $order->id])->one(),
            ];
        } catch (\Throwable $e) {
            if ($tx->isActive) {
                $tx->rollBack();
            }
            throw $e;
        }
    }
}
