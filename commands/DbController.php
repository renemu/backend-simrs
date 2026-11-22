<?php

declare(strict_types=1);

namespace app\commands;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use app\seeders\DatabaseSeeder;

/**
 * Database migration and seeding helper commands.
 *
 * Examples:
 *   ./yii db/migrate            # Run pending migrations only (no seeder)
 *   ./yii db/migrate --seed=1   # Run pending migrations + run seeders
 *   ./yii db/fresh              # Drop & re-run all migrations only (no seeder)
 *   ./yii db/fresh --seed=1     # Drop & re-run all migrations + run seeders
 *   ./yii db/fresh-seed         # Shortcut: Drop & re-run all migrations + run seeders
 *   ./yii db/seed               # Run all seeders only
 */
class DbController extends Controller
{
    public $defaultAction = 'migrate';

    /**
     * @var bool Whether to run database seeders after migration (`--seed=1` or `--seed`).
     */
    public bool $seed = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['seed']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            's' => 'seed',
        ]);
    }

    /**
     * Runs pending migrations (schema only, or with seeder when `--seed=1` is passed).
     */
    public function actionMigrate(): int
    {
        $this->stdout("==> Running pending migrations...\n", Console::FG_CYAN);
        $status = Yii::$app->runAction('migrate/up', ['interactive' => false]);
        if ($status !== ExitCode::OK) {
            return (int) $status;
        }

        if ($this->seed) {
            return $this->actionSeed();
        }

        return ExitCode::OK;
    }

    /**
     * Rolls back all migrations and re-applies them from scratch.
     * Pass `--seed=1` (or `-s=1`) to also run seeders afterwards.
     */
    public function actionFresh(): int
    {
        $this->stdout("==> Rolling back all migrations...\n", Console::FG_YELLOW);
        Yii::$app->runAction('migrate/down', ['all', 'interactive' => false]);

        $this->stdout("==> Applying all migrations...\n", Console::FG_CYAN);
        $status = Yii::$app->runAction('migrate/up', ['interactive' => false]);
        if ($status !== ExitCode::OK) {
            return (int) $status;
        }

        if ($this->seed) {
            return $this->actionSeed();
        }

        $this->stdout("Fresh migration completed (without seeder).\n", Console::FG_GREEN, Console::BOLD);
        return ExitCode::OK;
    }

    /**
     * Shortcut to re-run all migrations from scratch AND run all seeders.
     */
    public function actionFreshSeed(): int
    {
        $this->seed = true;
        return $this->actionFresh();
    }

    /**
     * Runs all database seeders without running migrations.
     */
    public function actionSeed(): int
    {
        $this->stdout("==> Running database seeders...\n", Console::FG_CYAN);
        $results = DatabaseSeeder::run();

        foreach ($results as $table => $count) {
            $this->stdout(sprintf("  [OK] Seeded %-18s (%d rows)\n", $table, $count), Console::FG_GREEN);
        }

        $this->stdout("Database seeding completed successfully.\n", Console::FG_GREEN, Console::BOLD);
        return ExitCode::OK;
    }
}
