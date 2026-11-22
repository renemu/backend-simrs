<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `prescriptions`, `prescription_items`, and `medicine_stock_movements` tables (FR-05, BR-07).
 */
class m261123_000010_create_prescriptions_tables extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%prescriptions}}', [
            'id' => $this->primaryKey(),
            'prescription_number' => $this->string(32)->notNull()->unique(),
            'visit_id' => $this->integer()->notNull(),
            'patient_id' => $this->integer()->notNull(),
            'doctor_id' => $this->integer()->notNull(),
            'status' => $this->string(24)->notNull()->defaultValue('pending'),
            'notes' => $this->text()->null(),
            'dispensed_by' => $this->integer()->null(),
            'dispensed_at' => $this->timestamp(3)->null(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute("ALTER TABLE {{%prescriptions}} ADD CONSTRAINT chk_prescriptions_status CHECK (status IN ('pending', 'dispensed', 'cancelled'))");
        $this->createIndex('idx-prescriptions-visit_id', '{{%prescriptions}}', 'visit_id');
        $this->createIndex('idx-prescriptions-patient_id', '{{%prescriptions}}', 'patient_id');
        $this->createIndex('idx-prescriptions-status', '{{%prescriptions}}', 'status');

        $this->addForeignKey('fk-prescriptions-visit_id', '{{%prescriptions}}', 'visit_id', '{{%visits}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-prescriptions-patient_id', '{{%prescriptions}}', 'patient_id', '{{%patients}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-prescriptions-doctor_id', '{{%prescriptions}}', 'doctor_id', '{{%doctors}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-prescriptions-dispensed_by', '{{%prescriptions}}', 'dispensed_by', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%prescription_items}}', [
            'id' => $this->primaryKey(),
            'prescription_id' => $this->integer()->notNull(),
            'medicine_id' => $this->integer()->notNull(),
            'medicine_name_snapshot' => $this->string(191)->notNull(),
            'unit_snapshot' => $this->string(32)->notNull(),
            'dosage' => $this->string(100)->notNull(),
            'frequency' => $this->string(100)->notNull(),
            'quantity' => $this->integer()->notNull(),
            'unit_price_snapshot' => $this->decimal(12, 2)->notNull(),
            'instructions' => $this->text()->null(),
        ]);

        $this->execute("ALTER TABLE {{%prescription_items}} ADD CONSTRAINT chk_prescription_items_qty CHECK (quantity > 0)");
        $this->execute("ALTER TABLE {{%prescription_items}} ADD CONSTRAINT chk_prescription_items_price CHECK (unit_price_snapshot >= 0)");
        $this->createIndex('idx-prescription_items-prescription_id', '{{%prescription_items}}', 'prescription_id');
        $this->createIndex('idx-prescription_items-medicine_id', '{{%prescription_items}}', 'medicine_id');

        $this->addForeignKey('fk-prescription_items-prescription_id', '{{%prescription_items}}', 'prescription_id', '{{%prescriptions}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-prescription_items-medicine_id', '{{%prescription_items}}', 'medicine_id', '{{%medicines}}', 'id', 'RESTRICT', 'CASCADE');

        $this->createTable('{{%medicine_stock_movements}}', [
            'id' => $this->primaryKey(),
            'medicine_id' => $this->integer()->notNull(),
            'movement_type' => $this->string(24)->notNull(),
            'quantity_delta' => $this->integer()->notNull(),
            'stock_before' => $this->integer()->notNull(),
            'stock_after' => $this->integer()->notNull(),
            'reference_type' => $this->string(50)->null(),
            'reference_id' => $this->integer()->null(),
            'notes' => $this->text()->null(),
            'actor_id' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->execute("ALTER TABLE {{%medicine_stock_movements}} ADD CONSTRAINT chk_stock_movements_type CHECK (movement_type IN ('dispense', 'restock', 'adjustment'))");
        $this->execute("ALTER TABLE {{%medicine_stock_movements}} ADD CONSTRAINT chk_stock_movements_after CHECK (stock_after >= 0)");
        $this->createIndex('idx-stock_movements-medicine_created', '{{%medicine_stock_movements}}', ['medicine_id', 'created_at']);
        $this->createIndex('idx-stock_movements-ref', '{{%medicine_stock_movements}}', ['reference_type', 'reference_id']);

        $this->addForeignKey('fk-stock_movements-medicine_id', '{{%medicine_stock_movements}}', 'medicine_id', '{{%medicines}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-stock_movements-actor_id', '{{%medicine_stock_movements}}', 'actor_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%medicine_stock_movements}}');
        $this->dropTable('{{%prescription_items}}');
        $this->dropTable('{{%prescriptions}}');
    }
}
