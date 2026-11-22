<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use app\components\JwtHelper;

/**
 * User model matching table `users`.
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property string|null $phone
 * @property string|null $address
 * @property int|null $role_id
 * @property string|null $password
 * @property bool $is_active
 * @property string|null $last_login_at
 * @property string|null $remember_token
 * @property string|null $avatar
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string $theme
 * @property string $sidebar_type
 * @property int $session_version
 * @property string|null $deleted_at
 *
 * @property-read Role|null $role
 */
class User extends ActiveRecord implements IdentityInterface
{
    public ?string $plain_password = null;

    public static function tableName(): string
    {
        return '{{%users}}';
    }

    public function rules(): array
    {
        return [
            [['email'], 'required'],
            [['email'], 'email'],
            [['email', 'name', 'phone', 'address', 'password', 'remember_token', 'avatar'], 'string', 'max' => 191],
            [['email'], 'unique'],
            [['theme'], 'string', 'max' => 32],
            [['theme'], 'default', 'value' => 'default'],
            [['sidebar_type'], 'string', 'max' => 16],
            [['sidebar_type'], 'default', 'value' => 'floating'],
            [['is_active'], 'boolean'],
            [['is_active'], 'default', 'value' => true],
            [['role_id', 'created_by', 'updated_by', 'session_version'], 'integer'],
            [['session_version'], 'default', 'value' => 0],
            [['last_login_at', 'created_at', 'updated_at', 'deleted_at', 'plain_password'], 'safe'],
            [
                ['role_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => Role::class,
                'targetAttribute' => ['role_id' => 'id'],
            ],
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if (is_string($this->email)) {
            $this->email = strtolower(trim($this->email));
        }

        return true;
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

        if (!empty($this->plain_password)) {
            $this->setPassword($this->plain_password);
        }

        return true;
    }

    public static function findIdentity($id): ?static
    {
        return static::find()
            ->where(['id' => $id, 'is_active' => true])
            ->andWhere(['deleted_at' => null])
            ->one();
    }

    public static function findIdentityByAccessToken($token, $type = null): ?static
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $payload = JwtHelper::verifyToken($token);
        if (!is_array($payload) || !isset($payload['sub'])) {
            return null;
        }

        $user = static::findIdentity((int) $payload['sub']);
        if ($user === null) {
            return null;
        }

        if (!isset($payload['sv']) || (int) $payload['sv'] !== (int) $user->session_version) {
            return null;
        }

        return $user;
    }

    /**
     * Finds active non-deleted user by email (or name/username fallback).
     */
    public static function findByEmail(string $email): ?static
    {
        return static::find()
            ->where(['LOWER(email)' => strtolower(trim($email))])
            ->andWhere(['deleted_at' => null])
            ->one();
    }

    public static function findByUsername(string $username): ?static
    {
        return static::findByEmail($username);
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getAuthKey(): ?string
    {
        return $this->remember_token;
    }

    public function validateAuthKey($authKey): bool
    {
        return $this->remember_token !== null && $this->remember_token === $authKey;
    }

    /**
     * Validates password against bcrypt hash ($2b$ or $2y$).
     */
    public function validatePassword(string $password): bool
    {
        if (empty($this->password)) {
            return false;
        }
        return password_verify($password, $this->password);
    }

    /**
     * Hashes and sets password using bcrypt ($2y$ / compatible with $2b$).
     */
    public function setPassword(string $password): void
    {
        $this->password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * Generates a signed JWT token for this user including role & session_version.
     */
    public function generateJwtToken(?int $ttl = null): string
    {
        return JwtHelper::generateToken([
            'sub' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'role_id' => $this->role_id,
            'role' => $this->role ? $this->role->name : null,
            'sv' => (int) $this->session_version,
        ], $ttl);
    }

    public function getRole(): ActiveQuery
    {
        return $this->hasOne(Role::class, ['id' => 'role_id']);
    }

    /**
     * Checks whether this user's role has permission for a module & action.
     */
    public function hasModulePermission(string $moduleCode, string $action = 'read'): bool
    {
        if (!$this->role) {
            return false;
        }
        return $this->role->checkPermission($moduleCode, $action);
    }

    /**
     * Returns full permission map and accessible module tree for this user.
     */
    public function getPermissionsPayload(): array
    {
        $role = $this->role;
        $rolePerms = $role ? $role->getDecodedPermissions() : [];
        $permByCode = [];
        foreach ($rolePerms as $perm) {
            if (!empty($perm['module_code'])) {
                $permByCode[$perm['module_code']] = [
                    'module_code' => $perm['module_code'],
                    'create' => (bool) ($perm['create'] ?? false),
                    'read' => (bool) ($perm['read'] ?? false),
                    'update' => (bool) ($perm['update'] ?? false),
                    'delete' => (bool) ($perm['delete'] ?? false),
                ];
            }
        }

        return array_values($permByCode);
    }

    public function fields(): array
    {
        return [
            'id',
            'email',
            'name',
            'phone',
            'address',
            'role_id',
            'is_active' => fn(self $u) => (bool) $u->is_active,
            'last_login_at',
            'avatar',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'theme',
            'sidebar_type',
            'session_version',
            'deleted_at',
            'role' => fn(self $u) => $u->role ? [
                'id' => $u->role->id,
                'name' => $u->role->name,
                'display_name' => $u->role->display_name,
                'module_permission' => $u->role->getDecodedPermissions(),
            ] : null,
        ];
    }
}
