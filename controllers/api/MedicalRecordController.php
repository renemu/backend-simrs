<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\MedicalRecord;
use app\models\Procedure;
use app\models\User;
use app\models\UserLog;
use app\models\Visit;
use app\models\VisitProcedure;

/**
 * MedicalRecordController manages electronic medical records (FR-03) and visit medical procedures (FR-04).
 */
class MedicalRecordController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'medical-records',
            'actions' => [
                'view' => 'read',
                'upsert' => 'update',
                'add-procedure' => 'update',
                'cancel-procedure' => 'update',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/visits/<visitId>/medical-record
     */
    public function actionView(int $visitId): array
    {
        $visit = $this->findVisit($visitId);
        return [
            'success' => true,
            'data' => [
                'visit' => $visit,
                'medical_record' => $visit->medicalRecord,
                'procedures' => $visit->visitProcedures,
            ],
        ];
    }

    /**
     * POST/PUT /api/visits/<visitId>/medical-record
     * Creates or updates the visit's EMR with version tracking & audit trail (BR-05, BR-12).
     */
    public function actionUpsert(int $visitId): array
    {
        $visit = $this->findVisit($visitId);
        if ($visit->status === Visit::STATUS_CANCELLED) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Cannot record examination for a cancelled visit.',
            ];
        }

        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $record = MedicalRecord::findOne(['visit_id' => $visit->id]);
        $isNew = $record === null;
        if ($isNew && $actor && !$actor->hasModulePermission('medical-records', 'create')) {
            throw new ForbiddenHttpException('You do not have "create" permission for module "medical-records".');
        }
        $previousDiagnosis = $record?->primary_diagnosis;

        if ($isNew) {
            $record = new MedicalRecord();
            $record->visit_id = $visit->id;
            $record->patient_id = $visit->patient_id;
            $record->doctor_id = $visit->doctor_id;
            $record->version = 1;
            $record->created_by = $actor?->id;
        } else {
            $record->version = (int) $record->version + 1;
        }

        foreach (['chief_complaint', 'anamnesis', 'physical_exam', 'primary_diagnosis', 'secondary_diagnoses', 'clinical_notes'] as $field) {
            if (array_key_exists($field, $body)) {
                $record->$field = $body[$field];
            }
        }
        if (array_key_exists('vital_signs', $body)) {
            $record->vital_signs = $body['vital_signs'];
        }
        $record->updated_by = $actor?->id;

        if (!$record->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $record->getErrors(),
            ];
        }

        // Advance visit queue/status automatically during examination
        if ($visit->queue_status === Visit::QUEUE_WAITING || $visit->queue_status === Visit::QUEUE_CALLED) {
            $visit->queue_status = Visit::QUEUE_IN_EXAMINATION;
            $visit->status = Visit::STATUS_IN_PROGRESS;
            $visit->updated_by = $actor?->id;
            $visit->save(false, ['queue_status', 'status', 'updated_by', 'updated_at']);
        }

        $auditDesc = $isNew
            ? sprintf('Created EMR v1 for visit %s (Primary Dx: %s)', $visit->visit_number, $record->primary_diagnosis)
            : sprintf(
                'Updated EMR to v%d for visit %s (Primary Dx: "%s" -> "%s")',
                $record->version,
                $visit->visit_number,
                (string) $previousDiagnosis,
                $record->primary_diagnosis
            );

        UserLog::record($isNew ? 'create' : 'update', 'medical-records', $auditDesc, $actor, $record->id);

        Yii::$app->response->statusCode = $isNew ? 201 : 200;
        return [
            'success' => true,
            'message' => $isNew ? 'Medical record saved successfully.' : 'Medical record updated successfully.',
            'data' => $record,
        ];
    }

    /**
     * POST /api/visits/<visitId>/procedures
     * Adds a billable medical procedure with tariff snapshot from master `procedures` (FR-04, BR-06).
     */
    public function actionAddProcedure(int $visitId): array
    {
        $visit = $this->findVisit($visitId);
        if ($visit->status === Visit::STATUS_CANCELLED) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Cannot add procedures to a cancelled visit.',
            ];
        }

        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $procedureId = (int) ($body['procedure_id'] ?? 0);
        $procedure = Procedure::findOne(['id' => $procedureId, 'is_active' => true]);
        if (!$procedure) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Active procedure not found.',
            ];
        }

        $vp = new VisitProcedure();
        $vp->visit_id = $visit->id;
        $vp->procedure_id = $procedure->id;
        $vp->procedure_name_snapshot = $procedure->name;
        $vp->applied_tariff = (float) $procedure->tariff; // Snapshot from master (BR-06)
        $vp->quantity = max(1, (int) ($body['quantity'] ?? 1));
        $vp->notes = isset($body['notes']) ? trim((string) $body['notes']) : null;
        $vp->status = VisitProcedure::STATUS_ACTIVE;
        $vp->performed_by = $actor?->id;
        $vp->created_by = $actor?->id;
        $vp->updated_by = $actor?->id;

        if (!$vp->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $vp->getErrors(),
            ];
        }

        UserLog::record(
            'add_procedure',
            'medical-records',
            sprintf('Added procedure "%s" (qty=%d, tariff=%.2f) to visit %s', $vp->procedure_name_snapshot, $vp->quantity, (float) $vp->applied_tariff, $visit->visit_number),
            $actor,
            $vp->id
        );

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Procedure added to visit.',
            'data' => $vp,
        ];
    }

    /**
     * PATCH /api/visit-procedures/<id>/cancel
     * Cancels a visit procedure (status transition instead of hard delete, BR-16).
     */
    public function actionCancelProcedure(int $id): array
    {
        $vp = VisitProcedure::findOne($id);
        if (!$vp) {
            throw new NotFoundHttpException("Visit procedure with ID $id not found.");
        }

        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $vp->status = VisitProcedure::STATUS_CANCELLED;
        $vp->updated_by = $actor?->id;
        $vp->save(false, ['status', 'updated_by', 'updated_at']);

        UserLog::record(
            'cancel_procedure',
            'medical-records',
            sprintf('Cancelled procedure "%s" on visit_id=%d', $vp->procedure_name_snapshot, $vp->visit_id),
            $actor,
            $vp->id
        );

        return [
            'success' => true,
            'message' => 'Procedure cancelled.',
            'data' => $vp,
        ];
    }

    protected function findVisit(int $visitId): Visit
    {
        $visit = Visit::find()->with(['patient', 'department', 'doctor', 'medicalRecord', 'visitProcedures'])->where(['id' => $visitId])->one();
        if (!$visit) {
            throw new NotFoundHttpException("Visit with ID $visitId not found.");
        }
        return $visit;
    }
}
