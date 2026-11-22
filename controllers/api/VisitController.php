<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;
use app\models\User;
use app\models\UserLog;
use app\models\Visit;

/**
 * VisitController handles outpatient registration, queue management, and visit history (FR-02).
 */
class VisitController extends BaseApiController
{
    private function currentUser(): User
    {
        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        if (!$user) {
            throw new UnauthorizedHttpException('Unauthenticated.');
        }
        return $user;
    }

    private function requireAnyPermission(array $pairs): User
    {
        $user = $this->currentUser();
        foreach ($pairs as [$moduleCode, $action]) {
            if ($user->hasModulePermission($moduleCode, $action)) {
                return $user;
            }
        }
        throw new ForbiddenHttpException('You do not have permission to access this visit operation.');
    }

    /**
     * GET /api/visits
     * Query: visit_date, department_id, doctor_id, patient_id, status, queue_status, search, page, per_page
     */
    public function actionIndex(): array
    {
        $this->requireAnyPermission([
            ['registrations', 'read'],
            ['queues', 'read'],
            ['medical-records', 'read'],
            ['billings', 'read'],
            ['prescriptions', 'read'],
            ['laboratory', 'read'],
        ]);

        $request = Yii::$app->request;
        $query = Visit::find()->with(['patient', 'department', 'doctor', 'schedule']);

        $visitDate = trim((string) $request->get('visit_date', ''));
        if ($visitDate !== '') {
            $query->andWhere(['visit_date' => $visitDate]);
        }

        $deptId = $request->get('department_id');
        if ($deptId !== null && $deptId !== '') {
            $query->andWhere(['department_id' => (int) $deptId]);
        }

        $doctorId = $request->get('doctor_id');
        if ($doctorId !== null && $doctorId !== '') {
            $query->andWhere(['doctor_id' => (int) $doctorId]);
        }

        $patientId = $request->get('patient_id');
        if ($patientId !== null && $patientId !== '') {
            $query->andWhere(['patient_id' => (int) $patientId]);
        }

        $status = trim((string) $request->get('status', ''));
        if ($status !== '') {
            $query->andWhere(['status' => $status]);
        }

        $queueStatus = trim((string) $request->get('queue_status', ''));
        if ($queueStatus !== '') {
            $query->andWhere(['queue_status' => $queueStatus]);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->joinWith(['patient p'], false);
            $query->andWhere([
                'or',
                ['ilike', '{{%visits}}.visit_number', $search],
                ['ilike', 'p.full_name', $search],
                ['ilike', 'p.medical_record_number', $search],
            ]);
        }

        $total = (int) (clone $query)->count();
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 20)));

        $visits = $query
            ->orderBy(['visit_date' => SORT_DESC, 'queue_number' => SORT_ASC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return [
            'success' => true,
            'data' => $visits,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * GET /api/visits/<id>
     * Returns visit detail with role-scoped clinical, procedure, prescription, and lab summaries.
     */
    public function actionView(int $id): array
    {
        $user = $this->requireAnyPermission([
            ['registrations', 'read'],
            ['queues', 'read'],
            ['medical-records', 'read'],
            ['billings', 'read'],
            ['prescriptions', 'read'],
            ['laboratory', 'read'],
        ]);

        $visit = Visit::find()
            ->with([
                'patient',
                'department',
                'doctor',
                'schedule',
                'medicalRecord',
                'visitProcedures',
                'prescriptions.items.medicine',
                'labOrders.result',
            ])
            ->where(['id' => $id])
            ->one();

        if (!$visit) {
            throw new NotFoundHttpException("Visit with ID $id not found.");
        }

        $data = $visit->toArray();

        // Enforce least privilege on sensitive clinical record (actors-and-roles.md)
        if ($user->hasModulePermission('medical-records', 'read')) {
            $data['medical_record'] = $visit->medicalRecord;
        }
        if ($user->hasModulePermission('medical-records', 'read') || $user->hasModulePermission('billings', 'read')) {
            $data['procedures'] = $visit->visitProcedures;
        }
        if ($user->hasModulePermission('prescriptions', 'read') || $user->hasModulePermission('billings', 'read')) {
            $data['prescriptions'] = $visit->prescriptions;
        }
        if ($user->hasModulePermission('laboratory', 'read') || $user->hasModulePermission('medical-records', 'read') || $user->hasModulePermission('billings', 'read')) {
            $data['lab_orders'] = $visit->labOrders;
        }

        return [
            'success' => true,
            'data' => $data,
        ];
    }

    /**
     * POST /api/visits
     * Body: { patient_id, department_id, doctor_id, schedule_id, visit_date, notes }
     */
    public function actionCreate(): array
    {
        $actor = $this->requireAnyPermission([['registrations', 'create']]);
        $body = $this->getBodyParams();

        $patientId = (int) ($body['patient_id'] ?? 0);
        $departmentId = (int) ($body['department_id'] ?? 0);
        $doctorId = (int) ($body['doctor_id'] ?? 0);
        $scheduleId = (int) ($body['schedule_id'] ?? 0);
        $visitDate = trim((string) ($body['visit_date'] ?? date('Y-m-d')));
        $notes = isset($body['notes']) ? (string) $body['notes'] : null;

        if ($patientId <= 0 || $departmentId <= 0 || $doctorId <= 0 || $scheduleId <= 0) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'patient_id, department_id, doctor_id, and schedule_id are required.',
            ];
        }

        $visit = Visit::registerAtomic($patientId, $departmentId, $doctorId, $scheduleId, $visitDate, $notes, $actor);

        UserLog::record(
            'create',
            'registrations',
            sprintf('Registered visit %s (Queue #%d) for patient_id=%d on %s', $visit->visit_number, $visit->queue_number, $visit->patient_id, $visit->visit_date),
            $actor,
            $visit->id
        );

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Visit registered and queue number generated successfully.',
            'data' => $visit,
        ];
    }

    /**
     * PATCH /api/visits/<id>/queue-status
     * Body: { queue_status: 'waiting'|'called'|'in_examination'|'completed'|'cancelled' }
     */
    public function actionQueueStatus(int $id): array
    {
        $actor = $this->requireAnyPermission([['queues', 'update']]);
        $visit = $this->findModel($id);
        $body = $this->getBodyParams();

        $nextQueueStatus = trim((string) ($body['queue_status'] ?? ''));
        $allowed = [
            Visit::QUEUE_WAITING,
            Visit::QUEUE_CALLED,
            Visit::QUEUE_IN_EXAMINATION,
            Visit::QUEUE_COMPLETED,
            Visit::QUEUE_CANCELLED,
        ];

        if (!in_array($nextQueueStatus, $allowed, true)) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Invalid queue_status value.',
            ];
        }

        if ($visit->status === Visit::STATUS_CANCELLED && $nextQueueStatus !== Visit::QUEUE_CANCELLED) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Cannot change queue status of a cancelled visit.',
            ];
        }

        $oldStatus = $visit->queue_status;
        $visit->queue_status = $nextQueueStatus;
        if (in_array($nextQueueStatus, [Visit::QUEUE_CALLED, Visit::QUEUE_IN_EXAMINATION], true)) {
            $visit->status = Visit::STATUS_IN_PROGRESS;
        } elseif ($nextQueueStatus === Visit::QUEUE_COMPLETED) {
            $visit->status = Visit::STATUS_COMPLETED;
        } elseif ($nextQueueStatus === Visit::QUEUE_CANCELLED) {
            $visit->status = Visit::STATUS_CANCELLED;
        }
        $visit->updated_by = $actor->id;
        $visit->save(false, ['queue_status', 'status', 'updated_by', 'updated_at']);

        UserLog::record(
            'update_queue',
            'queues',
            sprintf('Updated visit %s queue_status %s -> %s', $visit->visit_number, $oldStatus, $nextQueueStatus),
            $actor,
            $visit->id
        );

        return [
            'success' => true,
            'message' => 'Queue status updated successfully.',
            'data' => $visit,
        ];
    }

    /**
     * PATCH /api/visits/<id>/cancel
     */
    public function actionCancel(int $id): array
    {
        $actor = $this->requireAnyPermission([['registrations', 'update']]);
        $visit = $this->findModel($id);

        if ($visit->status === Visit::STATUS_COMPLETED) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'Completed visits cannot be cancelled.',
            ];
        }

        $visit->status = Visit::STATUS_CANCELLED;
        $visit->queue_status = Visit::QUEUE_CANCELLED;
        $visit->updated_by = $actor->id;
        $visit->save(false, ['status', 'queue_status', 'updated_by', 'updated_at']);

        UserLog::record('cancel', 'registrations', sprintf('Cancelled visit %s', $visit->visit_number), $actor, $visit->id);

        return [
            'success' => true,
            'message' => 'Visit cancelled successfully.',
            'data' => $visit,
        ];
    }

    protected function findModel(int $id): Visit
    {
        $model = Visit::find()->with(['patient', 'department', 'doctor', 'schedule'])->where(['id' => $id])->one();
        if (!$model) {
            throw new NotFoundHttpException("Visit with ID $id not found.");
        }
        return $model;
    }
}
