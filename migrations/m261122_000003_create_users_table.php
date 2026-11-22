<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation of table `users` (schema only).
 */
class m261122_000003_create_users_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%users}}', [
            'id' => $this->primaryKey(),
            'email' => $this->string(191)->notNull()->unique(),
            'name' => $this->string(191)->null(),
            'phone' => $this->string(191)->null(),
            'address' => $this->string(191)->null(),
            'role_id' => $this->integer()->null(),
            'password' => $this->string(191)->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'last_login_at' => $this->timestamp(3)->null(),
            'remember_token' => $this->string(191)->null(),
            'avatar' => $this->string(191)->null(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
            'theme' => $this->string(32)->notNull()->defaultValue('default'),
            'sidebar_type' => $this->string(16)->notNull()->defaultValue('floating'),
            'session_version' => $this->integer()->notNull()->defaultValue(0),
            'deleted_at' => $this->timestamp(3)->null(),
        ]);

        $this->createIndex('idx-users-role_id', '{{%users}}', 'role_id');
        $this->createIndex('idx-users-deleted_at', '{{%users}}', 'deleted_at');
        $this->addForeignKey(
            'fk-users-role_id',
            '{{%users}}',
            'role_id',
            '{{%roles}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
    }

    public function safeDown()
    {
        $this->dropTable('{{%users}}');
    }
}
