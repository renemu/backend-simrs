<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for `modules` table.
 */
class ModuleSeeder
{
    public static function run(): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%modules}}')->execute();

        $rows = [
            [1, 'Dashboard', 'dashboard', 'LayoutDashboardIcon', false, true, false, false, null, 4, '2026-10-21 04:20:42.318', '2026-10-25 10:39:49.016', null, '/dashboard', 1, 1],
            [3, 'Settings', 'settings', 'SettingsIcon', false, true, false, false, null, 4, '2026-10-21 04:20:42.324', '2026-10-25 10:40:58.306', null, '/settings', 2, 0],
            [4, 'Users', 'users', 'UsersIcon', false, true, false, false, null, 4, '2026-10-21 04:30:53.684', '2026-10-25 10:40:43.942', null, null, 1, 0],
            [7, 'Notification', 'notification', 'BellIcon', false, true, false, false, null, 4, '2026-10-22 16:27:54.841', '2026-10-25 10:40:21.220', null, '/notification', 2, 3],
            [5, 'Users', 'users-list', 'UserIcon', false, true, false, false, null, null, '2026-10-21 04:59:31.433', '2026-10-23 16:06:45.266', 4, '/users', 1, 1],
            [6, 'Roles', 'users-roles', 'ShieldIcon', false, true, false, false, null, null, '2026-10-21 04:59:31.438', '2026-10-23 16:06:45.271', 4, '/users/roles', 2, 1],
            [9, 'System', 'settings-apps', 'AppWindowIcon', false, true, false, false, null, 4, '2026-10-23 23:43:46.115', '2026-10-26 16:55:13.441', 3, '/settings/apps', 2, 1],
            [10, 'Appearance', 'settings-appearance', 'PaletteIcon', false, true, false, false, null, 4, '2026-10-23 23:43:46.115', '2026-10-26 16:55:13.435', 3, '/settings/appearance', 1, 1],
            [11, 'Integration', 'settings-integration', 'PlugIcon', false, true, false, false, null, null, '2026-10-23 23:43:46.115', null, 3, '/settings/integration', 3, 1],
            [12, 'Modules', 'settings-modules', 'ListTreeIcon', false, true, true, false, null, null, '2026-10-25 17:31:52.092', null, 3, '/settings/modules', 4, 1],
            [13, 'Activity logs', 'users-logs', 'FileTextIcon', false, true, false, false, null, null, '2026-10-25 17:47:48.972', null, 4, '/users/logs', 3, 1],
        ];

        $count = $db->createCommand()->batchInsert('{{%modules}}', [
            'id', 'name', 'code', 'icon', 'create', 'read', 'update', 'delete',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'parent_id', 'path', 'sort_order', 'type'
        ], $rows)->execute();

        $db->createCommand("SELECT setval(pg_get_serial_sequence('modules', 'id'), COALESCE((SELECT MAX(id) FROM modules), 1));")->execute();

        return $count;
    }
}
