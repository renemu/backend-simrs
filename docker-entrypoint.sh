#!/bin/sh
set -eu

mkdir -p /app/runtime /app/web/assets
chmod -R 775 /app/runtime /app/web/assets

echo "[backend-yii2] Running database migrations..."
php /app/yii migrate --interactive=0

echo "[backend-yii2] Checking initial seed state..."
USER_COUNT=$(php -r '
$host = getenv("DB_HOST") ?: "postgres";
$port = getenv("DB_PORT") ?: "5432";
$db   = getenv("DB_DATABASE") ?: "simrs_db";
$user = getenv("DB_USERNAME") ?: "postgres";
$pass = getenv("DB_PASSWORD") ?: "postgres";
$pdo  = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
echo (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
')

if [ "$USER_COUNT" -eq 0 ]; then
  echo "[backend-yii2] Users table is empty — running initial seeder..."
  php /app/yii seed
else
  echo "[backend-yii2] Seed data already exists ($USER_COUNT users) — skipping seeder."
fi

echo "[backend-yii2] Starting HTTP server on 0.0.0.0:8080 (workers=${PHP_CLI_SERVER_WORKERS:-4})..."
exec php -S 0.0.0.0:8080 -t /app/web /app/web/router.php
