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
    /**
     * Core SIMRS roles defined in docs/01-analysis/actors-and-roles.md.
     */
    public const CORE_ROLES = [
        'admin',
        'registration',
        'doctor',
        'pharmacy',
        'laboratory',
        'cashier',
    ];

    public static function tableName(): string
    {
        return '{{%roles}}';
    }

    public function rules(): array
    {
        return [
            [['name', 'display_name'], 'required'],
            [['name', 'display_name'], 'string', 'max' => 191],
            [['name'], 'match', 'pattern' => '/^[a-z0-9_-]+$/', 'message' => 'Role name must use lowercase letters, numbers, hyphens, or underscores.'],
            [['name'], 'unique'],
            [['module_permission'], 'validateModulePermission'],
            [['created_by', 'updated_by'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if (is_string($this->name)) {
            $this->name = strtolower(trim($this->name));
        }
        if (is_string($this->display_name)) {
            $this->display_name = trim($this->display_name);
        }

        return true;
    }

    public function validateModulePermission(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === null || $value === '') {
            return;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $this->addError($attribute, 'module_permission must be a valid JSON array.');
                return;
            }
            $value = $decoded;
        }

        if (!is_array($value)) {
            $this->addError($attribute, 'module_permission must be an array.');
            return;
        }

        foreach ($value as $index => $item) {
            if (!is_array($item) || empty($item['module_code']) || !is_string($item['module_code'])) {
                $this->addError($attribute, sprintf('Permission entry at index %d must contain a valid module_code.', $index));
                return;
            }
        }
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

        $normalized = $this->normalizePermissions($this->module_permission);
        if ($normalized !== null) {
            $this->module_permission = (string) json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return true;
    }

    /**
     * Normalizes module_permission into a deduplicated array of CRUD entries.
     *
     * @param mixed $raw
     * @return array<int, array{module_code: string, create: bool, read: bool, update: bool, delete: bool}>|null
     */
    private function normalizePermissions(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return null;
            }
            $raw = $decoded;
        }

        if (!is_array($raw)) {
            return null;
        }

        $byCode = [];
        foreach ($raw as $item) {
            if (!is_array($item) || empty($item['module_code'])) {
                continue;
            }
            $code = trim((string) $item['module_code']);
            if ($code === '') {
                continue;
            }
            $byCode[$code] = [
                'module_code' => $code,
                'create' => (bool) ($item['create'] ?? false),
                'read' => (bool) ($item['read'] ?? false),
                'update' => (bool) ($item['update'] ?? false),
                'delete' => (bool) ($item['delete'] ?? false),
            ];
        }

        return array_values($byCode);
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
     * Checks if this role explicitly grants a specific CRUD action on a module code.
     * Enforces least privilege without implicit wildcard bypass.
     */
    public function checkPermission(string $moduleCode, string $action = 'read'): bool
    {
        if (!in_array($action, ['create', 'read', 'update', 'delete'], true)) {
            return false;
        }

        foreach ($this->getDecodedPermissions() as $perm) {
            if (($perm['module_code'] ?? '') === $moduleCode) {
                return (bool) ($perm[$action] ?? false);
            }
        }

        return false;
    }

    public function isCoreRole(): bool
    {
        return in_array($this->name, self::CORE_ROLES, true);
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
            'is_core' => fn(self $model) => $model->isCoreRole(),
            'module_permission' => fn(self $model) => $model->getDecodedPermissions(),
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
