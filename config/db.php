<?php

use app\components\Env;

Env::load();

$driver = (string) Env::get('DB_CONNECTION', 'pgsql');
$host = (string) Env::get('DB_HOST', '127.0.0.1');
$port = (string) Env::get('DB_PORT', '5432');
$dbname = (string) Env::get('DB_DATABASE', 'simrs_db');
$dsn = (string) Env::get('DB_DSN', sprintf('%s:host=%s;port=%s;dbname=%s', $driver, $host, $port, $dbname));

return [
    'class' => \yii\db\Connection::class,
    'dsn' => $dsn,
    'username' => (string) Env::get('DB_USERNAME', 'postgres'),
    'password' => (string) Env::get('DB_PASSWORD', 'postgres'),
    'charset' => (string) Env::get('DB_CHARSET', 'utf8'),

    // Schema cache options (controlled via .env)
    'enableSchemaCache' => (bool) Env::get('DB_SCHEMA_CACHE', false),
    'schemaCacheDuration' => (int) Env::get('DB_SCHEMA_CACHE_DURATION', 60),
    'schemaCache' => 'cache',
];
