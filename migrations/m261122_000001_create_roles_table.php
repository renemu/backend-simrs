<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation of table `roles` (schema only).
 */
class m261122_000001_create_roles_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%roles}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(191)->notNull()->unique(),
            'display_name' => $this->string(191)->notNull(),
            'module_permission' => $this->text()->null(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%roles}}');
    }
}
