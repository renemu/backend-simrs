<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * MedicineStockMovement model matching table `medicine_stock_movements` (BR-07 audit trail).
 *
 * @property int $id
 * @property int $medicine_id
 * @property string $movement_type
 * @property int $quantity_delta
 * @property int $stock_before
 * @property int $stock_after
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $notes
 * @property int|null $actor_id
 * @property string $created_at
 *
 * @property-read Medicine|null $medicine
 * @property-read User|null $actor
 */
class MedicineStockMovement extends ActiveRecord
{
    public const TYPE_DISPENSE = 'dispense';
    public const TYPE_RESTOCK = 'restock';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public static function tableName(): string
    {
        return '{{%medicine_stock_movements}}';
    }

    public function getMedicine(): ActiveQuery
    {
        return $this->hasOne(Medicine::class, ['id' => 'medicine_id']);
    }

    public function getActor(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'actor_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'medicine_id',
            'movement_type',
            'quantity_delta' => fn(self $m) => (int) $m->quantity_delta,
            'stock_before' => fn(self $m) => (int) $m->stock_before,
            'stock_after' => fn(self $m) => (int) $m->stock_after,
            'reference_type',
            'reference_id',
            'notes',
            'medicine' => fn(self $m) => $m->medicine ? [
                'id' => $m->medicine->id,
                'code' => $m->medicine->code,
                'name' => $m->medicine->name,
                'unit' => $m->medicine->unit,
            ] : null,
            'actor' => fn(self $m) => $m->actor ? [
                'id' => $m->actor->id,
                'name' => $m->actor->name,
                'email' => $m->actor->email,
            ] : null,
            'created_at',
        ];
    }
}
