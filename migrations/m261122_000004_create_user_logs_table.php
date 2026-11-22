<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation and seeding of table `user_logs`.
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

        // Seeder for user_logs
        $this->batchInsert('{{%user_logs}}', [
            'id', 'user_id', 'actor_name', 'impersonator_id', 'action', 'module', 'target_id', 'description', 'created_at', 'ip', 'browser', 'location'
        ], [
            [1, 1, 'Admin test', null, 'login', 'auth', 1, 'User admin@example.com logged in', '2026-10-28 01:44:48.017', '127.0.0.1', 'Mozilla/5.0', 'Local'],
            [2, 4, 'Super Admin', null, 'login', 'auth', 4, 'User superadmin@example.com logged in', '2026-11-02 01:52:13.752', '127.0.0.1', 'Mozilla/5.0', 'Local'],
        ]);

        $this->execute("SELECT setval(pg_get_serial_sequence('user_logs', 'id'), COALESCE((SELECT MAX(id) FROM user_logs), 1));");
    }

    public function safeDown()
    {
        $this->dropTable('{{%user_logs}}');
    }
}
