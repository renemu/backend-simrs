<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for `roles` table.
 */
class RoleSeeder
{
    public static function run(): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%roles}}')->execute();

        $rows = [
            [
                1,
                'admin',
                'Administrator',
                '[{"module_code":"dashboard","create":false,"read":true,"update":false,"delete":false},{"module_code":"notification","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-appearance","create":false,"read":true,"update":false,"delete":false},{"module_code":"settings-apps","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-integration","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-modules","create":false,"read":true,"update":true,"delete":false},{"module_code":"users","create":false,"read":true,"update":false,"delete":false},{"module_code":"users-list","create":true,"read":true,"update":true,"delete":true},{"module_code":"users-logs","create":false,"read":true,"update":false,"delete":false},{"module_code":"users-roles","create":false,"read":true,"update":false,"delete":false}]',
                null,
                4,
                '2026-10-21 04:20:42.307',
                '2026-10-28 01:53:47.053'
            ],
            [
                2,
                'user',
                'Regular User',
                '[{"module_code":"dashboard","create":false,"read":true,"update":false,"delete":false},{"module_code":"notification","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-appearance","create":false,"read":true,"update":true,"delete":false},{"module_code":"settings-apps","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-integration","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-modules","create":false,"read":false,"update":false,"delete":false},{"module_code":"users","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-list","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-logs","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-roles","create":false,"read":false,"update":false,"delete":false}]',
                null,
                4,
                '2026-10-21 04:20:42.313',
                '2026-10-27 02:17:04.530'
            ],
            [
                3,
                'superadmin',
                'Super Administrator',
                '[{"read": true, "create": true, "delete": true, "update": true, "module_code": "dashboard"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "settings"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "users"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "users-list"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "users-roles"}, {"read": true, "create": false, "delete": false, "update": true, "module_code": "settings-appearance"}, {"read": true, "create": false, "delete": false, "update": true, "module_code": "settings-apps"}, {"read": true, "create": false, "delete": false, "update": false, "module_code": "settings-integration"}, {"read": true, "create": false, "delete": false, "update": true, "module_code": "settings-modules"}, {"read": true, "create": false, "delete": false, "update": false, "module_code": "users-logs"}]',
                null,
                null,
                '2026-10-21 06:36:00.231',
                '2026-10-23 16:06:45.208'
            ],
            [
                7,
                'test-user',
                'Test User',
                '[{"module_code":"dashboard","create":false,"read":false,"update":false,"delete":false},{"module_code":"notification","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-appearance","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-apps","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-integration","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-modules","create":false,"read":false,"update":false,"delete":false},{"module_code":"users","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-list","create":false,"read":true,"update":true,"delete":false},{"module_code":"users-logs","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-roles","create":false,"read":false,"update":false,"delete":false}]',
                1,
                null,
                '2026-10-28 01:41:23.571',
                '2026-10-28 01:41:23.571'
            ],
        ];

        $count = $db->createCommand()->batchInsert('{{%roles}}', [
            'id', 'name', 'display_name', 'module_permission', 'created_by', 'updated_by', 'created_at', 'updated_at'
        ], $rows)->execute();

        $db->createCommand("SELECT setval(pg_get_serial_sequence('roles', 'id'), COALESCE((SELECT MAX(id) FROM roles), 1));")->execute();

        return $count;
    }
}
