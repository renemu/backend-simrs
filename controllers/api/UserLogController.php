<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\UserLog;

/**
 * UserLogController exposes read-only audit trail records for privileged review.
 */
class UserLogController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'users-logs',
            'actions' => [
                'index' => 'read',
                'view' => 'read',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/user-logs
     * Query params: search, module, action, user_id, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = UserLog::find();

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'actor_name', $search],
                ['ilike', 'description', $search],
                ['ilike', 'module', $search],
                ['ilike', 'action', $search],
            ]);
        }

        $module = trim((string) $request->get('module', ''));
        if ($module !== '') {
            $query->andWhere(['module' => $module]);
        }

        $action = trim((string) $request->get('action', ''));
        if ($action !== '') {
            $query->andWhere(['action' => $action]);
        }

        $userId = $request->get('user_id');
        if ($userId !== null && $userId !== '') {
            $query->andWhere(['user_id' => (int) $userId]);
        }

        $total = (int) (clone $query)->count();
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 20)));

        $logs = $query
            ->orderBy(['created_at' => SORT_DESC, 'id' => SORT_DESC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return [
            'success' => true,
            'data' => $logs,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * GET /api/user-logs/<id>
     */
    public function actionView(int $id): array
    {
        $log = UserLog::findOne($id);
        if (!$log) {
            throw new NotFoundHttpException("UserLog with ID $id not found.");
        }

        return [
            'success' => true,
            'data' => $log,
        ];
    }
}
