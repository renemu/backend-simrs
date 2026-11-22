<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;
use app\components\PermissionFilter;
use app\models\Medicine;
use app\models\MedicineStockMovement;
use app\models\Prescription;
use app\models\PrescriptionItem;
use app\models\User;
use app\models\UserLog;
use app\models\Visit;

/**
 * PrescriptionController manages doctor prescriptions & atomic pharmacy fulfillment (FR-05, BR-07).
 */
class PrescriptionController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'prescriptions',
            'actions' => [
                'index' => 'read',
                'view' => 'read',
                'create' => 'create',
                'dispense' => 'update',
                'cancel' => 'update',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/prescriptions
     * Query: status, visit_id, patient_id, search, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = Prescription::find()->with(['items.medicine', 'patient', 'doctor', 'visit']);

        $status = trim((string) $request->get('status', ''));
        if ($status !== '') {
            $query->andWhere(['{{%prescriptions}}.status' => $status]);
        }

        $visitId = $request->get('visit_id');
        if ($visitId !== null && $visitId !== '') {
            $query->andWhere(['visit_id' => (int) $visitId]);
        }

        $patientId = $request->get('patient_id');
        if ($patientId !== null && $patientId !== '') {
            $query->andWhere(['{{%prescriptions}}.patient_id' => (int) $patientId]);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->joinWith(['patient p'], false);
            $query->andWhere([
                'or',
                ['ilike', '{{%prescriptions}}.prescription_number', $search],
                ['ilike', 'p.full_name', $search],
                ['ilike', 'p.medical_record_number', $search],
            ]);
        }

        return $this->paginateQuery($query, ['id' => SORT_DESC]);
    }

    /**
     * GET /api/prescriptions/<id>
     */
    public function actionView(int $id): array
    {
        $model = Prescription::find()
            ->with(['items.medicine', 'patient', 'doctor', 'visit'])
            ->where(['id' => $id])
            ->one();

        if (!$model) {
            throw new NotFoundHttpException("Prescription with ID $id not found.");
        }

        return [
            'success' => true,
            'data' => $model,
        ];
    }

    /**
     * POST /api/visits/<visitId>/prescriptions
     * Drafts a prescription without deducting stock yet (BR-07).
     * Body: { notes, items: [{ medicine_id, dosage, frequency, quantity, instructions }] }
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
                'message' => 'Cannot create prescription for a cancelled visit.',
            ];
        }

        $body = $this->getBodyParams();
        $rawItems = $body['items'] ?? [];
        if (!is_array($rawItems) || empty($rawItems)) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'At least one prescription medicine item is required.',
            ];
        }

        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();

        try {
            $prescription = new Prescription();
            $prescription->prescription_number = Prescription::generateNumber();
            $prescription->visit_id = $visit->id;
            $prescription->patient_id = $visit->patient_id;
            $prescription->doctor_id = $visit->doctor_id;
            $prescription->status = Prescription::STATUS_PENDING;
            $prescription->notes = isset($body['notes']) ? trim((string) $body['notes']) : null;
            $prescription->created_by = $actor?->id;
            $prescription->updated_by = $actor?->id;

            if (!$prescription->save()) {
                $transaction->rollBack();
                Yii::$app->response->statusCode = 422;
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Unprocessable Entity',
                    'message' => 'Validation failed.',
                    'errors' => $prescription->getErrors(),
                ];
            }

            foreach ($rawItems as $idx => $itemData) {
                $medId = (int) ($itemData['medicine_id'] ?? 0);
                $qty = (int) ($itemData['quantity'] ?? 0);
                $dosage = trim((string) ($itemData['dosage'] ?? ''));
                $frequency = trim((string) ($itemData['frequency'] ?? ''));

                $medicine = Medicine::findOne(['id' => $medId, 'is_active' => true]);
                if (!$medicine) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'status' => 422,
                        'error' => 'Unprocessable Entity',
                        'message' => sprintf('Item #%d: active medicine not found.', $idx + 1),
                    ];
                }

                if ($qty <= 0 || $dosage === '' || $frequency === '') {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'status' => 422,
                        'error' => 'Unprocessable Entity',
                        'message' => sprintf('Item #%d (%s): dosage, frequency, and positive quantity are required.', $idx + 1, $medicine->name),
                    ];
                }

                $item = new PrescriptionItem();
                $item->prescription_id = $prescription->id;
                $item->medicine_id = $medicine->id;
                $item->medicine_name_snapshot = $medicine->name;
                $item->unit_snapshot = $medicine->unit;
                $item->dosage = $dosage;
                $item->frequency = $frequency;
                $item->quantity = $qty;
                $item->unit_price_snapshot = (float) $medicine->unit_price;
                $item->instructions = isset($itemData['instructions']) ? trim((string) $itemData['instructions']) : null;

                if (!$item->save()) {
                    $transaction->rollBack();
                    Yii::$app->response->statusCode = 422;
                    return [
                        'success' => false,
                        'status' => 422,
                        'error' => 'Unprocessable Entity',
                        'message' => 'Failed to save prescription item.',
                        'errors' => $item->getErrors(),
                    ];
                }
            }

            $transaction->commit();

            $fresh = Prescription::find()
                ->with(['items.medicine', 'patient', 'doctor', 'visit'])
                ->where(['id' => $prescription->id])
                ->one();

            UserLog::record(
                'create',
                'prescriptions',
                sprintf('Created prescription %s (%d item(s)) for visit %s', $prescription->prescription_number, count($rawItems), $visit->visit_number),
                $actor,
                $prescription->id
            );

            Yii::$app->response->statusCode = 201;
            return [
                'success' => true,
                'message' => 'Prescription created successfully (stock will be deducted at pharmacy hand-over).',
                'data' => $fresh,
            ];
        } catch (\Throwable $e) {
            if ($transaction->isActive) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }

    /**
     * POST /api/prescriptions/<id>/dispense
     * Atomically validates stock, deducts stock with row-level locking, and logs stock movements (BR-07).
     */
    public function actionDispense(int $id): array
    {
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $prescription = Prescription::dispenseAtomic($id, $actor);

        UserLog::record(
            'dispense',
            'prescriptions',
            sprintf('Dispensed prescription %s and deducted stock atomically', $prescription->prescription_number),
            $actor,
            $prescription->id
        );

        return [
            'success' => true,
            'message' => 'Prescription dispensed and medicine stock updated successfully.',
            'data' => $prescription,
        ];
    }

    /**
     * PATCH /api/prescriptions/<id>/cancel
     */
    public function actionCancel(int $id): array
    {
        $prescription = Prescription::findOne($id);
        if (!$prescription) {
            throw new NotFoundHttpException("Prescription with ID $id not found.");
        }

        if ($prescription->status !== Prescription::STATUS_PENDING) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => sprintf('Only pending prescriptions can be cancelled (current status: %s).', $prescription->status),
            ];
        }

        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;
        $prescription->status = Prescription::STATUS_CANCELLED;
        $prescription->updated_by = $actor?->id;
        $prescription->save(false, ['status', 'updated_by', 'updated_at']);

        UserLog::record('cancel', 'prescriptions', sprintf('Cancelled prescription %s', $prescription->prescription_number), $actor, $prescription->id);

        return [
            'success' => true,
            'message' => 'Prescription cancelled.',
            'data' => $prescription,
        ];
    }

    /**
     * GET /api/medicine-stock-movements
     * Read-only audit trail of medicine stock changes.
     */
    public function actionStockMovements(): array
    {
        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        if (!$user) {
            throw new UnauthorizedHttpException('Unauthenticated.');
        }
        if (!$user->hasModulePermission('prescriptions', 'read') && !$user->hasModulePermission('master-medicines', 'read')) {
            throw new ForbiddenHttpException('You do not have permission to view stock movements.');
        }

        $request = Yii::$app->request;
        $query = MedicineStockMovement::find()->with(['medicine', 'actor']);

        $medicineId = $request->get('medicine_id');
        if ($medicineId !== null && $medicineId !== '') {
            $query->andWhere(['medicine_id' => (int) $medicineId]);
        }

        return $this->paginateQuery($query, ['id' => SORT_DESC]);
    }
}
