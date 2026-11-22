<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\User;
use app\models\UserLog;

/**
 * UserController provides RESTful CRUD for `users` table with soft-delete & session invalidation support.
 */
class UserController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'users-list',
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
     * GET /api/users
     * Query params: search, role_id, is_active, include_deleted, page, per_page
     */
    public function actionIndex(): array
    {
        $request = Yii::$app->request;
        $query = User::find()->with('role');

        $includeDeleted = filter_var($request->get('include_deleted', false), FILTER_VALIDATE_BOOLEAN);
        if (!$includeDeleted) {
            $query->andWhere(['deleted_at' => null]);
        }

        $search = trim((string) $request->get('search', ''));
        if ($search !== '') {
            $query->andWhere([
                'or',
                ['ilike', 'email', $search],
                ['ilike', 'name', $search],
                ['ilike', 'phone', $search],
            ]);
        }

        $roleId = $request->get('role_id');
        if ($roleId !== null && $roleId !== '') {
            $query->andWhere(['role_id' => (int) $roleId]);
        }

        $isActive = $request->get('is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->andWhere(['is_active' => filter_var($isActive, FILTER_VALIDATE_BOOLEAN)]);
        }

        $total = (int) (clone $query)->count();
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', 20)));

        $users = $query
            ->orderBy(['id' => SORT_ASC])
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return [
            'success' => true,
            'data' => $users,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * GET /api/users/<id>
     */
    public function actionView(int $id): array
    {
        $user = $this->findModel($id);
        return [
            'success' => true,
            'data' => $user,
        ];
    }

    /**
     * POST /api/users
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $plainPassword = (string) ($body['password'] ?? '');
        if ($plainPassword === '' || strlen($plainPassword) < 6) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => [
                    'password' => ['Password is required and must be at least 6 characters.'],
                ],
            ];
        }

        if (!isset($body['role_id']) || $body['role_id'] === '' || $body['role_id'] === null) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => [
                    'role_id' => ['Role is required.'],
                ],
            ];
        }

        $user = new User();
        $user->email = trim((string) ($body['email'] ?? ''));
        $user->name = isset($body['name']) ? trim((string) $body['name']) : null;
        $user->phone = $body['phone'] ?? null;
        $user->address = $body['address'] ?? null;
        $user->role_id = (int) $body['role_id'];
        $user->is_active = array_key_exists('is_active', $body) ? (bool) $body['is_active'] : true;
        $user->avatar = $body['avatar'] ?? null;
        $user->theme = (string) ($body['theme'] ?? 'default');
        $user->sidebar_type = (string) ($body['sidebar_type'] ?? 'floating');
        $user->created_by = $actor?->id;
        $user->updated_by = $actor?->id;
        $user->setPassword($plainPassword);

        if (!$user->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $user->getErrors(),
            ];
        }

        $user->refresh();
        UserLog::record('create', 'users-list', sprintf('Created user %s (role_id=%d)', $user->email, (int) $user->role_id), $actor, $user->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'User created successfully.',
            'data' => $user,
        ];
    }

    /**
     * PUT/PATCH /api/users/<id>
     */
    public function actionUpdate(int $id): array
    {
        $user = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $oldRoleId = $user->role_id;
        $oldIsActive = (bool) $user->is_active;

        $updatable = ['email', 'name', 'phone', 'address', 'role_id', 'is_active', 'avatar', 'theme', 'sidebar_type'];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $body)) {
                if ($field === 'role_id') {
                    $user->role_id = $body[$field] !== null && $body[$field] !== '' ? (int) $body[$field] : null;
                } elseif ($field === 'is_active') {
                    $user->is_active = (bool) $body[$field];
                } else {
                    $user->$field = $body[$field];
                }
            }
        }

        if ($actor && $actor->id === $user->id && !$user->is_active) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'You cannot deactivate your own active account.',
            ];
        }

        $revokeSession = false;

        if (!empty($body['password'])) {
            $newPassword = (string) $body['password'];
            if (strlen($newPassword) < 6) {
                Yii::$app->response->statusCode = 422;
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Unprocessable Entity',
                    'message' => 'Validation failed.',
                    'errors' => [
                        'password' => ['Password must be at least 6 characters.'],
                    ],
                ];
            }
            $user->setPassword($newPassword);
            $revokeSession = true;
        }

        if ($oldRoleId !== $user->role_id || ($oldIsActive && !$user->is_active)) {
            $revokeSession = true;
        }

        if ($revokeSession) {
            $user->session_version = (int) $user->session_version + 1;
        }

        // Allow reactivating a previously soft-deleted user explicitly
        if ($user->deleted_at !== null && $user->is_active) {
            $user->deleted_at = null;
        }

        $user->updated_by = $actor?->id;

        if (!$user->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Validation failed.',
                'errors' => $user->getErrors(),
            ];
        }

        $user->refresh();
        UserLog::record('update', 'users-list', sprintf('Updated user %s', $user->email), $actor, $user->id);

        return [
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $user,
        ];
    }

    /**
     * DELETE /api/users/<id>
     * Always performs soft delete (deleted_at + is_active=false + session revocation) to preserve audit trail.
     */
    public function actionDelete(int $id): array
    {
        $user = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        if ($actor && $actor->id === $user->id) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'You cannot delete your own account.',
            ];
        }

        if ($user->deleted_at !== null) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => 'User is already deactivated/deleted.',
            ];
        }

        $user->deleted_at = date('Y-m-d H:i:s');
        $user->is_active = false;
        $user->session_version = (int) $user->session_version + 1;
        $user->updated_by = $actor?->id;
        $user->save(false, ['deleted_at', 'is_active', 'session_version', 'updated_by', 'updated_at']);

        UserLog::record('delete', 'users-list', sprintf('Soft deleted user %s', $user->email), $actor, $user->id);

        return [
            'success' => true,
            'message' => 'User deactivated (soft deleted) successfully.',
            'data' => $user,
        ];
    }

    protected function findModel(int $id): User
    {
        $model = User::find()->with('role')->where(['id' => $id])->one();
        if (!$model) {
            throw new NotFoundHttpException("User with ID $id not found.");
        }
        return $model;
    }
}
