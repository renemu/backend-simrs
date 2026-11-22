<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `departments` (poliklinik / service units) table (FR-01.04).
 */
class m261123_000002_create_departments_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%departments}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(32)->notNull()->unique(),
            'name' => $this->string(191)->notNull(),
            'registration_fee' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'description' => $this->text()->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute('ALTER TABLE {{%departments}} ADD CONSTRAINT chk_departments_registration_fee CHECK (registration_fee >= 0)');
        $this->createIndex('idx-departments-is_active', '{{%departments}}', 'is_active');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%departments}}');
    }
}
