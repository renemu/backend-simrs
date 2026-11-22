<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Master DatabaseSeeder that runs all table seeders in foreign-key dependency order.
 */
class DatabaseSeeder
{
    /**
     * @return array<string, int> Map of table name => inserted row count
     */
    public static function run(): array
    {
        $db = Yii::$app->db;
        $transaction = $db->beginTransaction();
        try {
            // Clear Batch 4, Batch 3 & Batch 2 tables first if they exist (FK children first)
            $schema = $db->schema;
            $domainTables = [
                'payments', 'invoice_items', 'invoices',
                'lab_results', 'lab_orders',
                'medicine_stock_movements', 'prescription_items', 'prescriptions',
                'visit_procedures', 'medical_records', 'visits',
                'doctor_schedules', 'doctors', 'departments', 'procedures', 'medicines', 'patients',
            ];
            foreach ($domainTables as $tbl) {
                if ($schema->getTableSchema('{{%' . $tbl . '}}', true) !== null) {
                    $db->createCommand()->delete('{{%' . $tbl . '}}')->execute();
                }
            }

            // Clear Batch 1 child tables before parent tables
            $db->createCommand()->delete('{{%user_logs}}')->execute();
            $db->createCommand()->delete('{{%users}}')->execute();
            $db->createCommand()->delete('{{%modules}}')->execute();
            $db->createCommand()->delete('{{%roles}}')->execute();
            $db->createCommand()->delete('{{%auth_rate_limits}}')->execute();

            $results = [
                'roles' => RoleSeeder::run(),
                'modules' => ModuleSeeder::run(),
                'users' => UserSeeder::run(),
            ];

            if ($schema->getTableSchema('{{%patients}}', true) !== null) {
                $results = array_merge($results, MasterDataSeeder::run());
            }

            $transaction->commit();
            return $results;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
