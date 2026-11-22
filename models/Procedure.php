<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Procedure (Tindakan & Tarif Medis) model matching table `procedures`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property float|string $tariff
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 */
class Procedure extends ActiveRecord
{
    public const ALLOWED_CATEGORIES = ['consultation', 'action', 'laboratory', 'diagnostic'];

    public static function tableName(): string
    {
        return '{{%procedures}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name', 'tariff'], 'required'],
            [['code'], 'string', 'max' => 32],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9_-]+$/', 'message' => 'Procedure code must contain uppercase letters, numbers, hyphens, or underscores.'],
            [['code'], 'unique'],
            [['name'], 'string', 'max' => 191],
            [['category'], 'in', 'range' => self::ALLOWED_CATEGORIES],
            [['category'], 'default', 'value' => 'action'],
            [['tariff'], 'number', 'min' => 0],
            [['description'], 'string'],
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
        if (is_string($this->category)) {
            $this->category = strtolower(trim($this->category));
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

    public function fields(): array
    {
        return [
            'id',
            'code',
            'name',
            'category',
            'tariff' => fn(self $m) => (float) $m->tariff,
            'description',
            'is_active' => fn(self $m) => (bool) $m->is_active,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
