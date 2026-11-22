<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use app\components\PermissionFilter;
use app\models\Payment;
use app\models\User;
use app\models\UserLog;

/**
 * PaymentController handles staged/partial payments and idempotent payment recording (FR-07.04 - FR-07.08, BR-10, BR-11).
 */
class PaymentController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'payments',
            'actions' => [
                'index' => 'read',
                'create' => 'create',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/payments
     * Query: invoice_id, payment_method, search, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Payment::find()->with(['invoice.patient', 'cashier']);

        $invoiceId = $request->get('invoice_id');
        if ($invoiceId !== null && $invoiceId !== '') {
            $query->andWhere(['{{%payments}}.invoice_id' => (int) $invoiceId]);
        }

        $method = trim((string) $request->get('payment_method', ''));
        if ($method !== '') {
            $query->andWhere(['{{%payments}}.payment_method' => $method]);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->joinWith(['invoice inv', 'invoice.patient p'], false);
            $query->andWhere([
                'or',
                ['ilike', '{{%payments}}.payment_reference', $search],
                ['ilike', 'inv.invoice_number', $search],
                ['ilike', 'p.full_name', $search],
            ]);
        }

        return $this->paginateQuery($query, ['id' => SORT_DESC]);
    }

    /**
     * POST /api/invoices/<invoiceId>/payments
     * Header: Idempotency-Key: <unique-key> (or body.idempotency_key)
     * Body: { payment_method: 'cash'|'non_cash', payment_channel?, amount, notes? }
     */
    public function actionCreate(int $invoiceId): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $headerKey = Yii::$app->request->headers->get('Idempotency-Key');
        $idempotencyKey = trim((string) ($headerKey ?: ($body['idempotency_key'] ?? '')));

        $paymentMethod = trim((string) ($body['payment_method'] ?? 'cash'));
        $paymentChannel = isset($body['payment_channel']) ? (string) $body['payment_channel'] : null;
        $amount = (float) ($body['amount'] ?? 0);
        $notes = isset($body['notes']) ? (string) $body['notes'] : null;

        $result = Payment::recordAtomic(
            $invoiceId,
            $idempotencyKey,
            $paymentMethod,
            $paymentChannel,
            $amount,
            $notes,
            $actor
        );

        $payment = $result['payment'];
        $invoice = $result['invoice'];
        $isReplay = $result['idempotent_replay'];

        if (!$isReplay) {
            UserLog::record(
                'create_payment',
                'payments',
                sprintf(
                    'Recorded %s payment %s (amount=%.2f) for invoice %s (status=%s)',
                    $payment->payment_method,
                    $payment->payment_reference,
                    (float) $payment->amount,
                    $invoice->invoice_number,
                    $invoice->payment_status
                ),
                $actor,
                $payment->id
            );
        }

        Yii::$app->response->statusCode = $isReplay ? 200 : 201;
        return [
            'success' => true,
            'message' => $isReplay
                ? 'Idempotent retry detected: returning previously recorded payment without duplicating.'
                : 'Payment recorded successfully.',
            'idempotent_replay' => $isReplay,
            'data' => [
                'payment' => $payment,
                'invoice' => $invoice,
            ],
        ];
    }
}
