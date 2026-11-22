<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * VisitProcedure model matching table `visit_procedures` (FR-04, BR-06).
 *
 * @property int $id
 * @property int $visit_id
 * @property int $procedure_id
 * @property string $procedure_name_snapshot
 * @property int $quantity
 * @property float|string $applied_tariff
 * @property string|null $notes
 * @property string $status
 * @property int|null $performed_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string $created_at
 * @property string|null $updated_at
 */
class VisitProcedure extends ActiveRecord
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';

    public static function tableName(): string
    {
        return '{{%visit_procedures}}';
    }

    public function rules(): array
    {
        return [
            [['visit_id', 'procedure_id', 'procedure_name_snapshot', 'applied_tariff'], 'required'],
            [['visit_id', 'procedure_id', 'performed_by', 'created_by', 'updated_by'], 'integer'],
            [['quantity'], 'integer', 'min' => 1],
            [['quantity'], 'default', 'value' => 1],
            [['applied_tariff'], 'number', 'min' => 0],
            [['procedure_name_snapshot'], 'string', 'max' => 191],
            [['notes'], 'string'],
            [['status'], 'in', 'range' => [self::STATUS_ACTIVE, self::STATUS_CANCELLED]],
            [['status'], 'default', 'value' => self::STATUS_ACTIVE],
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
        return true;
    }

    public function getProcedure(): ActiveQuery
    {
        return $this->hasOne(Procedure::class, ['id' => 'procedure_id']);
    }

    public function fields(): array
    {
        return [
            'id',
            'visit_id',
            'procedure_id',
            'procedure_name_snapshot',
            'quantity' => fn(self $m) => (int) $m->quantity,
            'applied_tariff' => fn(self $m) => (float) $m->applied_tariff,
            'line_total' => fn(self $m) => (float) $m->applied_tariff * (int) $m->quantity,
            'notes',
            'status',
            'performed_by',
            'created_at',
            'updated_at',
        ];
    }
}
