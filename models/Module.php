<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Module model matching table `modules`.
 *
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property string|null $icon
 * @property bool $create
 * @property bool $read
 * @property bool $update
 * @property bool $delete
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property int|null $parent_id
 * @property string|null $path
 * @property int $sort_order
 * @property int $type
 *
 * @property-read Module|null $parent
 * @property-read Module[] $children
 */
class Module extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%modules}}';
    }

    public function rules(): array
    {
        return [
            [['name'], 'required'],
            [['name', 'code', 'icon', 'path'], 'string', 'max' => 191],
            [['code'], 'unique'],
            [['create', 'read', 'update', 'delete'], 'boolean'],
            [['create', 'read', 'update', 'delete'], 'default', 'value' => false],
            [['created_by', 'updated_by', 'parent_id', 'sort_order', 'type'], 'integer'],
            [['sort_order', 'type'], 'default', 'value' => 0],
            [['created_at', 'updated_at'], 'safe'],
            [
                ['parent_id'],
                'exist',
                'skipOnError' => true,
                'targetClass' => self::class,
                'targetAttribute' => ['parent_id' => 'id'],
            ],
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

    public function getParent(): ActiveQuery
    {
        return $this->hasOne(self::class, ['id' => 'parent_id']);
    }

    public function getChildren(): ActiveQuery
    {
        return $this->hasMany(self::class, ['parent_id' => 'id'])->orderBy(['sort_order' => SORT_ASC, 'id' => SORT_ASC]);
    }

    public function fields(): array
    {
        return [
            'id',
            'name',
            'code',
            'icon',
            'create' => fn(self $m) => (bool) $m->create,
            'read' => fn(self $m) => (bool) $m->read,
            'update' => fn(self $m) => (bool) $m->update,
            'delete' => fn(self $m) => (bool) $m->delete,
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'parent_id',
            'path',
            'sort_order',
            'type',
        ];
    }

    public function extraFields(): array
    {
        return ['parent', 'children'];
    }
}
