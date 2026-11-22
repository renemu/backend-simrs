<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use app\models\LoginForm;
use app\models\Module;
use app\models\Role;
use app\models\User;
use app\models\UserLog;

/**
 * AuthController handles user authentication, registration, profile, and role/module permissions.
 */
class AuthController extends BaseApiController
{
    protected function authOptionalActions(): array
    {
        return ['login', 'register'];
    }

    /**
     * POST /api/auth/login
     * Body: { "email": "admin@example.com", "password": "admin123" }
     */
    public function actionLogin(): array
    {
        $body = $this->getBodyParams();

        $model = new LoginForm();
        $model->email = (string) ($body['email'] ?? $body['username'] ?? '');
        $model->username = $model->email;
        $model->password = (string) ($body['password'] ?? '');
        $model->rememberMe = (bool) ($body['rememberMe'] ?? true);

        if (!$model->validate()) {
            Yii::$app->response->statusCode = 401;
            return [
                'success' => false,
                'message' => 'Invalid email or password.',
                'errors' => $model->getErrors(),
            ];
        }

        /** @var User $user */
        $user = $model->getUser();
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->remember_token = Yii::$app->security->generateRandomString(64);
        $user->save(false, ['last_login_at', 'remember_token', 'updated_at']);

        $token = $user->generateJwtToken();

        UserLog::record('login', 'auth', sprintf('User %s logged in', $user->email), $user, $user->id);

        return [
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'permissions' => $user->getPermissionsPayload(),
                'menus' => $this->buildAccessibleModules($user),
            ],
        ];
    }

    /**
     * POST /api/auth/register
     * Body: { "email": "...", "name": "...", "password": "...", "phone": "...", "address": "..." }
     */
    public function actionRegister(): array
    {
        $body = $this->getBodyParams();

        $defaultRole = Role::findOne(['name' => 'user']);

        $user = new User();
        $user->email = trim((string) ($body['email'] ?? ''));
        $user->name = $body['name'] ?? null;
        $user->phone = $body['phone'] ?? null;
        $user->address = $body['address'] ?? null;
        $user->role_id = isset($body['role_id']) ? (int) $body['role_id'] : ($defaultRole?->id ?? 2);
        $user->is_active = true;
        $user->theme = (string) ($body['theme'] ?? 'default');
        $user->sidebar_type = (string) ($body['sidebar_type'] ?? 'floating');

        $plainPassword = (string) ($body['password'] ?? '');
        if ($plainPassword === '' || strlen($plainPassword) < 6) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Password must be at least 6 characters.',
                'errors' => ['password' => ['Password must be at least 6 characters.']],
            ];
        }

        $user->setPassword($plainPassword);
        $user->remember_token = Yii::$app->security->generateRandomString(64);
        $user->last_login_at = date('Y-m-d H:i:s');

        if (!$user->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $user->getErrors(),
            ];
        }

        $user->refresh();
        $token = $user->generateJwtToken();

        UserLog::record('register', 'auth', sprintf('User %s registered', $user->email), $user, $user->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Registration successful.',
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'permissions' => $user->getPermissionsPayload(),
                'menus' => $this->buildAccessibleModules($user),
            ],
        ];
    }

    /**
     * GET /api/auth/me
     */
    public function actionMe(): array
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;

        return [
            'success' => true,
            'data' => [
                'user' => $user,
                'role' => $user->role,
                'permissions' => $user->getPermissionsPayload(),
                'menus' => $this->buildAccessibleModules($user),
            ],
        ];
    }

    /**
     * GET /api/auth/permissions
     * Optional query: ?module_code=users-list&action=read
     */
    public function actionPermissions(): array
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;
        $moduleCode = Yii::$app->request->get('module_code');
        $action = (string) Yii::$app->request->get('action', 'read');

        $result = [
            'role' => $user->role ? [
                'id' => $user->role->id,
                'name' => $user->role->name,
                'display_name' => $user->role->display_name,
            ] : null,
            'permissions' => $user->getPermissionsPayload(),
            'menus' => $this->buildAccessibleModules($user),
        ];

        if (!empty($moduleCode)) {
            $result['check'] = [
                'module_code' => $moduleCode,
                'action' => $action,
                'allowed' => $user->hasModulePermission((string) $moduleCode, $action),
            ];
        }

        return [
            'success' => true,
            'data' => $result,
        ];
    }

    /**
     * POST /api/auth/logout
     * Increments session_version and clears remember_token to invalidate existing tokens.
     */
    public function actionLogout(): array
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;
        $user->session_version = (int) $user->session_version + 1;
        $user->remember_token = null;
        $user->save(false, ['session_version', 'remember_token', 'updated_at']);

        UserLog::record('logout', 'auth', sprintf('User %s logged out', $user->email), $user, $user->id);

        return [
            'success' => true,
            'message' => 'Logged out successfully.',
        ];
    }

    /**
     * PUT/PATCH /api/auth/profile
     * Updates authenticated user's profile, password, theme, or sidebar_type.
     */
    public function actionProfile(): array
    {
        /** @var User $user */
        $user = Yii::$app->user->identity;
        $body = $this->getBodyParams();

        foreach (['name', 'phone', 'address', 'avatar', 'theme', 'sidebar_type'] as $attr) {
            if (array_key_exists($attr, $body)) {
                $user->$attr = $body[$attr];
            }
        }

        if (!empty($body['password'])) {
            $newPassword = (string) $body['password'];
            if (strlen($newPassword) < 6) {
                Yii::$app->response->statusCode = 422;
                return [
                    'success' => false,
                    'message' => 'Password must be at least 6 characters.',
                ];
            }
            $user->setPassword($newPassword);
        }

        $user->updated_by = $user->id;
        if (!$user->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Failed to update profile.',
                'errors' => $user->getErrors(),
            ];
        }

        UserLog::record('update_profile', 'auth', sprintf('User %s updated profile', $user->email), $user, $user->id);

        return [
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $user,
        ];
    }

    /**
     * Builds hierarchical modules enriched with the user's effective CRUD permissions.
     */
    private function buildAccessibleModules(User $user): array
    {
        $modules = Module::find()
            ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $permMap = [];
        foreach ($user->getPermissionsPayload() as $p) {
            $permMap[$p['module_code']] = $p;
        }

        $items = [];
        foreach ($modules as $mod) {
            $code = (string) $mod->code;
            $effective = $permMap[$code] ?? [
                'module_code' => $code,
                'create' => $user->hasModulePermission($code, 'create'),
                'read' => $user->hasModulePermission($code, 'read'),
                'update' => $user->hasModulePermission($code, 'update'),
                'delete' => $user->hasModulePermission($code, 'delete'),
            ];

            $items[$mod->id] = [
                'id' => $mod->id,
                'name' => $mod->name,
                'code' => $mod->code,
                'icon' => $mod->icon,
                'path' => $mod->path,
                'parent_id' => $mod->parent_id,
                'sort_order' => $mod->sort_order,
                'type' => $mod->type,
                'permissions' => [
                    'create' => (bool) $effective['create'],
                    'read' => (bool) $effective['read'],
                    'update' => (bool) $effective['update'],
                    'delete' => (bool) $effective['delete'],
                ],
                'children' => [],
            ];
        }

        $tree = [];
        foreach ($items as $id => &$item) {
            $parentId = $item['parent_id'];
            if ($parentId !== null && isset($items[$parentId])) {
                $items[$parentId]['children'][] = &$item;
            } else {
                $tree[] = &$item;
            }
        }
        unset($item);

        return $tree;
    }
}
