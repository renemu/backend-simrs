<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `payments` table with unique `payment_reference` and unique `idempotency_key` (FR-07.04 - FR-07.08, BR-10, BR-11).
 */
class m261123_000013_create_payments_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%payments}}', [
            'id' => $this->primaryKey(),
            'invoice_id' => $this->integer()->notNull(),
            'payment_reference' => $this->string(64)->notNull()->unique(),
            'idempotency_key' => $this->string(128)->notNull()->unique(),
            'payment_method' => $this->string(24)->notNull(),
            'payment_channel' => $this->string(64)->null(),
            'amount' => $this->decimal(12, 2)->notNull(),
            'status' => $this->string(24)->notNull()->defaultValue('completed'),
            'notes' => $this->text()->null(),
            'cashier_id' => $this->integer()->null(),
            'paid_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
        ]);

        $this->execute("ALTER TABLE {{%payments}} ADD CONSTRAINT chk_payments_method CHECK (payment_method IN ('cash', 'non_cash'))");
        $this->execute("ALTER TABLE {{%payments}} ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)");
        $this->execute("ALTER TABLE {{%payments}} ADD CONSTRAINT chk_payments_status CHECK (status IN ('completed', 'voided'))");

        $this->createIndex('idx-payments-invoice_id', '{{%payments}}', 'invoice_id');
        $this->createIndex('idx-payments-paid_at', '{{%payments}}', 'paid_at');

        $this->addForeignKey('fk-payments-invoice_id', '{{%payments}}', 'invoice_id', '{{%invoices}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-payments-cashier_id', '{{%payments}}', 'cashier_id', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%payments}}');
    }
}
