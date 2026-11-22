<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Department (Poliklinik / Unit Layanan) model matching table `departments`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property float|string $registration_fee
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 *
 * @property-read Doctor[] $doctors
 */
class Department extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%departments}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name'], 'required'],
            [['code'], 'string', 'max' => 32],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9_-]+$/', 'message' => 'Department code must contain uppercase letters, numbers, hyphens, or underscores.'],
            [['code'], 'unique'],
            [['name'], 'string', 'max' => 191],
            [['description'], 'string'],
            [['registration_fee'], 'number', 'min' => 0],
            [['registration_fee'], 'default', 'value' => 0],
            [['is_active'], 'boolean'],
            [['is_active'], 'default', 'value' => true],
            [['created_by', 'updated_by'], 'integer'],
            [['created_at', 'updated_at'], 'safe'],
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }

        if (is_string($this->code)) {
            $this->code = strtoupper(trim($this->code));
        }
        if (is_string($this->name)) {
            $this->name = trim($this->name);
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

        return true;
    }

    public function getDoctors(): ActiveQuery
    {
        return $this->hasMany(Doctor::class, ['department_id' => 'id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'code',
            'name',
            'registration_fee' => fn(self $m) => (float) $m->registration_fee,
            'description',
            'is_active' => fn(self $m) => (bool) $m->is_active,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
