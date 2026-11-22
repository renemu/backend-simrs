<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `invoices` and `invoice_items` tables (FR-07.01 - FR-07.03, BR-09, BR-12).
 */
class m261123_000012_create_invoices_tables extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%invoices}}', [
            'id' => $this->primaryKey(),
            'invoice_number' => $this->string(32)->notNull()->unique(),
            'visit_id' => $this->integer()->notNull(),
            'patient_id' => $this->integer()->notNull(),
            'revision_number' => $this->integer()->notNull()->defaultValue(1),
            'superseded_by_id' => $this->integer()->null(),
            'total_amount' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'paid_amount' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'balance_amount' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'payment_status' => $this->string(24)->notNull()->defaultValue('unpaid'),
            'status' => $this->string(24)->notNull()->defaultValue('issued'),
            'notes' => $this->text()->null(),
            'issued_by' => $this->integer()->null(),
            'issued_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute("ALTER TABLE {{%invoices}} ADD CONSTRAINT chk_invoices_revision CHECK (revision_number >= 1)");
        $this->execute("ALTER TABLE {{%invoices}} ADD CONSTRAINT chk_invoices_amounts CHECK (total_amount >= 0 AND paid_amount >= 0 AND balance_amount >= 0)");
        $this->execute("ALTER TABLE {{%invoices}} ADD CONSTRAINT chk_invoices_payment_status CHECK (payment_status IN ('unpaid', 'partially_paid', 'paid'))");
        $this->execute("ALTER TABLE {{%invoices}} ADD CONSTRAINT chk_invoices_status CHECK (status IN ('issued', 'revised', 'cancelled'))");

        // Ensure at most one active ('issued') invoice per visit at any time
        $this->execute("CREATE UNIQUE INDEX uq_invoices_active_visit ON {{%invoices}} (visit_id) WHERE status = 'issued'");
        $this->createIndex('idx-invoices-patient_id', '{{%invoices}}', 'patient_id');
        $this->createIndex('idx-invoices-payment_status', '{{%invoices}}', 'payment_status');
        $this->createIndex('idx-invoices-issued_at', '{{%invoices}}', 'issued_at');

        $this->addForeignKey('fk-invoices-visit_id', '{{%invoices}}', 'visit_id', '{{%visits}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-invoices-patient_id', '{{%invoices}}', 'patient_id', '{{%patients}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-invoices-issued_by', '{{%invoices}}', 'issued_by', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
        $this->addForeignKey('fk-invoices-superseded_by', '{{%invoices}}', 'superseded_by_id', '{{%invoices}}', 'id', 'SET NULL', 'CASCADE');

        $this->createTable('{{%invoice_items}}', [
            'id' => $this->primaryKey(),
            'invoice_id' => $this->integer()->notNull(),
            'item_type' => $this->string(32)->notNull(),
            'reference_id' => $this->integer()->null(),
            'description' => $this->string(255)->notNull(),
            'quantity' => $this->integer()->notNull()->defaultValue(1),
            'unit_price' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'line_total' => $this->decimal(12, 2)->notNull()->defaultValue(0),
        ]);

        $this->execute("ALTER TABLE {{%invoice_items}} ADD CONSTRAINT chk_invoice_items_type CHECK (item_type IN ('registration', 'consultation', 'procedure', 'laboratory', 'medicine'))");
        $this->execute("ALTER TABLE {{%invoice_items}} ADD CONSTRAINT chk_invoice_items_qty CHECK (quantity > 0)");
        $this->execute("ALTER TABLE {{%invoice_items}} ADD CONSTRAINT chk_invoice_items_prices CHECK (unit_price >= 0 AND line_total >= 0)");

        $this->createIndex('idx-invoice_items-invoice_id', '{{%invoice_items}}', 'invoice_id');
        $this->addForeignKey('fk-invoice_items-invoice_id', '{{%invoice_items}}', 'invoice_id', '{{%invoices}}', 'id', 'CASCADE', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%invoice_items}}');
        $this->dropTable('{{%invoices}}');
    }
}
