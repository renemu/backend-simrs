<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * InvoiceItem model matching table `invoice_items` (BR-09).
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $item_type
 * @property int|null $reference_id
 * @property string $description
 * @property int $quantity
 * @property float|string $unit_price
 * @property float|string $line_total
 */
class InvoiceItem extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%invoice_items}}';
    }

    public function rules(): array
    {
        return [
            [['invoice_id', 'item_type', 'description', 'quantity', 'unit_price', 'line_total'], 'required'],
            [['invoice_id', 'reference_id', 'quantity'], 'integer'],
            [['quantity'], 'integer', 'min' => 1],
            [['unit_price', 'line_total'], 'number', 'min' => 0],
            [['item_type'], 'in', 'range' => ['registration', 'consultation', 'procedure', 'laboratory', 'medicine']],
            [['description'], 'string', 'max' => 255],
        ];
    }

    public function getInvoice(): ActiveQuery
    {
        return $this->hasOne(Invoice::class, ['id' => 'invoice_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'invoice_id',
            'item_type',
            'reference_id',
            'description',
            'quantity' => fn(self $m) => (int) $m->quantity,
            'unit_price' => fn(self $m) => (float) $m->unit_price,
            'line_total' => fn(self $m) => (float) $m->line_total,
        ];
    }
}
