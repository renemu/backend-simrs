<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Module;
use app\models\Role;
use app\models\User;
use app\models\UserLog;

/**
 * RoleController provides RESTful CRUD for `roles` and their `module_permission` assignments.
 */
class RoleController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'users-roles',
            'actions' => [
                'index' => 'read',
                'view' => 'read',
                'create' => 'create',
                'update' => 'update',
                'permissions' => 'update',
                'delete' => 'delete',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/roles
     */
    public function actionIndex(): array
    {
        $roles = Role::find()->orderBy(['id' => SORT_ASC])->all();

        return [
            'success' => true,
            'data' => $roles,
        ];
    }

    /**
     * GET /api/roles/<id>
     */
    public function actionView(int $id): array
    {
        $role = $this->findModel($id);

        return [
            'success' => true,
            'data' => $role,
        ];
    }

    /**
     * POST /api/roles
     * Body: { "name": "doctor", "display_name": "Dokter", "module_permission": [...] }
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $role = new Role();
        $role->name = trim((string) ($body['name'] ?? ''));
        $role->display_name = trim((string) ($body['display_name'] ?? ''));
        $role->module_permission = $body['module_permission'] ?? $this->buildDefaultModulePermissions();
        $role->created_by = $actor?->id;
        $role->updated_by = $actor?->id;

        if (!$role->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $role->getErrors(),
            ];
        }

        UserLog::record('create', 'users-roles', sprintf('Created role %s', $role->name), $actor, $role->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => $role,
        ];
    }

    /**
     * PUT/PATCH /api/roles/<id>
     */
    public function actionUpdate(int $id): array
    {
        $role = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        if (array_key_exists('name', $body)) {
            $role->name = trim((string) $body['name']);
        }
        if (array_key_exists('display_name', $body)) {
            $role->display_name = trim((string) $body['display_name']);
        }
        if (array_key_exists('module_permission', $body)) {
            $role->module_permission = $body['module_permission'];
        }
        $role->updated_by = $actor?->id;

        if (!$role->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $role->getErrors(),
            ];
        }

        UserLog::record('update', 'users-roles', sprintf('Updated role %s', $role->name), $actor, $role->id);

        return [
            'success' => true,
            'message' => 'Role updated successfully.',
            'data' => $role,
        ];
    }

    /**
     * PUT /api/roles/<id>/permissions
     * Body: { "module_permission": [{"module_code": "dashboard", "create": false, "read": true, "update": false, "delete": false}, ...] }
     */
    public function actionPermissions(int $id): array
    {
        $role = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $permissions = $body['module_permission'] ?? $body['permissions'] ?? null;
        if ($permissions === null) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Field "module_permission" is required.',
            ];
        }

        $role->module_permission = $permissions;
        $role->updated_by = $actor?->id;

        if (!$role->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Failed to update role permissions.',
                'errors' => $role->getErrors(),
            ];
        }

        UserLog::record('update_permissions', 'users-roles', sprintf('Updated permissions for role %s', $role->name), $actor, $role->id);

        return [
            'success' => true,
            'message' => 'Role permissions updated successfully.',
            'data' => $role,
        ];
    }

    /**
     * DELETE /api/roles/<id>
     */
    public function actionDelete(int $id): array
    {
        $role = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $name = $role->name;
        $role->delete();

        UserLog::record('delete', 'users-roles', sprintf('Deleted role %s', $name), $actor, $id);

        return [
            'success' => true,
            'message' => 'Role deleted successfully.',
        ];
    }

    protected function findModel(int $id): Role
    {
        $model = Role::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException("Role with ID $id not found.");
        }
        return $model;
    }

    private function buildDefaultModulePermissions(): array
    {
        $modules = Module::find()->orderBy(['id' => SORT_ASC])->all();
        $list = [];
        foreach ($modules as $mod) {
            if ($mod->code) {
                $list[] = [
                    'module_code' => $mod->code,
                    'create' => false,
                    'read' => false,
                    'update' => false,
                    'delete' => false,
                ];
            }
        }
        return $list;
    }
}
