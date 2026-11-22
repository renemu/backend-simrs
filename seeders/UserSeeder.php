<?php

declare(strict_types=1);

namespace app\seeders;

use Yii;

/**
 * Seeder for `users` table providing one demo account for each of the 6 SIMRS roles.
 * All credentials are dummy data for local assessment/demo only.
 */
class UserSeeder
{
    public static function run(): int
    {
        $db = Yii::$app->db;
        $db->createCommand()->delete('{{%users}}')->execute();

        $now = date('Y-m-d H:i:s');

        $demoUsers = [
            [1, 'admin@example.com', 'Administrator SIMRS', '081100000001', 'Unit IT & Administrasi RS', 1, 'admin123', 'default'],
            [2, 'registration@example.com', 'Siti Rahmawati', '081100000002', 'Loket Pendaftaran Rawat Jalan', 2, 'pendaftaran123', 'teal'],
            [3, 'doctor@example.com', 'dr. Andi Pratama', '081100000003', 'Poliklinik Umum', 3, 'dokter123', 'emerald'],
            [4, 'pharmacy@example.com', 'apt. Rina Kartika', '081100000004', 'Instalasi Farmasi Rawat Jalan', 4, 'farmasi123', 'violet'],
            [5, 'laboratory@example.com', 'Budi Santoso, A.Md.AK', '081100000005', 'Instalasi Laboratorium Klinik', 5, 'lab123', 'default'],
            [6, 'cashier@example.com', 'Dewi Lestari', '081100000006', 'Loket Kasir Rawat Jalan', 6, 'kasir123', 'teal'],
        ];

        $rows = [];
        foreach ($demoUsers as [$id, $email, $name, $phone, $address, $roleId, $plainPassword, $theme]) {
            $rows[] = [
                $id,
                $email,
                $name,
                $phone,
                $address,
                $roleId,
                password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 10]),
                true,
                null,
                null,
                null,
                1,
                1,
                $now,
                $now,
                $theme,
                'floating',
                1,
                null,
            ];
        }

        $count = $db->createCommand()->batchInsert('{{%users}}', [
            'id', 'email', 'name', 'phone', 'address', 'role_id', 'password', 'is_active',
            'last_login_at', 'remember_token', 'avatar', 'created_by', 'updated_by',
            'created_at', 'updated_at', 'theme', 'sidebar_type', 'session_version', 'deleted_at'
        ], $rows)->execute();

        $db->createCommand("SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE((SELECT MAX(id) FROM users), 1));")->execute();

        return $count;
    }
}
