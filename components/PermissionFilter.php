<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\base\ActionFilter;
use yii\web\ForbiddenHttpException;
use yii\web\UnauthorizedHttpException;
use app\models\User;

/**
 * PermissionFilter checks if the authenticated user's role has permission
 * for a given module_code and action (create, read, update, delete).
 *
 * Example usage in controller behaviors():
 * [
 *     'class' => PermissionFilter::class,
 *     'moduleCode' => 'users-list',
 *     'actions' => [
 *         'index' => 'read',
 *         'view' => 'read',
 *         'create' => 'create',
 *         'update' => 'update',
 *         'delete' => 'delete',
 *     ],
 * ]
 */
class PermissionFilter extends ActionFilter
{
    public string $moduleCode = '';

    /**
     * @var array<string, string> mapping of action ID => permission type ('create'|'read'|'update'|'delete')
     */
    public array $actions = [];

    public function beforeAction($action): bool
    {
        $actionId = $action->id;
        if (!isset($this->actions[$actionId])) {
            return true;
        }

        /** @var User|null $user */
        $user = Yii::$app->user->identity;
        if (!$user) {
            throw new UnauthorizedHttpException('Unauthenticated.');
        }

        $permissionType = $this->actions[$actionId];
        if (!$user->hasModulePermission($this->moduleCode, $permissionType)) {
            throw new ForbiddenHttpException(
                sprintf('You do not have "%s" permission for module "%s".', $permissionType, $this->moduleCode)
            );
        }

        return true;
    }
}
