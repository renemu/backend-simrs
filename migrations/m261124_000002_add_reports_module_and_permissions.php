<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds the `reports` (Laporan SIMRS) module and baseline role permissions
 * so existing installations receive the reporting module without fresh-seeding.
 */
class m261124_000002_add_reports_module_and_permissions extends Migration
{
    private const CORE_ROLES = [
        'admin',
        'registration',
        'doctor',
        'pharmacy',
        'laboratory',
        'cashier',
    ];

    public function safeUp(): void
    {
        $db = $this->db;
        $now = date('Y-m-d H:i:s');

        // 1. Shift `users` and `settings` sort_order cleanly if they are still at 5 and 6
        $db->createCommand()
            ->update('{{%modules}}', ['sort_order' => 6, 'updated_at' => $now], ['code' => 'users', 'sort_order' => 5])
            ->execute();
        $db->createCommand()
            ->update('{{%modules}}', ['sort_order' => 7, 'updated_at' => $now], ['code' => 'settings', 'sort_order' => 6])
            ->execute();

        // 2. Insert `reports` module if it does not already exist
        $exists = $db->createCommand(
            'SELECT id FROM {{%modules}} WHERE code = :code',
            [':code' => 'reports']
        )->queryScalar();

        if (!$exists) {
            $db->createCommand()->insert('{{%modules}}', [
                'name' => 'Laporan SIMRS',
                'code' => 'reports',
                'icon' => 'BarChart3Icon',
                'create' => false,
                'read' => true,
                'update' => false,
                'delete' => false,
                'created_by' => null,
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'parent_id' => null,
                'path' => '/reports',
                'sort_order' => 5,
                'type' => 1,
            ])->execute();

            $db->createCommand(
                "SELECT setval(pg_get_serial_sequence('modules', 'id'), COALESCE((SELECT MAX(id) FROM modules), 1));"
            )->execute();
        }

        // 3. Grant `reports` read permission to the 6 core SIMRS roles (report sections are further scoped server-side)
        $roles = $db->createCommand('SELECT id, name, module_permission FROM {{%roles}}')->queryAll();
        foreach ($roles as $role) {
            if (!in_array($role['name'], self::CORE_ROLES, true)) {
                continue;
            }

            $decoded = json_decode((string) ($role['module_permission'] ?? '[]'), true);
            if (!is_array($decoded)) {
                $decoded = [];
            }

            $hasReports = false;
            foreach ($decoded as $perm) {
                if (is_array($perm) && ($perm['module_code'] ?? '') === 'reports') {
                    $hasReports = true;
                    break;
                }
            }

            if (!$hasReports) {
                $decoded[] = [
                    'module_code' => 'reports',
                    'create' => false,
                    'read' => true,
                    'update' => false,
                    'delete' => false,
                ];

                $db->createCommand()->update(
                    '{{%roles}}',
                    [
                        'module_permission' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => $now,
                    ],
                    ['id' => (int) $role['id']]
                )->execute();
            }
        }
    }

    public function safeDown(): void
    {
        $db = $this->db;
        $now = date('Y-m-d H:i:s');

        // Remove `reports` permission from roles
        $roles = $db->createCommand('SELECT id, module_permission FROM {{%roles}}')->queryAll();
        foreach ($roles as $role) {
            $decoded = json_decode((string) ($role['module_permission'] ?? '[]'), true);
            if (!is_array($decoded)) {
                continue;
            }

            $filtered = array_values(array_filter(
                $decoded,
                static fn($perm) => !is_array($perm) || ($perm['module_code'] ?? '') !== 'reports'
            ));

            if (count($filtered) !== count($decoded)) {
                $db->createCommand()->update(
                    '{{%roles}}',
                    [
                        'module_permission' => json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'updated_at' => $now,
                    ],
                    ['id' => (int) $role['id']]
                )->execute();
            }
        }

        // Delete `reports` module
        $db->createCommand()->delete('{{%modules}}', ['code' => 'reports'])->execute();

        // Restore sort_order
        $db->createCommand()
            ->update('{{%modules}}', ['sort_order' => 5, 'updated_at' => $now], ['code' => 'users', 'sort_order' => 6])
            ->execute();
        $db->createCommand()
            ->update('{{%modules}}', ['sort_order' => 6, 'updated_at' => $now], ['code' => 'settings', 'sort_order' => 7])
            ->execute();
    }
}
