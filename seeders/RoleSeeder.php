<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for `roles` table implementing the 6 SIMRS roles and baseline permission matrix
 * from docs/01-analysis/actors-and-roles.md.
 */
class RoleSeeder
{
    /**
     * Helper to build a single module permission entry.
     *
     * @return array{module_code: string, create: bool, read: bool, update: bool, delete: bool}
     */
    private static function perm(
        string $code,
        bool $create = false,
        bool $read = false,
        bool $update = false,
        bool $delete = false
    ): array {
        return [
            'module_code' => $code,
            'create' => $create,
            'read' => $read,
            'update' => $update,
            'delete' => $delete,
        ];
    }

    /**
     * Encodes permission list into JSON.
     *
     * @param array<int, array{module_code: string, create: bool, read: bool, update: bool, delete: bool}> $perms
     */
    private static function encode(array $perms): string
    {
        return (string) json_encode($perms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function run(): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%roles}}')->execute();

        $now = date('Y-m-d H:i:s');

        // 1. Administrator — CRUD master data, user/role/module; R-only on clinical/financial records
        $adminPerms = self::encode([
            self::perm('dashboard', false, true, false, false),
            self::perm('master-data', false, true, false, false),
            self::perm('master-patients', true, true, true, true),
            self::perm('master-doctors', true, true, true, true),
            self::perm('master-schedules', true, true, true, true),
            self::perm('master-procedures', true, true, true, true),
            self::perm('master-medicines', true, true, true, true),
            self::perm('outpatient', false, true, false, false),
            self::perm('registrations', false, true, false, false),
            self::perm('queues', false, true, false, false),
            self::perm('medical-records', false, true, false, false),
            self::perm('prescriptions', false, true, false, false),
            self::perm('laboratory', false, true, false, false),
            self::perm('finance', false, true, false, false),
            self::perm('billings', false, true, false, false),
            self::perm('payments', false, true, false, false),
            self::perm('reports', false, true, false, false),
            self::perm('users', false, true, false, false),
            self::perm('users-list', true, true, true, true),
            self::perm('users-roles', true, true, true, true),
            self::perm('users-logs', false, true, false, false),
            self::perm('settings', false, true, false, false),
            self::perm('settings-modules', true, true, true, true),
            self::perm('settings-apps', false, true, true, false),
        ]);

        // 2. Petugas Pendaftaran — CRU patients, registrations, queues; R master & billing; no clinical/payment writes
        $registrationPerms = self::encode([
            self::perm('dashboard', false, true, false, false),
            self::perm('master-data', false, true, false, false),
            self::perm('master-patients', true, true, true, false),
            self::perm('master-doctors', false, true, false, false),
            self::perm('master-schedules', false, true, false, false),
            self::perm('master-procedures', false, true, false, false),
            self::perm('master-medicines', false, true, false, false),
            self::perm('outpatient', false, true, false, false),
            self::perm('registrations', true, true, true, false),
            self::perm('queues', true, true, true, false),
            self::perm('medical-records', false, false, false, false),
            self::perm('prescriptions', false, false, false, false),
            self::perm('laboratory', false, false, false, false),
            self::perm('finance', false, true, false, false),
            self::perm('billings', false, true, false, false),
            self::perm('payments', false, false, false, false),
            self::perm('reports', false, true, false, false),
            self::perm('users', false, false, false, false),
            self::perm('users-list', false, false, false, false),
            self::perm('users-roles', false, false, false, false),
            self::perm('users-logs', false, false, false, false),
            self::perm('settings', false, false, false, false),
            self::perm('settings-modules', false, false, false, false),
            self::perm('settings-apps', false, false, false, false),
        ]);

        // 3. Dokter — R/U queues; CRU medical records; CR prescriptions & lab orders; R master/registrations/billings
        $doctorPerms = self::encode([
            self::perm('dashboard', false, true, false, false),
            self::perm('master-data', false, true, false, false),
            self::perm('master-patients', false, true, false, false),
            self::perm('master-doctors', false, true, false, false),
            self::perm('master-schedules', false, true, false, false),
            self::perm('master-procedures', false, true, false, false),
            self::perm('master-medicines', false, true, false, false),
            self::perm('outpatient', false, true, false, false),
            self::perm('registrations', false, true, false, false),
            self::perm('queues', false, true, true, false),
            self::perm('medical-records', true, true, true, false),
            self::perm('prescriptions', true, true, false, false),
            self::perm('laboratory', true, true, false, false),
            self::perm('finance', false, true, false, false),
            self::perm('billings', false, true, false, false),
            self::perm('payments', false, false, false, false),
            self::perm('reports', false, true, false, false),
            self::perm('users', false, false, false, false),
            self::perm('users-list', false, false, false, false),
            self::perm('users-roles', false, false, false, false),
            self::perm('users-logs', false, false, false, false),
            self::perm('settings', false, false, false, false),
            self::perm('settings-modules', false, false, false, false),
            self::perm('settings-apps', false, false, false, false),
        ]);

        // 4. Petugas Farmasi — RU medicines; R/Process(U) prescriptions; R* patients & billings
        $pharmacyPerms = self::encode([
            self::perm('dashboard', false, true, false, false),
            self::perm('master-data', false, true, false, false),
            self::perm('master-patients', false, true, false, false),
            self::perm('master-doctors', false, false, false, false),
            self::perm('master-schedules', false, false, false, false),
            self::perm('master-procedures', false, false, false, false),
            self::perm('master-medicines', false, true, true, false),
            self::perm('outpatient', false, true, false, false),
            self::perm('registrations', false, false, false, false),
            self::perm('queues', false, false, false, false),
            self::perm('medical-records', false, false, false, false),
            self::perm('prescriptions', false, true, true, false),
            self::perm('laboratory', false, false, false, false),
            self::perm('finance', false, true, false, false),
            self::perm('billings', false, true, false, false),
            self::perm('payments', false, false, false, false),
            self::perm('reports', false, true, false, false),
            self::perm('users', false, false, false, false),
            self::perm('users-list', false, false, false, false),
            self::perm('users-roles', false, false, false, false),
            self::perm('users-logs', false, false, false, false),
            self::perm('settings', false, false, false, false),
            self::perm('settings-modules', false, false, false, false),
            self::perm('settings-apps', false, false, false, false),
        ]);

        // 5. Petugas Laboratorium — CRU lab orders/results; R* patients, medical records & billings
        $laboratoryPerms = self::encode([
            self::perm('dashboard', false, true, false, false),
            self::perm('master-data', false, true, false, false),
            self::perm('master-patients', false, true, false, false),
            self::perm('master-doctors', false, false, false, false),
            self::perm('master-schedules', false, false, false, false),
            self::perm('master-procedures', false, false, false, false),
            self::perm('master-medicines', false, false, false, false),
            self::perm('outpatient', false, true, false, false),
            self::perm('registrations', false, false, false, false),
            self::perm('queues', false, false, false, false),
            self::perm('medical-records', false, true, false, false),
            self::perm('prescriptions', false, false, false, false),
            self::perm('laboratory', true, true, true, false),
            self::perm('finance', false, true, false, false),
            self::perm('billings', false, true, false, false),
            self::perm('payments', false, false, false, false),
            self::perm('reports', false, true, false, false),
            self::perm('users', false, false, false, false),
            self::perm('users-list', false, false, false, false),
            self::perm('users-roles', false, false, false, false),
            self::perm('users-logs', false, false, false, false),
            self::perm('settings', false, false, false, false),
            self::perm('settings-modules', false, false, false, false),
            self::perm('settings-apps', false, false, false, false),
        ]);

        // 6. Kasir — CRU billings & payments; R* patients, procedures, medicines, registrations, prescriptions
        $cashierPerms = self::encode([
            self::perm('dashboard', false, true, false, false),
            self::perm('master-data', false, true, false, false),
            self::perm('master-patients', false, true, false, false),
            self::perm('master-doctors', false, false, false, false),
            self::perm('master-schedules', false, false, false, false),
            self::perm('master-procedures', false, true, false, false),
            self::perm('master-medicines', false, true, false, false),
            self::perm('outpatient', false, true, false, false),
            self::perm('registrations', false, true, false, false),
            self::perm('queues', false, false, false, false),
            self::perm('medical-records', false, false, false, false),
            self::perm('prescriptions', false, true, false, false),
            self::perm('laboratory', false, false, false, false),
            self::perm('finance', false, true, false, false),
            self::perm('billings', true, true, true, false),
            self::perm('payments', true, true, true, false),
            self::perm('reports', false, true, false, false),
            self::perm('users', false, false, false, false),
            self::perm('users-list', false, false, false, false),
            self::perm('users-roles', false, false, false, false),
            self::perm('users-logs', false, false, false, false),
            self::perm('settings', false, false, false, false),
            self::perm('settings-modules', false, false, false, false),
            self::perm('settings-apps', false, false, false, false),
        ]);

        $rows = [
            [1, 'admin', 'Administrator', $adminPerms, null, null, $now, $now],
            [2, 'registration', 'Petugas Pendaftaran', $registrationPerms, null, null, $now, $now],
            [3, 'doctor', 'Dokter', $doctorPerms, null, null, $now, $now],
            [4, 'pharmacy', 'Petugas Farmasi', $pharmacyPerms, null, null, $now, $now],
            [5, 'laboratory', 'Petugas Laboratorium', $laboratoryPerms, null, null, $now, $now],
            [6, 'cashier', 'Kasir', $cashierPerms, null, null, $now, $now],
        ];

        $count = $db->createCommand()->batchInsert('{{%roles}}', [
            'id', 'name', 'display_name', 'module_permission', 'created_by', 'updated_by', 'created_at', 'updated_at'
        ], $rows)->execute();

        $db->createCommand("SELECT setval(pg_get_serial_sequence('roles', 'id'), COALESCE((SELECT MAX(id) FROM roles), 1));")->execute();

        return $count;
    }
}
