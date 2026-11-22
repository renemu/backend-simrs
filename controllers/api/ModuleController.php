<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\web\NotFoundHttpException;
use app\components\PermissionFilter;
use app\models\Module;
use app\models\User;
use app\models\UserLog;

/**
 * ModuleController provides RESTful CRUD and tree view for `modules` table.
 */
class ModuleController extends BaseApiController
{
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        $behaviors['permission'] = [
            'class' => PermissionFilter::class,
            'moduleCode' => 'settings-modules',
            'actions' => [
                'index' => 'read',
                'tree' => 'read',
                'view' => 'read',
                'create' => 'create',
                'update' => 'update',
                'delete' => 'delete',
            ],
        ];

        return $behaviors;
    }

    /**
     * GET /api/modules
     */
    public function actionIndex(): array
    {
        $modules = Module::find()
            ->orderBy(['parent_id' => SORT_ASC, 'sort_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return [
            'success' => true,
            'data' => $modules,
        ];
    }

    /**
     * GET /api/modules/tree
     */
    public function actionTree(): array
    {
        $modules = Module::find()
            ->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        $map = [];
        foreach ($modules as $mod) {
            $map[$mod->id] = array_merge($mod->toArray(), ['children' => []]);
        }

        $tree = [];
        foreach ($map as $id => &$node) {
            if ($node['parent_id'] !== null && isset($map[$node['parent_id']])) {
                $map[$node['parent_id']]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }
        unset($node);

        return [
            'success' => true,
            'data' => $tree,
        ];
    }

    /**
     * GET /api/modules/<id>
     */
    public function actionView(int $id): array
    {
        $module = $this->findModel($id);

        return [
            'success' => true,
            'data' => array_merge($module->toArray(), [
                'children' => $module->children,
            ]),
        ];
    }

    /**
     * POST /api/modules
     */
    public function actionCreate(): array
    {
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $module = new Module();
        $this->populateModule($module, $body);
        $module->created_by = $actor?->id;
        $module->updated_by = $actor?->id;

        if (!$module->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $module->getErrors(),
            ];
        }

        UserLog::record('create', 'settings-modules', sprintf('Created module %s', $module->name), $actor, $module->id);

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'message' => 'Module created successfully.',
            'data' => $module,
        ];
    }

    /**
     * PUT/PATCH /api/modules/<id>
     */
    public function actionUpdate(int $id): array
    {
        $module = $this->findModel($id);
        $body = $this->getBodyParams();
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $this->populateModule($module, $body);
        $module->updated_by = $actor?->id;

        if (!$module->save()) {
            Yii::$app->response->statusCode = 422;
            return [
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $module->getErrors(),
            ];
        }

        UserLog::record('update', 'settings-modules', sprintf('Updated module %s', $module->name), $actor, $module->id);

        return [
            'success' => true,
            'message' => 'Module updated successfully.',
            'data' => $module,
        ];
    }

    /**
     * DELETE /api/modules/<id>
     */
    public function actionDelete(int $id): array
    {
        $module = $this->findModel($id);
        /** @var User|null $actor */
        $actor = Yii::$app->user->identity;

        $childCount = (int) Module::find()->where(['parent_id' => $module->id])->count();
        if ($childCount > 0) {
            Yii::$app->response->statusCode = 409;
            return [
                'success' => false,
                'status' => 409,
                'error' => 'Conflict',
                'message' => sprintf('Module "%s" still has %d child module(s). Move or delete children first.', $module->name, $childCount),
            ];
        }

        $name = $module->name;
        $module->delete();

        UserLog::record('delete', 'settings-modules', sprintf('Deleted module %s', $name), $actor, $id);

        return [
            'success' => true,
            'message' => 'Module deleted successfully.',
        ];
    }

    protected function findModel(int $id): Module
    {
        $model = Module::findOne($id);
        if (!$model) {
            throw new NotFoundHttpException("Module with ID $id not found.");
        }
        return $model;
    }

    private function populateModule(Module $module, array $body): void
    {
        $stringFields = ['name', 'code', 'icon', 'path'];
        foreach ($stringFields as $field) {
            if (array_key_exists($field, $body)) {
                $module->$field = $body[$field];
            }
        }

        $boolFields = ['create', 'read', 'update', 'delete'];
        foreach ($boolFields as $field) {
            if (array_key_exists($field, $body)) {
                $module->$field = (bool) $body[$field];
            }
        }

        $intFields = ['parent_id', 'sort_order', 'type'];
        foreach ($intFields as $field) {
            if (array_key_exists($field, $body)) {
                $module->$field = $body[$field] !== null ? (int) $body[$field] : null;
            }
        }
    }
}
