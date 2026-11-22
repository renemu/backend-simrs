<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `medicines` (obat, stok & harga) table (FR-01.07, BR-07).
 */
class m261123_000006_create_medicines_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%medicines}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(32)->notNull()->unique(),
            'name' => $this->string(191)->notNull(),
            'unit' => $this->string(32)->notNull(),
            'stock' => $this->integer()->notNull()->defaultValue(0),
            'min_stock' => $this->integer()->notNull()->defaultValue(10),
            'unit_price' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute('ALTER TABLE {{%medicines}} ADD CONSTRAINT chk_medicines_stock CHECK (stock >= 0)');
        $this->execute('ALTER TABLE {{%medicines}} ADD CONSTRAINT chk_medicines_min_stock CHECK (min_stock >= 0)');
        $this->execute('ALTER TABLE {{%medicines}} ADD CONSTRAINT chk_medicines_unit_price CHECK (unit_price >= 0)');

        $this->createIndex('idx-medicines-name', '{{%medicines}}', 'name');
        $this->createIndex('idx-medicines-stock', '{{%medicines}}', 'stock');
        $this->createIndex('idx-medicines-is_active', '{{%medicines}}', 'is_active');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%medicines}}');
    }
}
