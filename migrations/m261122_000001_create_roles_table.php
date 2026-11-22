<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Handles the creation and seeding of table `roles`.
 */
class m261122_000001_create_roles_table extends Migration
{
    public function safeUp()
    {
        $this->createTable('{{%roles}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string(191)->notNull()->unique(),
            'display_name' => $this->string(191)->notNull(),
            'module_permission' => $this->text()->null(),
            'created_by' => $this->integer()->null(),
            'updated_by' => $this->integer()->null(),
            'created_at' => $this->timestamp(3)->defaultExpression('CURRENT_TIMESTAMP'),
            'updated_at' => $this->timestamp(3)->null(),
        ]);

        // Seeder for roles
        $this->batchInsert('{{%roles}}', [
            'id', 'name', 'display_name', 'module_permission', 'created_by', 'updated_by', 'created_at', 'updated_at'
        ], [
            [
                1,
                'superadmin',
                'Super Administrator',
                '[{"read": true, "create": true, "delete": true, "update": true, "module_code": "dashboard"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "settings"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "users"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "users-list"}, {"read": true, "create": true, "delete": true, "update": true, "module_code": "users-roles"}, {"read": true, "create": false, "delete": false, "update": true, "module_code": "settings-appearance"}, {"read": true, "create": false, "delete": false, "update": true, "module_code": "settings-apps"}, {"read": true, "create": false, "delete": false, "update": false, "module_code": "settings-integration"}, {"read": true, "create": false, "delete": false, "update": true, "module_code": "settings-modules"}, {"read": true, "create": false, "delete": false, "update": false, "module_code": "users-logs"}]',
                null,
                null,
                '2026-10-21 06:36:00.231',
                '2026-10-23 16:06:45.208'
            ],
            [
                2,
                'admin',
                'Administrator',
                '[{"module_code":"dashboard","create":false,"read":true,"update":false,"delete":false},{"module_code":"notification","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-appearance","create":false,"read":true,"update":false,"delete":false},{"module_code":"settings-apps","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-integration","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-modules","create":false,"read":true,"update":true,"delete":false},{"module_code":"users","create":false,"read":true,"update":false,"delete":false},{"module_code":"users-list","create":true,"read":true,"update":true,"delete":true},{"module_code":"users-logs","create":false,"read":true,"update":false,"delete":false},{"module_code":"users-roles","create":false,"read":true,"update":false,"delete":false}]',
                null,
                4,
                '2026-10-21 04:20:42.307',
                '2026-10-28 01:53:47.053'
            ],
            [
                3,
                'user',
                'Regular User',
                '[{"module_code":"dashboard","create":false,"read":true,"update":false,"delete":false},{"module_code":"notification","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-appearance","create":false,"read":true,"update":true,"delete":false},{"module_code":"settings-apps","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-integration","create":false,"read":false,"update":false,"delete":false},{"module_code":"settings-modules","create":false,"read":false,"update":false,"delete":false},{"module_code":"users","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-list","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-logs","create":false,"read":false,"update":false,"delete":false},{"module_code":"users-roles","create":false,"read":false,"update":false,"delete":false}]',
                null,
                4,
                '2026-10-21 04:20:42.313',
                '2026-10-27 02:17:04.530'
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
        ]);

        $this->execute("SELECT setval(pg_get_serial_sequence('roles', 'id'), COALESCE((SELECT MAX(id) FROM roles), 1));");
    }

    public function safeDown()
    {
        $this->dropTable('{{%roles}}');
    }
}
