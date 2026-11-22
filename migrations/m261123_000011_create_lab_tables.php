<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `lab_orders` and `lab_results` tables (FR-06, BR-08).
 */
class m261123_000011_create_lab_tables extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%lab_orders}}', [
            'id' => $this->primaryKey(),
            'order_number' => $this->string(32)->notNull()->unique(),
            'visit_id' => $this->integer()->notNull(),
            'patient_id' => $this->integer()->notNull(),
            'procedure_id' => $this->integer()->null(),
            'test_name' => $this->string(191)->notNull(),
            'tariff_snapshot' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'status' => $this->string(24)->notNull()->defaultValue('requested'),
            'integration_status' => $this->string(24)->notNull()->defaultValue('local_only'),
            'correlation_id' => $this->string(64)->null(),
            'notes' => $this->text()->null(),
            'requested_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute("ALTER TABLE {{%lab_orders}} ADD CONSTRAINT chk_lab_orders_tariff CHECK (tariff_snapshot >= 0)");
        $this->execute("ALTER TABLE {{%lab_orders}} ADD CONSTRAINT chk_lab_orders_status CHECK (status IN ('requested', 'in_progress', 'completed', 'cancelled'))");
        $this->execute("ALTER TABLE {{%lab_orders}} ADD CONSTRAINT chk_lab_orders_integration CHECK (integration_status IN ('local_only', 'synced', 'failed'))");

        $this->createIndex('idx-lab_orders-visit_id', '{{%lab_orders}}', 'visit_id');
        $this->createIndex('idx-lab_orders-patient_id', '{{%lab_orders}}', 'patient_id');
        $this->createIndex('idx-lab_orders-status', '{{%lab_orders}}', 'status');

        $this->addForeignKey('fk-lab_orders-visit_id', '{{%lab_orders}}', 'visit_id', '{{%visits}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-lab_orders-patient_id', '{{%lab_orders}}', 'patient_id', '{{%patients}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-lab_orders-procedure_id', '{{%lab_orders}}', 'procedure_id', '{{%procedures}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk-lab_orders-requested_by', '{{%lab_orders}}', 'requested_by', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%lab_results}}', [
            'id' => $this->primaryKey(),
            'lab_order_id' => $this->integer()->notNull()->unique(),
            'result_summary' => $this->text()->notNull(),
            'result_details' => 'jsonb NULL',
            'reference_notes' => $this->text()->null(),
            'recorded_by' => $this->integer()->null(),
            'recorded_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->addForeignKey('fk-lab_results-lab_order_id', '{{%lab_results}}', 'lab_order_id', '{{%lab_orders}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-lab_results-recorded_by', '{{%lab_results}}', 'recorded_by', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%lab_results}}');
        $this->dropTable('{{%lab_orders}}');
    }
}
