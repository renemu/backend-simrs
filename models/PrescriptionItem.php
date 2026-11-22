<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * PrescriptionItem model matching table `prescription_items`.
 *
 * @property int $id
 * @property int $prescription_id
 * @property int $medicine_id
 * @property string $medicine_name_snapshot
 * @property string $unit_snapshot
 * @property string $dosage
 * @property string $frequency
 * @property int $quantity
 * @property float|string $unit_price_snapshot
 * @property string|null $instructions
 *
 * @property-read Medicine|null $medicine
 */
class PrescriptionItem extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%prescription_items}}';
    }

    public function rules(): array
    {
        return [
            [['prescription_id', 'medicine_id', 'medicine_name_snapshot', 'unit_snapshot', 'dosage', 'frequency', 'quantity', 'unit_price_snapshot'], 'required'],
            [['prescription_id', 'medicine_id'], 'integer'],
            [['quantity'], 'integer', 'min' => 1],
            [['unit_price_snapshot'], 'number', 'min' => 0],
            [['medicine_name_snapshot'], 'string', 'max' => 191],
            [['unit_snapshot'], 'string', 'max' => 32],
            [['dosage', 'frequency'], 'string', 'max' => 100],
            [['instructions'], 'string'],
        ];
    }

    public function getMedicine(): ActiveQuery
    {
        return $this->hasOne(Medicine::class, ['id' => 'medicine_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'prescription_id',
            'medicine_id',
            'medicine_name_snapshot',
            'unit_snapshot',
            'dosage',
            'frequency',
            'quantity' => fn(self $m) => (int) $m->quantity,
            'unit_price_snapshot' => fn(self $m) => (float) $m->unit_price_snapshot,
            'line_total' => fn(self $m) => (float) $m->unit_price_snapshot * (int) $m->quantity,
            'instructions',
            'current_stock' => fn(self $m) => $m->medicine ? (int) $m->medicine->stock : null,
        ];
    }
}
