<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\InternalServiceAuthFilter;
use app\components\PermissionFilter;
use app\models\LabOrder;
use app\models\LabResult;
use app\models\Procedure;
use app\models\User;
use app\models\UserLog;
use app\models\Visit;

/**
 * LabOrderController manages laboratory & diagnostic modality requests,
 * external gateway integration status updates, and authoritative result entry (FR-06, BR-08, BR-13).
 */
class LabOrderController extends BaseApiController
{
    protected function authOptionalActions(): array
    {
        // webhook-result uses strict HMAC M2M verification via InternalServiceAuthFilter instead of user JWT
        return ['webhook-result'];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['internalServiceAuth'] = [
            'class' => InternalServiceAuthFilter::class,
            'only' => ['webhook-result'],
        ];

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'laboratory',
            'actions' => [
                'index' => 'read',
                'view' => 'read',
                'create' => 'create',
                'update-status' => 'update',
                'update-integration' => 'update',
                'save-result' => 'update',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/lab-orders
     * Query: status, modality_type, integration_status, visit_id, patient_id, search, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = LabOrder::find()->with(['patient', 'visit', 'result']);

        $status = trim((string) $request->get('status', ''));
        if ($status !== '') {
            $query->andWhere(['{{%lab_orders}}.status' => $status]);
        }

        $modalityType = trim((string) $request->get('modality_type', ''));
        if ($modalityType !== '') {
            $query->andWhere(['{{%lab_orders}}.modality_type' => strtolower($modalityType)]);
        }

        $integrationStatus = trim((string) $request->get('integration_status', ''));
        if ($integrationStatus !== '') {
            $query->andWhere(['{{%lab_orders}}.integration_status' => strtolower($integrationStatus)]);
        }

        $visitId = $request->get('visit_id');
        if ($visitId !== null && $visitId !== '') {
            $query->andWhere(['{{%lab_orders}}.visit_id' => (int) $visitId]);
        }

        $patientId = $request->get('patient_id');
        if ($patientId !== null && $patientId !== '') {
            $query->andWhere(['{{%lab_orders}}.patient_id' => (int) $patientId]);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->joinWith(['patient p'], false);
            $query->andWhere([
                'or',
                ['ilike', '{{%lab_orders}}.order_number', $search],
                ['ilike', '{{%lab_orders}}.test_name', $search],
                ['ilike', '{{%lab_orders}}.external_reference_id', $search],
                ['ilike', '{{%lab_orders}}.body_part', $search],
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
     * Body: { procedure_id?, test_name?, modality_type?, body_part?, contrast_used?, external_system?, tariff?, notes? }
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
        $order->integration_status = LabOrder::INTEGRATION_LOCAL_ONLY;
        $order->correlation_id = trim((string) Yii::$app->request->headers->get('X-Correlation-Id', '')) ?: bin2hex(random_bytes(12));
        $order->notes = isset($body['notes']) && trim((string) $body['notes']) !== '' ? trim((string) $body['notes']) : null;
        $order->body_part = isset($body['body_part']) && trim((string) $body['body_part']) !== '' ? trim((string) $body['body_part']) : null;
        $order->contrast_used = isset($body['contrast_used']) ? filter_var($body['contrast_used'], FILTER_VALIDATE_BOOLEAN) : false;
        $order->external_system = isset($body['external_system']) && trim((string) $body['external_system']) !== '' ? trim((string) $body['external_system']) : null;
        $order->requested_by = $actor?->id;

        $procCode = null;
        if (!empty($body['procedure_id'])) {
            $proc = Procedure::findOne(['id' => (int) $body['procedure_id'], 'is_active' => true]);
            if (!$proc) {
                Yii::$app->response->statusCode = 422;
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Unprocessable Entity',
                    'message' => 'Selected laboratory/diagnostic procedure not found.',
                ];
            }
            $procCode = $proc->code;
            $order->procedure_id = $proc->id;
            $order->test_name = $proc->name;
            $order->tariff_snapshot = (float) $proc->tariff;
        } else {
            $order->test_name = trim((string) ($body['test_name'] ?? ''));
            $order->tariff_snapshot = isset($body['tariff']) ? (float) $body['tariff'] : 0.0;
        }

        $requestedModality = isset($body['modality_type']) ? strtolower(trim((string) $body['modality_type'])) : '';
        if ($requestedModality !== '') {
            $order->modality_type = $requestedModality;
        } else {
            $order->modality_type = LabOrder::inferModality($procCode, $order->test_name);
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
        UserLog::record(
            'create',
            'laboratory',
            sprintf('Requested %s order %s (%s) for visit %s', $order->modality_type, $order->order_number, $order->test_name, $visit->visit_number),
            $actor,
            $order->id
        );

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
     * PATCH /api/lab-orders/<id>/integration
     * Authoritative sync endpoint called by backend-node external modality gateway (CT-Scan, X-Ray, MRI, USG, LIS)
     * to persist dispatch, sync, or downstream failure reconciliation metadata in PostgreSQL (BR-13).
     */
    public function actionUpdateIntegration(int $id): array
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
                'message' => 'Cannot update external integration state for a cancelled lab order.',
            ];
        }

        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $integrationStatus = isset($body['integration_status'])
            ? strtolower(trim((string) $body['integration_status']))
            : $order->integration_status;

        if (!in_array($integrationStatus, LabOrder::ALLOWED_INTEGRATION_STATUSES, true)) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Invalid integration_status.',
                'errors' => [
                    'integration_status' => ['integration_status must be one of: ' . implode(', ', LabOrder::ALLOWED_INTEGRATION_STATUSES)],
                ],
            ];
        }

        $order->integration_status = $integrationStatus;

        if (isset($body['modality_type']) && trim((string) $body['modality_type']) !== '') {
            $order->modality_type = strtolower(trim((string) $body['modality_type']));
        }
        if (array_key_exists('body_part', $body)) {
            $order->body_part = $body['body_part'] !== null && trim((string) $body['body_part']) !== ''
                ? trim((string) $body['body_part'])
                : null;
        }
        if (array_key_exists('contrast_used', $body)) {
            $order->contrast_used = filter_var($body['contrast_used'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('external_system', $body)) {
            $order->external_system = $body['external_system'] !== null && trim((string) $body['external_system']) !== ''
                ? trim((string) $body['external_system'])
                : null;
        }
        if (array_key_exists('external_reference_id', $body)) {
            $order->external_reference_id = $body['external_reference_id'] !== null && trim((string) $body['external_reference_id']) !== ''
                ? trim((string) $body['external_reference_id'])
                : null;
        }

        $corrHeader = trim((string) Yii::$app->request->headers->get('X-Correlation-Id', ''));
        if (!empty($body['correlation_id'])) {
            $order->correlation_id = trim((string) $body['correlation_id']);
        } elseif ($corrHeader !== '') {
            $order->correlation_id = substr($corrHeader, 0, 64);
        }

        $now = date('Y-m-d H:i:s');
        if ($integrationStatus === LabOrder::INTEGRATION_DISPATCHED) {
            $order->dispatched_at = !empty($body['dispatched_at']) ? (string) $body['dispatched_at'] : $now;
            $order->integration_error = null;
            if ($order->status === LabOrder::STATUS_REQUESTED) {
                $order->status = LabOrder::STATUS_IN_PROGRESS;
            }
        } elseif ($integrationStatus === LabOrder::INTEGRATION_SYNCED) {
            $order->last_synced_at = !empty($body['last_synced_at']) ? (string) $body['last_synced_at'] : $now;
            $order->integration_error = null;
        } elseif ($integrationStatus === LabOrder::INTEGRATION_FAILED) {
            $order->integration_error = isset($body['integration_error']) && trim((string) $body['integration_error']) !== ''
                ? trim((string) $body['integration_error'])
                : 'External gateway communication failed.';
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

        UserLog::record(
            'external_integration',
            'laboratory',
            sprintf(
                'External gateway [%s] updated order %s -> integration_status=%s%s',
                $order->external_system ?? $order->modality_type,
                $order->order_number,
                $order->integration_status,
                $order->external_reference_id ? " (ref={$order->external_reference_id})" : ''
            ),
            $actor,
            $order->id
        );

        return [
            'success' => true,
            'message' => 'Laboratory order external integration metadata updated.',
            'data' => LabOrder::find()->with(['patient', 'visit', 'result'])->where(['id' => $order->id])->one(),
        ];
    }

    /**
     * POST/PUT /api/lab-orders/<id>/result
     * Body: {
     *   result_summary, result_details?, reference_notes?,
     *   result_source?, external_study_uid?, modality_device?, external_completed_at?,
     *   external_reference_id?, external_system?, correlation_id?
     * }
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
            if (array_key_exists('result_source', $body) && trim((string) $body['result_source']) !== '') {
                $result->result_source = strtolower(trim((string) $body['result_source']));
            } elseif (empty($result->result_source)) {
                $result->result_source = LabResult::SOURCE_MANUAL;
            }
            if (array_key_exists('external_study_uid', $body)) {
                $result->external_study_uid = $body['external_study_uid'] !== null && trim((string) $body['external_study_uid']) !== ''
                    ? trim((string) $body['external_study_uid'])
                    : null;
            }
            if (array_key_exists('modality_device', $body)) {
                $result->modality_device = $body['modality_device'] !== null && trim((string) $body['modality_device']) !== ''
                    ? trim((string) $body['modality_device'])
                    : null;
            }
            if (array_key_exists('external_completed_at', $body)) {
                $result->external_completed_at = $body['external_completed_at'] !== null && trim((string) $body['external_completed_at']) !== ''
                    ? trim((string) $body['external_completed_at'])
                    : null;
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

            $now = date('Y-m-d H:i:s');
            $order->status = LabOrder::STATUS_COMPLETED;
            $updateCols = ['status', 'updated_at'];

            if ($result->result_source === LabResult::SOURCE_EXTERNAL_GATEWAY) {
                $order->integration_status = LabOrder::INTEGRATION_SYNCED;
                $order->last_synced_at = $now;
                $order->integration_error = null;
                $updateCols = array_merge($updateCols, ['integration_status', 'last_synced_at', 'integration_error']);

                if (!empty($body['external_reference_id'])) {
                    $order->external_reference_id = trim((string) $body['external_reference_id']);
                    $updateCols[] = 'external_reference_id';
                }
                if (!empty($body['external_system'])) {
                    $order->external_system = trim((string) $body['external_system']);
                    $updateCols[] = 'external_system';
                }
                $corrId = !empty($body['correlation_id'])
                    ? trim((string) $body['correlation_id'])
                    : trim((string) Yii::$app->request->headers->get('X-Correlation-Id', ''));
                if ($corrId !== '') {
                    $order->correlation_id = substr($corrId, 0, 64);
                    $updateCols[] = 'correlation_id';
                }
            }

            $order->save(false, array_values(array_unique($updateCols)));

            $tx->commit();

            UserLog::record(
                'save_result',
                'laboratory',
                sprintf(
                    'Saved %s result (%s) for order %s',
                    $order->modality_type,
                    $result->result_source,
                    $order->order_number
                ),
                $actor,
                $order->id
            );

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

    /**
     * POST /api/internal/lab-orders/webhook-result
     * Protected by InternalServiceAuthFilter (HMAC-SHA256 M2M).
     * Receives verified asynchronous external RIS/PACS/CT-Scan webhook callbacks forwarded by backend-node
     * without requiring a user session JWT. Resolves order by lab_order_id, external_reference_id, or order_number.
     */
    public function actionWebhookResult(): array
    {
        $body = $this->getBodyParams();

        $order = null;
        if (!empty($body['lab_order_id'])) {
            $order = LabOrder::findOne((int) $body['lab_order_id']);
        }
        if (!$order && !empty($body['external_reference_id'])) {
            $order = LabOrder::findOne(['external_reference_id' => trim((string) $body['external_reference_id'])]);
        }
        if (!$order && !empty($body['order_number'])) {
            $order = LabOrder::findOne(['order_number' => trim((string) $body['order_number'])]);
        }

        if (!$order) {
            throw new NotFoundHttpException('Target laboratory/diagnostic order not found for webhook callback.');
        }
        if ($order->status === LabOrder::STATUS_CANCELLED) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Cannot record webhook result for a cancelled order.',
            ];
        }

        $summary = trim((string) ($body['result_summary'] ?? ''));
        if ($summary === '') {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => ['result_summary' => ['result_summary is required.']],
            ];
        }

        $studyUid = isset($body['external_study_uid']) && trim((string) $body['external_study_uid']) !== ''
            ? trim((string) $body['external_study_uid'])
            : null;

        // Idempotent replay check: if already synced with identical summary & study UID, return 200 without duplicating write
        $existingResult = LabResult::findOne(['lab_order_id' => $order->id]);
        if (
            $existingResult !== null
            && $order->integration_status === LabOrder::INTEGRATION_SYNCED
            && $existingResult->result_summary === $summary
            && ($studyUid === null || $existingResult->external_study_uid === $studyUid)
        ) {
            return [
                'success' => true,
                'idempotent_replay' => true,
                'message' => 'Webhook result already synchronized (idempotent replay).',
                'data' => LabOrder::find()->with(['patient', 'visit', 'result'])->where(['id' => $order->id])->one(),
            ];
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();
        try {
            $result = $existingResult ?? new LabResult();
            $result->lab_order_id = $order->id;
            $result->result_summary = $summary;
            if (array_key_exists('result_details', $body)) {
                $result->result_details = $body['result_details'];
            }
            if (array_key_exists('reference_notes', $body)) {
                $result->reference_notes = trim((string) $body['reference_notes']) ?: null;
            }
            $result->result_source = LabResult::SOURCE_EXTERNAL_GATEWAY;
            if ($studyUid !== null) {
                $result->external_study_uid = $studyUid;
            }
            if (array_key_exists('modality_device', $body) && trim((string) $body['modality_device']) !== '') {
                $result->modality_device = trim((string) $body['modality_device']);
            }
            if (array_key_exists('external_completed_at', $body) && trim((string) $body['external_completed_at']) !== '') {
                $result->external_completed_at = trim((string) $body['external_completed_at']);
            }

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

            $now = date('Y-m-d H:i:s');
            $order->status = LabOrder::STATUS_COMPLETED;
            $order->integration_status = LabOrder::INTEGRATION_SYNCED;
            $order->last_synced_at = $now;
            $order->integration_error = null;
            $updateCols = ['status', 'integration_status', 'last_synced_at', 'integration_error', 'updated_at'];

            if (!empty($body['modality_type']) && in_array(strtolower(trim((string) $body['modality_type'])), LabOrder::ALLOWED_MODALITIES, true)) {
                $order->modality_type = strtolower(trim((string) $body['modality_type']));
                $updateCols[] = 'modality_type';
            }
            if (!empty($body['external_reference_id'])) {
                $order->external_reference_id = trim((string) $body['external_reference_id']);
                $updateCols[] = 'external_reference_id';
            }
            if (!empty($body['external_system'])) {
                $order->external_system = trim((string) $body['external_system']);
                $updateCols[] = 'external_system';
            }
            $corrId = !empty($body['correlation_id'])
                ? trim((string) $body['correlation_id'])
                : trim((string) Yii::$app->request->headers->get('X-Correlation-Id', ''));
            if ($corrId !== '') {
                $order->correlation_id = substr($corrId, 0, 64);
                $updateCols[] = 'correlation_id';
            }

            $order->save(false, array_values(array_unique($updateCols)));

            $tx->commit();

            UserLog::record(
                'webhook_result',
                'laboratory',
                sprintf(
                    'Inbound external webhook [%s] synced %s result for order %s (ref=%s)',
                    $order->external_system ?? 'external-gateway',
                    $order->modality_type,
                    $order->order_number,
                    $order->external_reference_id ?? '-'
                ),
                null,
                $order->id
            );

            return [
                'success' => true,
                'idempotent_replay' => false,
                'message' => 'External webhook result persisted and order completed.',
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
