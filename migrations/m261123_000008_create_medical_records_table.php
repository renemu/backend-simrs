<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `medical_records` table (FR-03, BR-05).
 */
class m261123_000008_create_medical_records_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%medical_records}}', [
            'id' => $this->primaryKey(),
            'visit_id' => $this->integer()->notNull()->unique(),
            'patient_id' => $this->integer()->notNull(),
            'doctor_id' => $this->integer()->notNull(),
            'chief_complaint' => $this->text()->notNull(),
            'anamnesis' => $this->text()->null(),
            'vital_signs' => 'jsonb NULL',
            'physical_exam' => $this->text()->null(),
            'primary_diagnosis' => $this->string(255)->notNull(),
            'secondary_diagnoses' => $this->text()->null(),
            'clinical_notes' => $this->text()->null(),
            'version' => $this->integer()->notNull()->defaultValue(1),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->createIndex('idx-medical_records-patient_id', '{{%medical_records}}', 'patient_id');
        $this->createIndex('idx-medical_records-doctor_id', '{{%medical_records}}', 'doctor_id');

        $this->addForeignKey('fk-medical_records-visit_id', '{{%medical_records}}', 'visit_id', '{{%visits}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-medical_records-patient_id', '{{%medical_records}}', 'patient_id', '{{%patients}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-medical_records-doctor_id', '{{%medical_records}}', 'doctor_id', '{{%doctors}}', 'id', 'RESTRICT', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%medical_records}}');
    }
}
