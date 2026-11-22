<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Invoice;
use app\models\User;
use app\models\UserLog;

/**
 * BillingController handles consolidated outpatient billing & explicit invoice revisions (FR-07, BR-09, BR-12).
 */
class BillingController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'billings',
            'actions' => [
                'index' => 'read',
                'view' => 'read',
                'generate' => 'create',
                'revise' => 'update',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/invoices
     * Query: payment_status, status, visit_id, patient_id, search, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Invoice::find()->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient']);

        $paymentStatus = trim((string) $request->get('payment_status', ''));
        if ($paymentStatus !== '') {
            $query->andWhere(['{{%invoices}}.payment_status' => $paymentStatus]);
        }

        $status = trim((string) $request->get('status', ''));
        if ($status !== '') {
            $query->andWhere(['{{%invoices}}.status' => $status]);
        }

        $visitId = $request->get('visit_id');
        if ($visitId !== null && $visitId !== '') {
            $query->andWhere(['{{%invoices}}.visit_id' => (int) $visitId]);
        }

        $patientId = $request->get('patient_id');
        if ($patientId !== null && $patientId !== '') {
            $query->andWhere(['{{%invoices}}.patient_id' => (int) $patientId]);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->joinWith(['patient p'], false);
            $query->andWhere([
                'or',
                ['ilike', '{{%invoices}}.invoice_number', $search],
                ['ilike', 'p.full_name', $search],
                ['ilike', 'p.medical_record_number', $search],
            ]);
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
     * GET /api/invoices/<id>
     */
    public function actionView(int $id): array
    {
        $invoice = Invoice::find()
            ->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient'])
            ->where(['id' => $id])
            ->one();

        if (!$invoice) {
            throw new NotFoundHttpException("Invoice with ID $id not found.");
        }

        return [
            'success' => true,
            'data' => $invoice,
        ];
    }

    /**
     * POST /api/visits/<visitId>/invoices
     * Consolidates registration, consultation, active procedures, lab orders, and dispensed medicines into one invoice.
     */
    public function actionGenerate(int $visitId): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $notes = isset($body['notes']) ? (string) $body['notes'] : null;
        $invoice = Invoice::generateForVisitAtomic($visitId, $notes, $actor);

        UserLog::record(
            'generate_invoice',
            'billings',
            sprintf('Generated invoice %s (total=%.2f) for visit_id=%d', $invoice->invoice_number, (float) $invoice->total_amount, $visitId),
            $actor,
            $invoice->id
        );

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Invoice generated successfully.',
            'data' => $invoice,
        ];
    }

    /**
     * POST /api/invoices/<id>/revise
     * Explicitly creates a new revised invoice when clinical charges changed after initial billing (BR-12).
     */
    public function actionRevise(int $id): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $reason = isset($body['reason']) ? (string) $body['reason'] : 'Pembaruan item tindakan/obat pasca billing';
        $revised = Invoice::reviseAtomic($id, $reason, $actor);

        UserLog::record(
            'revise_invoice',
            'billings',
            sprintf('Revised invoice #%d -> %s (rev %d, total=%.2f)', $id, $revised->invoice_number, (int) $revised->revision_number, (float) $revised->total_amount),
            $actor,
            $revised->id
        );

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Invoice revised successfully.',
            'data' => $revised,
        ];
    }
}
