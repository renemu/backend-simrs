<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation and seeding of table `auth_rate_limits`.
 */
class m261122_000005_create_auth_rate_limits_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%auth_rate_limits}}', [
            'key' => $this->string(64)->notNull(),
            'attempts' => $this->integer()->notNull()->defaultValue(1),
            'expires_at' => $this->timestamp(3)->notNull(),
        ]);

        $this->addPrimaryKey('pk-auth_rate_limits', '{{%auth_rate_limits}}', 'key');
        $this->createIndex('idx-auth_rate_limits-expires_at', '{{%auth_rate_limits}}', 'expires_at');

        // Seeder for auth_rate_limits
        $this->batchInsert('{{%auth_rate_limits}}', [
            'key', 'attempts', 'expires_at'
        ], [
            ['login:127.0.0.1', 1, '2026-11-22 22:00:00.000'],
        ]);
    }

    public function safeDown()
    {
        $this->dropTable('{{%auth_rate_limits}}');
    }
}
