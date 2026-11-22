<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `visit_procedures` table (FR-04, BR-06 tariff snapshot).
 */
class m261123_000009_create_visit_procedures_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%visit_procedures}}', [
            'id' => $this->primaryKey(),
            'visit_id' => $this->integer()->notNull(),
            'procedure_id' => $this->integer()->notNull(),
            'procedure_name_snapshot' => $this->string(191)->notNull(),
            'quantity' => $this->integer()->notNull()->defaultValue(1),
            'applied_tariff' => $this->decimal(12, 2)->notNull(),
            'notes' => $this->text()->null(),
            'status' => $this->string(24)->notNull()->defaultValue('active'),
            'performed_by' => $this->integer()->null(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute("ALTER TABLE {{%visit_procedures}} ADD CONSTRAINT chk_visit_procedures_qty CHECK (quantity > 0)");
        $this->execute("ALTER TABLE {{%visit_procedures}} ADD CONSTRAINT chk_visit_procedures_tariff CHECK (applied_tariff >= 0)");
        $this->execute("ALTER TABLE {{%visit_procedures}} ADD CONSTRAINT chk_visit_procedures_status CHECK (status IN ('active', 'cancelled'))");

        $this->createIndex('idx-visit_procedures-visit_id', '{{%visit_procedures}}', 'visit_id');
        $this->createIndex('idx-visit_procedures-procedure_id', '{{%visit_procedures}}', 'procedure_id');

        $this->addForeignKey('fk-visit_procedures-visit_id', '{{%visit_procedures}}', 'visit_id', '{{%visits}}', 'id', 'CASCADE', 'CASCADE');
        $this->addForeignKey('fk-visit_procedures-procedure_id', '{{%visit_procedures}}', 'procedure_id', '{{%procedures}}', 'id', 'RESTRICT', 'CASCADE');
        $this->addForeignKey('fk-visit_procedures-performed_by', '{{%visit_procedures}}', 'performed_by', '{{%users}}', 'id', 'SET NULL', 'CASCADE');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%visit_procedures}}');
    }
}
