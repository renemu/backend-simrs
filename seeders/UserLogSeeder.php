<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for `user_logs` table.
 */
class UserLogSeeder
{
    public static function run(): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%user_logs}}')->execute();

        $rows = [
            [1, 1, 'Admin test', null, 'login', 'auth', 1, 'User admin@example.com logged in', '2026-10-28 01:44:48.017', '127.0.0.1', 'Mozilla/5.0', 'Local'],
            [2, 4, 'Super Admin', null, 'login', 'auth', 4, 'User superadmin@example.com logged in', '2026-11-02 01:52:13.752', '127.0.0.1', 'Mozilla/5.0', 'Local'],
        ];

        $count = $db->createCommand()->batchInsert('{{%user_logs}}', [
            'id', 'user_id', 'actor_name', 'impersonator_id', 'action', 'module', 'target_id', 'description', 'created_at', 'ip', 'browser', 'location'
        ], $rows)->execute();

        $db->createCommand("SELECT setval(pg_get_serial_sequence('user_logs', 'id'), COALESCE((SELECT MAX(id) FROM user_logs), 1));")->execute();

        return $count;
    }
}
