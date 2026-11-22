<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for `auth_rate_limits` table.
 */
class AuthRateLimitSeeder
{
    public static function run(): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%auth_rate_limits}}')->execute();

        $rows = [
            ['login:127.0.0.1', 1, '2026-11-22 22:00:00.000'],
        ];

        return $db->createCommand()->batchInsert('{{%auth_rate_limits}}', [
            'key', 'attempts', 'expires_at'
        ], $rows)->execute();
    }
}
