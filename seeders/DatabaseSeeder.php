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
        $transaction = Yii::$app->db->beginTransaction();
        try {
            // Clear child tables first before re-seeding parent tables
            Yii::$app->db->createCommand()->delete('{{%user_logs}}')->execute();
            Yii::$app->db->createCommand()->delete('{{%users}}')->execute();
            Yii::$app->db->createCommand()->delete('{{%modules}}')->execute();
            Yii::$app->db->createCommand()->delete('{{%roles}}')->execute();
            Yii::$app->db->createCommand()->delete('{{%auth_rate_limits}}')->execute();

            $results = [
                'roles' => RoleSeeder::run(),
                'modules' => ModuleSeeder::run(),
                'users' => UserSeeder::run(),
            ];

            $transaction->commit();
            return $results;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }
    }
}
