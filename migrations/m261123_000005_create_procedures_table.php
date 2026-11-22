<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Creates `procedures` (tindakan medis & tarif) table (FR-01.06, BR-06).
 */
class m261123_000005_create_procedures_table extends Migration
{
    public function safeUp(): void
    {
        $this->createTable('{{%procedures}}', [
            'id' => $this->primaryKey(),
            'code' => $this->string(32)->notNull()->unique(),
            'name' => $this->string(191)->notNull(),
            'category' => $this->string(64)->notNull()->defaultValue('action'),
            'tariff' => $this->decimal(12, 2)->notNull()->defaultValue(0),
            'description' => $this->text()->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->notNull()->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        $this->execute('ALTER TABLE {{%procedures}} ADD CONSTRAINT chk_procedures_tariff CHECK (tariff >= 0)');
        $this->createIndex('idx-procedures-name', '{{%procedures}}', 'name');
        $this->createIndex('idx-procedures-category', '{{%procedures}}', 'category');
        $this->createIndex('idx-procedures-is_active', '{{%procedures}}', 'is_active');
    }

    public function safeDown(): void
    {
        $this->dropTable('{{%procedures}}');
    }
}
