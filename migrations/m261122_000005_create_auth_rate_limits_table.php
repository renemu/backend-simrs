<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation of table `auth_rate_limits` (schema only).
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
    }

    public function safeDown()
    {
        $this->dropTable('{{%auth_rate_limits}}');
    }
}
