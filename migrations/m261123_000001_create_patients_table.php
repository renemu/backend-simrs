<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `patients` table (FR-01.01, FR-01.02, BR-01).
 */
class m261123_000001_create_patients_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%patients}}', [
            'id' => $this->primaryKey(),
            'medical_record_number' => $this->string(32)->notNull()->unique(),
            'national_id' => $this->string(32)->null(),
            'full_name' => $this->string(191)->notNull(),
            'birth_date' => $this->date()->notNull(),
            'gender' => $this->string(16)->notNull(),
            'phone' => $this->string(32)->null(),
            'address' => $this->text()->null(),
            'blood_type' => $this->string(5)->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute("ALTER TABLE {{%patients}} ADD CONSTRAINT chk_patients_gender CHECK (gender IN ('male', 'female'))");
        $this->execute("CREATE UNIQUE INDEX uq_patients_national_id ON {{%patients}} (national_id) WHERE national_id IS NOT NULL AND national_id <> ''");
        $this->createIndex('idx-patients-full_name', '{{%patients}}', 'full_name');
        $this->createIndex('idx-patients-phone', '{{%patients}}', 'phone');
        $this->createIndex('idx-patients-is_active', '{{%patients}}', 'is_active');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%patients}}');
    }
}
