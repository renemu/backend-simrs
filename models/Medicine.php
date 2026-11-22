<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;

/**
 * Medicine (Obat, Stok & Harga) model matching table `medicines`.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $unit
 * @property int $stock
 * @property int $min_stock
 * @property float|string $unit_price
 * @property bool $is_active
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 */
class Medicine extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%medicines}}';
    }

    public function rules(): array
    {
        return [
            [['code', 'name', 'unit', 'unit_price'], 'required'],
            [['code', 'unit'], 'string', 'max' => 32],
            [['code'], 'match', 'pattern' => '/^[A-Z0-9_-]+$/', 'message' => 'Medicine code must contain uppercase letters, numbers, hyphens, or underscores.'],
            [['code'], 'unique'],
            [['name'], 'string', 'max' => 191],
            [['stock', 'min_stock'], 'integer', 'min' => 0],
            [['stock'], 'default', 'value' => 0],
            [['min_stock'], 'default', 'value' => 10],
            [['unit_price'], 'number', 'min' => 0],
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
        if (is_string($this->unit)) {
            $this->unit = strtolower(trim($this->unit));
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
            'unit',
            'stock' => fn(self $m) => (int) $m->stock,
            'min_stock' => fn(self $m) => (int) $m->min_stock,
            'is_low_stock' => fn(self $m) => (int) $m->stock <= (int) $m->min_stock,
            'unit_price' => fn(self $m) => (float) $m->unit_price,
            'is_active' => fn(self $m) => (bool) $m->is_active,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ];
    }
}
