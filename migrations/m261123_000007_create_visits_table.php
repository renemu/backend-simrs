<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `visits` table (FR-02, BR-02 duplicate visit prevention, BR-04 atomic queue number).
 */
class m261123_000007_create_visits_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%visits}}', [
            'id' => $this->primaryKey(),
            'visit_number' => $this->string(32)->notNull()->unique(),
            'patient_id' => $this->integer()->notNull(),
            'department_id' => $this->integer()->notNull(),
            'doctor_id' => $this->integer()->notNull(),
            'schedule_id' => $this->integer()->notNull(),
            'visit_date' => $this->date()->notNull(),
            'queue_number' => $this->integer()->notNull(),
            'queue_status' => $this->string(24)->notNull()->defaultValue('waiting'),
            'status' => $this->string(24)->notNull()->defaultValue('registered'),
            'registration_fee_snapshot' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'consultation_fee_snapshot' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'notes' => $this->text()->null(),
            'registered_by' => $this->integer()->null(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute("ALTER TABLE {{%visits}} ADD CONSTRAINT chk_visits_queue_number CHECK (queue_number > 0)");
        $this->execute("ALTER TABLE {{%visits}} ADD CONSTRAINT chk_visits_queue_status CHECK (queue_status IN ('waiting', 'called', 'in_examination', 'completed', 'cancelled'))");
        $this->execute("ALTER TABLE {{%visits}} ADD CONSTRAINT chk_visits_status CHECK (status IN ('registered', 'in_progress', 'completed', 'cancelled'))");
        $this->execute("ALTER TABLE {{%visits}} ADD CONSTRAINT chk_visits_fees CHECK (registration_fee_snapshot >= 0 AND consultation_fee_snapshot >= 0)");

        // BR-02: Prevent duplicate active registrations for same patient + department + date
        $this->execute(
            "CREATE UNIQUE INDEX uq_visits_active_patient_dept_date ON {{%visits}} (patient_id, department_id, visit_date) WHERE status <> 'cancelled'"
        );

        // BR-04: Guarantee unique queue numbers per department per date
        $this->createIndex(
            'uq-visits-dept_date_queue',
            '{{%visits}}',
            ['department_id', 'visit_date', 'queue_number'],
            true
        );

        $this->createIndex('idx-visits-visit_date', '{{%visits}}', 'visit_date');
        $this->createIndex('idx-visits-patient_date', '{{%visits}}', ['patient_id', 'visit_date']);
        $this->createIndex('idx-visits-doctor_date', '{{%visits}}', ['doctor_id', 'visit_date']);
        $this->createIndex('idx-visits-queue_status', '{{%visits}}', 'queue_status');

        $this->addForeignKey('fk-visits-patient_id', '{{%visits}}', 'patient_id', '{{%patients}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-visits-department_id', '{{%visits}}', 'department_id', '{{%departments}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-visits-doctor_id', '{{%visits}}', 'doctor_id', '{{%doctors}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-visits-schedule_id', '{{%visits}}', 'schedule_id', '{{%doctor_schedules}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-visits-registered_by', '{{%visits}}', 'registered_by', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%visits}}');
    }
}
