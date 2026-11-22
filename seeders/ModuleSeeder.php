<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for `modules` table aligned with SIMRS outpatient modules in docs/01-analysis/.
 */
class ModuleSeeder
{
    public static function run(): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%modules}}')->execute();

        $now = date('Y-m-d H:i:s');

        // Columns: id, name, code, icon, create, read, update, delete, created_by, updated_by, created_at, updated_at, parent_id, path, sort_order, type
        // type: 0 = group/folder, 1 = page/module
        $rows = [
            // Root Dashboard
            [1, 'Dashboard', 'dashboard', 'LayoutDashboardIcon', false, true, false, false, null, null, $now, $now, null, '/dashboard', 1, 1],

            // Master Data Group
            [2, 'Master Data', 'master-data', 'DatabaseIcon', false, true, false, false, null, null, $now, $now, null, null, 2, 0],
            [3, 'Data Pasien', 'master-patients', 'UserIcon', true, true, true, true, null, null, $now, $now, 2, '/master/patients', 1, 1],
            [4, 'Dokter & Poli', 'master-doctors', 'StethoscopeIcon', true, true, true, true, null, null, $now, $now, 2, '/master/doctors', 2, 1],
            [5, 'Jadwal Praktik', 'master-schedules', 'CalendarClockIcon', true, true, true, true, null, null, $now, $now, 2, '/master/schedules', 3, 1],
            [6, 'Tindakan & Tarif', 'master-procedures', 'ClipboardListIcon', true, true, true, true, null, null, $now, $now, 2, '/master/procedures', 4, 1],
            [7, 'Obat & Stok', 'master-medicines', 'PillIcon', true, true, true, true, null, null, $now, $now, 2, '/master/medicines', 5, 1],

            // Pelayanan Rawat Jalan Group
            [8, 'Rawat Jalan', 'outpatient', 'ActivityIcon', false, true, false, false, null, null, $now, $now, null, null, 3, 0],
            [9, 'Pendaftaran Pasien', 'registrations', 'ClipboardPlusIcon', true, true, true, false, null, null, $now, $now, 8, '/outpatient/registrations', 1, 1],
            [10, 'Antrean Poli', 'queues', 'ListOrderedIcon', true, true, true, false, null, null, $now, $now, 8, '/outpatient/queues', 2, 1],
            [11, 'Rekam Medis', 'medical-records', 'FileHeartIcon', true, true, true, false, null, null, $now, $now, 8, '/outpatient/medical-records', 3, 1],
            [12, 'Resep & Farmasi', 'prescriptions', 'PackageCheckIcon', true, true, true, false, null, null, $now, $now, 8, '/outpatient/prescriptions', 4, 1],
            [13, 'Laboratorium', 'laboratory', 'FlaskConicalIcon', true, true, true, false, null, null, $now, $now, 8, '/outpatient/laboratory', 5, 1],

            // Billing & Pembayaran Group
            [14, 'Billing & Kasir', 'finance', 'ReceiptIcon', false, true, false, false, null, null, $now, $now, null, null, 4, 0],
            [15, 'Tagihan (Billing)', 'billings', 'FileSpreadsheetIcon', true, true, true, false, null, null, $now, $now, 14, '/finance/billings', 1, 1],
            [16, 'Pembayaran', 'payments', 'CreditCardIcon', true, true, true, false, null, null, $now, $now, 14, '/finance/payments', 2, 1],

            // Manajemen Akses Group
            [17, 'Manajemen Akses', 'users', 'UsersIcon', false, true, false, false, null, null, $now, $now, null, null, 5, 0],
            [18, 'Pengguna', 'users-list', 'UserIcon', true, true, true, true, null, null, $now, $now, 17, '/users', 1, 1],
            [19, 'Role & Hak Akses', 'users-roles', 'ShieldIcon', true, true, true, true, null, null, $now, $now, 17, '/users/roles', 2, 1],
            [20, 'Log Aktivitas', 'users-logs', 'FileTextIcon', false, true, false, false, null, null, $now, $now, 17, '/users/logs', 3, 1],

            // Pengaturan Group
            [21, 'Pengaturan', 'settings', 'SettingsIcon', false, true, false, false, null, null, $now, $now, null, '/settings', 6, 0],
            [22, 'Modul & Menu', 'settings-modules', 'ListTreeIcon', true, true, true, true, null, null, $now, $now, 21, '/settings/modules', 1, 1],
            [23, 'Sistem & API', 'settings-apps', 'AppWindowIcon', false, true, true, false, null, null, $now, $now, 21, '/settings/apps', 2, 1],
        ];

        $count = $db->createCommand()->batchInsert('{{%modules}}', [
            'id', 'name', 'code', 'icon', 'create', 'read', 'update', 'delete',
            'created_by', 'updated_by', 'created_at', 'updated_at', 'parent_id', 'path', 'sort_order', 'type'
        ], $rows)->execute();

        $db->createCommand("SELECT setval(pg_get_serial_sequence('modules', 'id'), COALESCE((SELECT MAX(id) FROM modules), 1));")->execute();

        return $count;
    }
}
