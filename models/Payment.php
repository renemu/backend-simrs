<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\db\IntegrityException;
use yii\web\ConflictHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnprocessableEntityHttpException;

/**
 * Payment model matching table `payments` (FR-07.04 - FR-07.08, BR-10, BR-11 idempotent payments).
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $payment_reference
 * @property string $idempotency_key
 * @property string $payment_method
 * @property string|null $payment_channel
 * @property float|string $amount
 * @property string $status
 * @property string|null $notes
 * @property int|null $cashier_id
 * @property string $paid_at
 * @property string $created_at
 *
 * @property-read Invoice|null $invoice
 * @property-read User|null $cashier
 */
class Payment extends ActiveRecord
{
    public const METHOD_CASH = 'cash';
    public const METHOD_NON_CASH = 'non_cash';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VOIDED = 'voided';

    public static function tableName(): string
    {
        return '{{%payments}}';
    }

    public function rules(): array
    {
        return [
            [['invoice_id', 'payment_reference', 'idempotency_key', 'payment_method', 'amount'], 'required'],
            [['invoice_id', 'cashier_id'], 'integer'],
            [['amount'], 'number', 'min' => 0.01],
            [['payment_reference'], 'string', 'max' => 64],
            [['payment_reference'], 'unique'],
            [['idempotency_key'], 'string', 'max' => 128],
            [['idempotency_key'], 'unique'],
            [['payment_method'], 'in', 'range' => [self::METHOD_CASH, self::METHOD_NON_CASH]],
            [['payment_channel'], 'string', 'max' => 64],
            [['status'], 'in', 'range' => [self::STATUS_COMPLETED, self::STATUS_VOIDED]],
            [['notes'], 'string'],
            [['paid_at', 'created_at'], 'safe'],
        ];
    }

    public static function generateReference(): string
    {
        return sprintf('PAY-%s-%s', date('YmdHis'), strtoupper(bin2hex(random_bytes(3))));
    }

    /**
     * Records a payment atomically with idempotency protection (BR-10, BR-11).
     * Returns ['payment' => Payment, 'invoice' => Invoice, 'idempotent_replay' => bool].
     *
     * @return array{payment: self, invoice: Invoice, idempotent_replay: bool}
     * @throws NotFoundHttpException
     * @throws ConflictHttpException
     * @throws UnprocessableEntityHttpException
     */
    public static function recordAtomic(
        int $invoiceId,
        string $idempotencyKey,
        string $paymentMethod,
        ?string $paymentChannel,
        float $amount,
        ?string $notes,
        ?User $actor
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new UnprocessableEntityHttpException('Idempotency-Key (1–128 chars) is required for payment creation.');
        }

        if (!in_array($paymentMethod, [self::METHOD_CASH, self::METHOD_NON_CASH], true)) {
            throw new UnprocessableEntityHttpException('payment_method must be "cash" or "non_cash".');
        }

        if ($amount <= 0) {
            throw new UnprocessableEntityHttpException('Payment amount must be greater than 0.');
        }

        // Fast-path idempotency check before acquiring row locks
        $existingPayment = static::findOne(['idempotency_key' => $idempotencyKey]);
        if ($existingPayment !== null) {
            if ((int) $existingPayment->invoice_id !== $invoiceId) {
                throw new ConflictHttpException('Idempotency-Key has already been used for a different invoice.');
            }
            $invoice = Invoice::find()
                ->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient'])
                ->where(['id' => $invoiceId])
                ->one();
            return [
                'payment' => $existingPayment,
                'invoice' => $invoice,
                'idempotent_replay' => true,
            ];
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction();

        try {
            // 1. Lock invoice row FOR UPDATE so concurrent partial payments serialize cleanly
            $lockedInvoice = $db->createCommand(
                'SELECT id, invoice_number, status, total_amount, paid_amount, balance_amount, payment_status FROM {{%invoices}} WHERE id = :id FOR UPDATE',
                [':id' => $invoiceId]
            )->queryOne();

            if (!$lockedInvoice) {
                throw new NotFoundHttpException("Invoice with ID $invoiceId not found.");
            }
            if ($lockedInvoice['status'] !== Invoice::STATUS_ISSUED) {
                throw new ConflictHttpException(sprintf('Invoice %s is not active (status: %s).', $lockedInvoice['invoice_number'], $lockedInvoice['status']));
            }

            // Re-check idempotency inside transaction
            $replay = static::findOne(['idempotency_key' => $idempotencyKey]);
            if ($replay !== null) {
                $tx->rollBack();
                if ((int) $replay->invoice_id !== $invoiceId) {
                    throw new ConflictHttpException('Idempotency-Key has already been used for a different invoice.');
                }
                $invoice = Invoice::find()
                    ->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient'])
                    ->where(['id' => $invoiceId])
                    ->one();
                return [
                    'payment' => $replay,
                    'invoice' => $invoice,
                    'idempotent_replay' => true,
                ];
            }

            // 2. Recalculate authoritative paid sum from persisted payment records
            $totalAmount = (float) $lockedInvoice['total_amount'];
            $persistedPaid = (float) $db->createCommand(
                "SELECT COALESCE(SUM(amount), 0) FROM {{%payments}} WHERE invoice_id = :id AND status = 'completed'",
                [':id' => $invoiceId]
            )->queryScalar();
            $remaining = round(max(0.0, $totalAmount - $persistedPaid), 2);

            if ($remaining <= 0) {
                throw new ConflictHttpException(sprintf('Invoice %s is already fully paid.', $lockedInvoice['invoice_number']));
            }

            if (round($amount, 2) > $remaining) {
                throw new UnprocessableEntityHttpException(
                    sprintf('Payment amount (%.2f) exceeds remaining invoice balance (%.2f).', $amount, $remaining)
                );
            }

            // 3. Persist new payment record
            $now = date('Y-m-d H:i:s');
            $payment = new self();
            $payment->invoice_id = $invoiceId;
            $payment->payment_reference = self::generateReference();
            $payment->idempotency_key = $idempotencyKey;
            $payment->payment_method = $paymentMethod;
            $payment->payment_channel = $paymentChannel !== null && trim($paymentChannel) !== ''
                ? trim($paymentChannel)
                : ($paymentMethod === self::METHOD_CASH ? 'Tunai' : 'Non-Tunai Simulasi');
            $payment->amount = round($amount, 2);
            $payment->status = self::STATUS_COMPLETED;
            $payment->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null;
            $payment->cashier_id = $actor?->id;
            $payment->paid_at = $now;
            $payment->created_at = $now;

            if (!$payment->save()) {
                $firstErrors = $payment->getFirstErrors();
                throw new UnprocessableEntityHttpException(reset($firstErrors) ?: 'Failed to save payment.');
            }

            // 4. Derive invoice paid_amount, balance_amount, and payment_status from persisted payments
            $newPaid = (float) $db->createCommand(
                "SELECT COALESCE(SUM(amount), 0) FROM {{%payments}} WHERE invoice_id = :id AND status = 'completed'",
                [':id' => $invoiceId]
            )->queryScalar();
            $newBalance = round(max(0.0, $totalAmount - $newPaid), 2);
            $newPaymentStatus = $newBalance <= 0
                ? Invoice::PAY_PAID
                : ($newPaid > 0 ? Invoice::PAY_PARTIAL : Invoice::PAY_UNPAID);

            $db->createCommand()->update(
                '{{%invoices}}',
                [
                    'paid_amount' => round($newPaid, 2),
                    'balance_amount' => $newBalance,
                    'payment_status' => $newPaymentStatus,
                    'updated_at' => $now,
                ],
                ['id' => $invoiceId]
            )->execute();

            $tx->commit();

            $invoice = Invoice::find()
                ->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient'])
                ->where(['id' => $invoiceId])
                ->one();

            return [
                'payment' => static::find()->with('cashier')->where(['id' => $payment->id])->one(),
                'invoice' => $invoice,
                'idempotent_replay' => false,
            ];
        } catch (IntegrityException $e) {
            if ($tx->isActive) {
                $tx->rollBack();
            }
            // Handle concurrent retry race on unique idempotency_key
            if (str_contains($e->getMessage(), 'idempotency_key') || str_contains($e->getMessage(), '23505')) {
                $replay = static::findOne(['idempotency_key' => $idempotencyKey]);
                if ($replay !== null && (int) $replay->invoice_id === $invoiceId) {
                    $invoice = Invoice::find()
                        ->with(['items', 'payments.cashier', 'visit.department', 'visit.doctor', 'patient'])
                        ->where(['id' => $invoiceId])
                        ->one();
                    return [
                        'payment' => $replay,
                        'invoice' => $invoice,
                        'idempotent_replay' => true,
                    ];
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($tx->isActive) {
                $tx->rollBack();
            }
            throw $e;
        }
    }

    public function getInvoice(): ActiveQuery
    {
        return $this->hasOne(Invoice::class, ['id' => 'invoice_id']);
    }

    public function getCashier(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'cashier_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'invoice_id',
            'payment_reference',
            'idempotency_key',
            'payment_method',
            'payment_channel',
            'amount' => fn(self $m) => (float) $m->amount,
            'status',
            'notes',
            'cashier_id',
            'cashier' => fn(self $m) => $m->cashier ? [
                'id' => $m->cashier->id,
                'name' => $m->cashier->name,
                'email' => $m->cashier->email,
            ] : null,
            'invoice_number' => fn(self $m) => $m->invoice?->invoice_number,
            'patient_name' => fn(self $m) => $m->invoice?->patient?->full_name,
            'paid_at',
            'created_at',
        ];
    }
}
