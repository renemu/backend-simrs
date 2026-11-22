<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation of table `user_logs` (schema only).
 */
class m261122_000004_create_user_logs_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%user_logs}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->null(),
            'actor_name' => $this->string(191)->notNull(),
            'impersonator_id' => $this->integer()->null(),
            'action' => $this->string(40)->notNull(),
            'module' => $this->string(80)->notNull(),
            'target_id' => $this->integer()->null(),
            'description' => $this->text()->notNull(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'ip' => $this->string(45)->null(),
            'browser' => $this->string(191)->null(),
            'location' => $this->string(191)->null(),
        ]);

        $this->createIndex('idx-user_logs-user_created', '{{%user_logs}}', ['user_id', 'created_at']);
        $this->createIndex('idx-user_logs-module_created', '{{%user_logs}}', ['module', 'created_at']);
        $this->createIndex('idx-user_logs-created_id', '{{%user_logs}}', ['created_at', 'id']);
        $this->addForeignKey(
            'fk-user_logs-user_id',
            '{{%user_logs}}',
            'user_id',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-user_logs-impersonator_id',
            '{{%user_logs}}',
            'impersonator_id',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%user_logs}}');
    }
}
