<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `doctors` table (FR-01.03).
 */
class m261123_000003_create_doctors_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%doctors}}', [
            'id' => $this->primaryKey(),
            'user_id' => $this->integer()->null()->unique(),
            'department_id' => $this->integer()->notNull(),
            'sip_number' => $this->string(64)->notNull()->unique(),
            'full_name' => $this->string(191)->notNull(),
            'specialization' => $this->string(191)->null(),
            'phone' => $this->string(32)->null(),
            'consultation_fee' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute('ALTER TABLE {{%doctors}} ADD CONSTRAINT chk_doctors_consultation_fee CHECK (consultation_fee >= 0)');
        $this->createIndex('idx-doctors-department_id', '{{%doctors}}', 'department_id');
        $this->createIndex('idx-doctors-is_active', '{{%doctors}}', 'is_active');
        $this->createIndex('idx-doctors-full_name', '{{%doctors}}', 'full_name');

        $this->addForeignKey(
            'fk-doctors-user_id',
            '{{%doctors}}',
            'user_id',
            '{{%users}}',
            'id',
            'SET NULL',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-doctors-department_id',
            '{{%doctors}}',
            'department_id',
            '{{%departments}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%doctors}}');
    }
}
