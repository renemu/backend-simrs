<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use app\models\AuthRateLimit;
use app\models\LoginForm;
use app\models\Module;
use app\models\User;
use app\models\UserLog;

/**
 * AuthController handles user authentication, profile management, and role/module permissions.
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
        $identifier = strtolower(trim((string) ($body['email'] ?? $body['username'] ?? '')));
        $rateKey = AuthRateLimit::buildKey($identifier, Yii::$app->request->userIP);

        $retryAfter = AuthRateLimit::getRetryAfterSeconds($rateKey);
        if ($retryAfter > 0) {
            Yii::$app->response->statusCode = 429;
            Yii::$app->response->getHeaders()->set('Retry-After', (string) $retryAfter);
            return [
                'success' => false,
                'status' => 429,
                'error' => 'Too Many Requests',
                'message' => sprintf(
                    'Too many failed login attempts. Please try again in %d seconds.',
                    $retryAfter
                ),
            ];
        }

        $model = new LoginForm();
        $model->email = $identifier;
        $model->username = $identifier;
        $model->password = (string) ($body['password'] ?? '');
        $model->rememberMe = (bool) ($body['rememberMe'] ?? true);

        if (!$model->validate()) {
            AuthRateLimit::hit($rateKey);
            Yii::$app->response->statusCode = 401;
            return [
                'success' => false,
                'status' => 401,
                'error' => 'Unauthorized',
                'message' => 'Invalid email or password.',
                'errors' => $model->getErrors(),
            ];
        }

        AuthRateLimit::clear($rateKey);

        /** @var User $user */
        $user = $model->getUser();
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save(false, ['last_login_at', 'updated_at']);

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
     * Public self-registration is disabled for internal SIMRS security;
     * staff accounts must be provisioned by an Administrator via POST /api/users.
     */
    public function actionRegister(): array
    {
        Yii::$app->response->statusCode = 403;
        return [
            'success' => false,
            'status' => 403,
            'error' => 'Forbidden',
            'message' => 'Public self-registration is disabled. Please contact an Administrator to create a staff account.',
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
     * Increments session_version to revoke all active JWT tokens for this user.
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
     * Updates authenticated user's own profile, password, theme, or sidebar_type.
     * Changing password increments session_version and issues a fresh JWT token.
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

        $passwordChanged = false;
        if (!empty($body['password'])) {
            $newPassword = (string) $body['password'];
            if (strlen($newPassword) < 6) {
                Yii::$app->response->statusCode = 422;
                return [
                    'success' => false,
                    'status' => 422,
                    'error' => 'Unprocessable Entity',
                    'message' => 'Password must be at least 6 characters.',
                    'errors' => ['password' => ['Password must be at least 6 characters.']],
                ];
            }
            $user->setPassword($newPassword);
            $user->session_version = (int) $user->session_version + 1;
            $passwordChanged = true;
        }

        $user->updated_by = $user->id;
        if (!$user->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Unprocessable Entity',
                'message' => 'Failed to update profile.',
                'errors' => $user->getErrors(),
            ];
        }

        UserLog::record('update_profile', 'auth', sprintf('User %s updated profile', $user->email), $user, $user->id);

        $response = [
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $user,
        ];

        if ($passwordChanged) {
            $response['token'] = $user->generateJwtToken();
        }

        return $response;
    }

    /**
     * Builds hierarchical modules accessible to the user based on effective CRUD permissions.
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

        return $this->filterReadableTree($tree);
    }

    /**
     * Removes modules the user cannot read (keeping parent groups only if they have readable children).
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function filterReadableTree(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $children = !empty($node['children']) ? $this->filterReadableTree($node['children']) : [];
            $isGroup = (int) ($node['type'] ?? 0) === 0;
            $canRead = (bool) ($node['permissions']['read'] ?? false);

            if ($isGroup) {
                if (!empty($children)) {
                    $node['children'] = $children;
                    $result[] = $node;
                }
            } elseif ($canRead || !empty($children)) {
                $node['children'] = $children;
                $result[] = $node;
            }
        }

        return $result;
    }
}
