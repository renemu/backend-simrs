<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Role model matching table `roles`.
 *
 * @property int $id
 * @property string $name
 * @property string $display_name
 * @property string|null $module_permission
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string|null $created_at
 * @property string|null $updated_at
 *
 * @property-read User[] $users
 */
class Role extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%roles}}';
    }

    public function rules(): array
    {
        return [
            [['name', 'display_name'], 'required'],
            [['name', 'display_name'], 'string', 'max' => 191],
            [['name'], 'unique'],
            [['module_permission'], 'safe'],
            [['created_by', 'updated_by'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeSave($insert): bool
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        if ($insert && empty($this->created_at)) {
            $this->created_at = $now;
        }
        $this->updated_at = $now;

        if (is_array($this->module_permission)) {
            $this->module_permission = json_encode(
                array_values($this->module_permission),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } elseif (is_string($this->module_permission) && $this->module_permission !== '') {
            // Validate JSON string
            $decoded = json_decode($this->module_permission, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->module_permission = json_encode(
                    array_values($decoded),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
            }
        }

        return true;
    }

    /**
     * Returns decoded module_permission as array of objects.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDecodedPermissions(): array
    {
        if (empty($this->module_permission)) {
            return [];
        }
        if (is_array($this->module_permission)) {
            return $this->module_permission;
        }
        $decoded = json_decode((string) $this->module_permission, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Checks if this role grants a specific CRUD action on a module code.
     */
    public function checkPermission(string $moduleCode, string $action = 'read'): bool
    {
        if ($this->name === 'superadmin') {
            // Check explicit permission first; fallback true for superadmin
            foreach ($this->getDecodedPermissions() as $perm) {
                if (($perm['module_code'] ?? '') === $moduleCode) {
                    return (bool) ($perm[$action] ?? false);
                }
            }
            return true;
        }

        foreach ($this->getDecodedPermissions() as $perm) {
            if (($perm['module_code'] ?? '') === $moduleCode) {
                return (bool) ($perm[$action] ?? false);
            }
        }

        return false;
    }

    public function getUsers(): ActiveQuery
    {
        return $this->hasMany(User::class, ['role_id' => 'id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'name',
            'display_name',
            'module_permission' => fn(self $model) => $model->getDecodedPermissions(),
            'module_permission_raw' => fn(self $model) => $model->module_permission,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
