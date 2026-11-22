<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation and seeding of table `users`.
 */
class m261122_000003_create_users_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%users}}', [
            'id' => $this->primaryKey(),
            'email' => $this->string(191)->notNull()->unique(),
            'name' => $this->string(191)->null(),
            'phone' => $this->string(191)->null(),
            'address' => $this->string(191)->null(),
            'role_id' => $this->integer()->null(),
            'password' => $this->string(191)->null(),
            'is_active' => $this->boolean()->notNull()->defaultValue(true),
            'last_login_at' => $this->timestamp(3)->null(),
            'remember_token' => $this->string(191)->null(),
            'avatar' => $this->string(191)->null(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
            'theme' => $this->string(32)->notNull()->defaultValue('default'),
            'sidebar_type' => $this->string(16)->notNull()->defaultValue('floating'),
            'session_version' => $this->integer()->notNull()->defaultValue(0),
            'deleted_at' => $this->timestamp(3)->null(),
        ]);

        $this->createIndex('idx-users-role_id', '{{%users}}', 'role_id');
        $this->createIndex('idx-users-deleted_at', '{{%users}}', 'deleted_at');
        $this->addForeignKey(
            'fk-users-role_id',
            '{{%users}}',
            'role_id',
            '{{%roles}}',
            'id',
            'SET NULL',
            'CASCADE'
        );

        // Seeder for users
        $this->batchInsert('{{%users}}', [
            'id', 'email', 'name', 'phone', 'address', 'role_id', 'password', 'is_active',
            'last_login_at', 'remember_token', 'avatar', 'created_by', 'updated_by',
            'created_at', 'updated_at', 'theme', 'sidebar_type', 'session_version', 'deleted_at'
        ], [
            [1, 'admin@example.com', 'Admin test', null, null, 1, '$2b$10$tt8L4.rWEYy4XCklf0ZEve.fzhyXeqPWmI1RMHc.PGGVTCwNtVeD2', true, '2026-10-28 01:44:48.017', null, null, null, 1, '2026-10-21 04:20:42.408', '2026-10-28 07:32:17.285', 'default', 'floating', 2, null],
            [2, 'user@example.com', 'User Biasa', null, null, 2, '$2b$10$SI1IuFh3TBnW3f/owWMOHuvs3iBpyLTiJY1HZiaTL65bdOLbeiiJm', true, '2026-10-28 01:31:09.537', null, null, null, 4, '2026-10-21 04:20:42.482', '2026-10-28 01:31:59.845', 'default', 'inset', 16, null],
            [4, 'superadmin@example.com', 'Super Admin', null, null, 3, '$2b$10$hs/.SRxP2QCNhIVXIn5NquOE/3/bAU1hG3GZc4mEO3No1sF2S.78G', true, '2026-11-02 01:52:13.752', null, 'avatars/4/9fa7ac75-3b3a-4f56-8c95-74c1655bb29f.webp', null, 4, '2026-10-21 06:36:00.381', '2026-11-02 02:31:40.733', 'caffeine', 'floating', 35, null],
            [6, 'user2@example.com', 'Aditya Pratama', null, null, 2, '$2b$10$eJa5cIkcTHD/9PTCyTb64OTsdaYUxUImC7r8uKl973GFq1n8pSRt6', true, null, null, null, null, 4, '2026-10-23 16:06:45.569', '2026-10-27 16:26:30.287', 'supabase', 'floating', 2, null],
            [7, 'user3@example.com', 'Nadia Putri', null, null, 2, '$2b$10$ss8rG3O1YRX3Ju0WlYbto.GIOFMap.ahU1ABLpxIW4kO2T/qVkTeO', true, null, null, null, null, null, '2026-10-23 16:06:45.644', '2026-10-23 17:03:52.701', 'claude', 'sidebar', 0, null],
            [8, 'user4@example.com', 'Rizky Ramadhan', null, null, 2, '$2b$10$UXJQg1lL/0GPsuOxmZeX2uDghT7JV8t9GAFNqmXxqmfTFRXnOZ4D.', true, null, null, null, null, null, '2026-10-23 16:06:45.718', '2026-10-23 16:06:45.718', 'default', 'floating', 0, null],
            [9, 'user5@example.com', 'Dewi Lestari', null, null, 2, '$2b$10$DskBP07UfPEiZWxvbyZAHeytuPD99zawnrSFAtxVZ/VQealnVC9EW', true, null, null, null, null, null, '2026-10-23 16:06:45.789', '2026-10-23 16:06:45.789', 'default', 'floating', 0, null],
            [10, 'user6@example.com', 'Fajar Saputra', null, null, 2, '$2b$10$3eSOdwAuyFoQVGXc1iBaOO9Ep0KGt4IbJOwfABcyB/9Nhe7dI1AhK', true, null, null, null, null, null, '2026-10-23 16:06:45.861', '2026-10-23 16:06:45.861', 'default', 'floating', 0, null],
            [11, 'user7@example.com', 'Aulia Rahma', null, null, 2, '$2b$10$.VCj.DrpTt5JcPk26e6kDOAYHNn065HnJXRlYJLzcEArM.UInyMTW', true, null, null, null, null, null, '2026-10-23 16:06:45.939', '2026-10-23 16:06:45.939', 'default', 'floating', 0, null],
            [12, 'user8@example.com', 'Bagas Firmansyah', null, null, 2, '$2b$10$5B2lAvwVITkgV9c8Crx7pO8TwO6gsecbTFqmVZgV0r277e1sQ308i', true, null, null, null, null, null, '2026-10-23 16:06:46.014', '2026-10-23 16:06:46.014', 'default', 'floating', 0, null],
            [13, 'user9@example.com', 'Citra Maharani', null, null, 2, '$2b$10$cC0Z0CUjSj6A5s2eDZhq/ep48fJ9QLWOV09wfjaQsXKfrP6q14rOC', true, null, null, null, null, null, '2026-10-23 16:06:46.088', '2026-10-23 16:06:46.088', 'default', 'floating', 0, null],
            [14, 'user10@example.com', 'Dimas Wicaksono', null, null, 2, '$2b$10$BfTkMZ.VYGuK/ueEnmUTW.J8aeHc04q61aTmlTsiW5Fvs/fgz62PO', true, null, null, null, null, null, '2026-10-23 16:06:46.162', '2026-10-23 16:06:46.162', 'default', 'floating', 0, null],
            [15, 'user11@example.com', 'Intan Permata', null, null, 2, '$2b$10$wjmusVbeSep3IUdw3QwKpeU7BExSIk.l9hufqRheTYS1pDsWhtl8m', true, null, null, null, null, null, '2026-10-23 16:06:46.235', '2026-10-23 16:06:46.235', 'default', 'floating', 0, null],
            [16, 'user12@example.com', 'Galih Nugroho', null, null, 2, '$2b$10$caCDpeeYvGj2C3OGGhbZZuKy26hn/VKP9je28b4Ud8ZrEvuxDtyHu', true, null, null, null, null, null, '2026-10-23 16:06:46.308', '2026-10-23 16:06:46.308', 'default', 'floating', 0, null],
            [17, 'user13@example.com', 'Maya Anggraini', null, null, 2, '$2b$10$0HUT74ohiwqYV.n0DEeVjeU4UALzewrOfFKbcRD.gm3XuvwbObDwW', true, null, null, null, null, null, '2026-10-23 16:06:46.382', '2026-10-23 16:06:46.382', 'default', 'floating', 0, null],
            [18, 'user14@example.com', 'Rafi Hidayat', null, null, 2, '$2b$10$wMgvdCzoR2vrQ81SWGNyrui3rpDFEhT1VA5Z7PpIC1F4rhQ9wLPlG', true, null, null, null, null, null, '2026-10-23 16:06:46.457', '2026-10-23 16:06:46.457', 'default', 'floating', 0, null],
            [19, 'user15@example.com', 'Salsa Amalia', null, null, 2, '$2b$10$jOw7f9E0ZhMYLOZcRzObiOBYoPrPMgv33AXYwJDLOFjxhxhIkeojS', true, null, null, null, null, null, '2026-10-23 16:06:46.532', '2026-10-23 16:06:46.532', 'default', 'floating', 0, null],
            [20, 'user16@example.com', 'Yoga Kurniawan', null, null, 2, '$2b$10$t/RJSjVBZD8fZwsxYbFYI.BS1nhppuVY2ycc3pIWA8ZUzrlVHE4me', true, null, null, null, null, null, '2026-10-23 16:06:46.605', '2026-10-23 16:06:46.605', 'default', 'floating', 0, null],
            [21, 'user17@example.com', 'Tiara Safitri', null, null, 2, '$2b$10$tsy0kZI7MhYhAjnSpnGKceJk43ntyVxB.NqZ2c1xooW/2W1ZsPiUK', true, null, null, null, null, null, '2026-10-23 16:06:46.678', '2026-10-23 16:06:46.678', 'default', 'floating', 0, null],
            [22, 'user18@example.com', 'Farhan Hakim', null, null, 2, '$2b$10$yI3UU3mFyNRXKQgHmiNpyOtNMuRgzg3KoKgMXmYCRE0RGcs3Xofti', true, null, null, null, null, null, '2026-10-23 16:06:46.751', '2026-10-23 16:06:46.751', 'default', 'floating', 0, null],
        ]);

        $this->execute("SELECT setval(pg_get_serial_sequence('users', 'id'), COALESCE((SELECT MAX(id) FROM users), 1));");
    }

    public function safeDown()
    {
        $this->dropTable('{{%users}}');
    }
}
