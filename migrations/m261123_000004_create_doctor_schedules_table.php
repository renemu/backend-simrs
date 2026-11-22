<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `doctor_schedules` table (FR-01.05, BR-03).
 */
class m261123_000004_create_doctor_schedules_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%doctor_schedules}}', [
            'id' => $this->primaryKey(),
            'doctor_id' => $this->integer()->notNull(),
            'department_id' => $this->integer()->notNull(),
            'day_of_week' => $this->smallInteger()->notNull(),
            'start_time' => $this->time()->notNull(),
            'end_time' => $this->time()->notNull(),
            'max_quota' => $this->integer()->notNull()->defaultValue(30),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute('ALTER TABLE {{%doctor_schedules}} ADD CONSTRAINT chk_doctor_schedules_day CHECK (day_of_week BETWEEN 0 AND 6)');
        $this->execute('ALTER TABLE {{%doctor_schedules}} ADD CONSTRAINT chk_doctor_schedules_time CHECK (start_time < end_time)');
        $this->execute('ALTER TABLE {{%doctor_schedules}} ADD CONSTRAINT chk_doctor_schedules_quota CHECK (max_quota > 0)');

        $this->createIndex(
            'uq-doctor_schedules-slot',
            '{{%doctor_schedules}}',
            ['doctor_id', 'day_of_week', 'start_time', 'end_time'],
            true
        );
        $this->createIndex(
            'idx-doctor_schedules-dept_day_active',
            '{{%doctor_schedules}}',
            ['department_id', 'day_of_week', 'is_active']
        );
        $this->createIndex(
            'idx-doctor_schedules-doctor_day',
            '{{%doctor_schedules}}',
            ['doctor_id', 'day_of_week']
        );

        $this->addForeignKey(
            'fk-doctor_schedules-doctor_id',
            '{{%doctor_schedules}}',
            'doctor_id',
            '{{%doctors}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
        $this->addForeignKey(
            'fk-doctor_schedules-department_id',
            '{{%doctor_schedules}}',
            'department_id',
            '{{%departments}}',
            'id',
            'RESTRICT',
            'CASCADE'
        );
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%doctor_schedules}}');
    }
}
