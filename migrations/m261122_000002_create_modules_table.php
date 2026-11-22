<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation of table `modules` (schema only).
 */
class m261122_000002_create_modules_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%modules}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(191)->notNull(),
            'code' => $this->string(191)->null()->unique(),
            'icon' => $this->string(191)->null(),
            'create' => $this->boolean()->notNull()->defaultValue(false),
            'read' => $this->boolean()->notNull()->defaultValue(false),
            'update' => $this->boolean()->notNull()->defaultValue(false),
            'delete' => $this->boolean()->notNull()->defaultValue(false),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
            'parent_id' => $this->integer()->null(),
            'path' => $this->string(191)->null(),
            'sort_order' => $this->integer()->notNull()->defaultValue(0),
            'type' => $this->integer()->notNull()->defaultValue(0),
        ]);

        $this->createIndex('idx-modules-parent_id', '{{%modules}}', 'parent_id');
        $this->addForeignKey(
            'fk-modules-parent_id',
            '{{%modules}}',
            'parent_id',
            '{{%modules}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%modules}}');
    }
}
