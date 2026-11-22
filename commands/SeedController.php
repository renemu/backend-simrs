<?php

declare(strict_types=1);

namespace app\commands;

use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use app\seeders\AuthRateLimitSeeder;
use app\seeders\DatabaseSeeder;
use app\seeders\ModuleSeeder;
use app\seeders\RoleSeeder;
use app\seeders\UserLogSeeder;
use app\seeders\UserSeeder;

/**
 * Runs database seeders independently from migrations.
 *
 * Usage:
 *   ./yii seed             # Run all seeders
 *   ./yii seed/roles       # Seed roles table only
 *   ./yii seed/modules     # Seed modules table only
 *   ./yii seed/users       # Seed users table only
 *   ./yii seed/user-logs   # Seed user_logs table only
 *   ./yii seed/rate-limits # Seed auth_rate_limits table only
 */
class SeedController extends Controller
{
    public $defaultAction = 'index';

    /**
     * Runs all seeders (roles, modules, users, user_logs, auth_rate_limits).
     */
    public function actionIndex(): int
    {
        $this->stdout("Running all database seeders...\n", Console::FG_CYAN);
        $results = DatabaseSeeder::run();

        foreach ($results as $table => $count) {
            $this->stdout(sprintf("  [OK] Seeded %-18s (%d rows)\n", $table, $count), Console::FG_GREEN);
        }

        $this->stdout("Database seeding completed successfully.\n", Console::FG_GREEN, Console::BOLD);
        return ExitCode::OK;
    }

    /**
     * Seeds only the `roles` table.
     */
    public function actionRoles(): int
    {
        $count = RoleSeeder::run();
        $this->stdout("Seeded roles ($count rows).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Seeds only the `modules` table.
     */
    public function actionModules(): int
    {
        $count = ModuleSeeder::run();
        $this->stdout("Seeded modules ($count rows).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Seeds only the `users` table.
     */
    public function actionUsers(): int
    {
        $count = UserSeeder::run();
        $this->stdout("Seeded users ($count rows).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Seeds only the `user_logs` table.
     */
    public function actionUserLogs(): int
    {
        $count = UserLogSeeder::run();
        $this->stdout("Seeded user_logs ($count rows).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Seeds only the `auth_rate_limits` table.
     */
    public function actionRateLimits(): int
    {
        $count = AuthRateLimitSeeder::run();
        $this->stdout("Seeded auth_rate_limits ($count rows).\n", Console::FG_GREEN);
        return ExitCode::OK;
    }
}
