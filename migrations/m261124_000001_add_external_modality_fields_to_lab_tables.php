<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Extends `lab_orders` and `lab_results` to support external diagnostic/mechanical
 * modality gateways (CT-Scan, X-Ray, MRI, USG, EKG, LIS) inside the Laboratory module.
 */
class m261124_000001_add_external_modality_fields_to_lab_tables extends Migration
{
    public function safeUp(): void
    {
        // 1. Extend lab_orders with modality & external gateway tracking fields
        $this->addColumn('{{%lab_orders}}', 'modality_type', $this->string(32)->notNull()->defaultValue('lab_clinical'));
        $this->addColumn('{{%lab_orders}}', 'body_part', $this->string(120)->null());
        $this->addColumn('{{%lab_orders}}', 'contrast_used', $this->boolean()->notNull()->defaultValue(false));
        $this->addColumn('{{%lab_orders}}', 'external_system', $this->string(64)->null());
        $this->addColumn('{{%lab_orders}}', 'external_reference_id', $this->string(128)->null());
        $this->addColumn('{{%lab_orders}}', 'dispatched_at', $this->timestamp(3)->null());
        $this->addColumn('{{%lab_orders}}', 'last_synced_at', $this->timestamp(3)->null());
        $this->addColumn('{{%lab_orders}}', 'integration_error', $this->text()->null());

        $this->execute("ALTER TABLE {{%lab_orders}} DROP CONSTRAINT IF EXISTS chk_lab_orders_integration");
        $this->execute("ALTER TABLE {{%lab_orders}} ADD CONSTRAINT chk_lab_orders_integration CHECK (integration_status IN ('local_only', 'dispatched', 'synced', 'failed'))");
        $this->execute("ALTER TABLE {{%lab_orders}} ADD CONSTRAINT chk_lab_orders_modality CHECK (modality_type IN ('lab_clinical', 'ct_scan', 'xray', 'mri', 'usg', 'ekg', 'other'))");

        $this->createIndex('idx-lab_orders-modality_type', '{{%lab_orders}}', 'modality_type');
        $this->createIndex('idx-lab_orders-external_reference_id', '{{%lab_orders}}', 'external_reference_id');

        // 2. Extend lab_results with external modality/DICOM provenance fields
        $this->addColumn('{{%lab_results}}', 'result_source', $this->string(32)->notNull()->defaultValue('manual'));
        $this->addColumn('{{%lab_results}}', 'external_study_uid', $this->string(128)->null());
        $this->addColumn('{{%lab_results}}', 'modality_device', $this->string(128)->null());
        $this->addColumn('{{%lab_results}}', 'external_completed_at', $this->timestamp(3)->null());

        $this->execute("ALTER TABLE {{%lab_results}} ADD CONSTRAINT chk_lab_results_source CHECK (result_source IN ('manual', 'external_gateway'))");
    }

    public function safeDown(): void
    {
        $this->execute("ALTER TABLE {{%lab_results}} DROP CONSTRAINT IF EXISTS chk_lab_results_source");
        $this->dropColumn('{{%lab_results}}', 'external_completed_at');
        $this->dropColumn('{{%lab_results}}', 'modality_device');
        $this->dropColumn('{{%lab_results}}', 'external_study_uid');
        $this->dropColumn('{{%lab_results}}', 'result_source');

        $this->dropIndex('idx-lab_orders-external_reference_id', '{{%lab_orders}}');
        $this->dropIndex('idx-lab_orders-modality_type', '{{%lab_orders}}');
        $this->execute("ALTER TABLE {{%lab_orders}} DROP CONSTRAINT IF EXISTS chk_lab_orders_modality");

        // Normalize any 'dispatched' rows before restoring original CHECK constraint
        $this->execute("UPDATE {{%lab_orders}} SET integration_status = 'local_only' WHERE integration_status = 'dispatched'");
        $this->execute("ALTER TABLE {{%lab_orders}} DROP CONSTRAINT IF EXISTS chk_lab_orders_integration");
        $this->execute("ALTER TABLE {{%lab_orders}} ADD CONSTRAINT chk_lab_orders_integration CHECK (integration_status IN ('local_only', 'synced', 'failed'))");

        $this->dropColumn('{{%lab_orders}}', 'integration_error');
        $this->dropColumn('{{%lab_orders}}', 'last_synced_at');
        $this->dropColumn('{{%lab_orders}}', 'dispatched_at');
        $this->dropColumn('{{%lab_orders}}', 'external_reference_id');
        $this->dropColumn('{{%lab_orders}}', 'external_system');
        $this->dropColumn('{{%lab_orders}}', 'contrast_used');
        $this->dropColumn('{{%lab_orders}}', 'body_part');
        $this->dropColumn('{{%lab_orders}}', 'modality_type');
    }
}
