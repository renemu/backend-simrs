<?php

use app\components\Env;

Env::load();

return [
    'appName' => (string) Env::get('APP_NAME', 'SIMRS Backend API (Yii2)'),
    'appVersion' => (string) Env::get('APP_VERSION', '1.0.0'),
    'adminEmail' => (string) Env::get('ADMIN_EMAIL', 'admin@example.com'),
    'senderEmail' => (string) Env::get('SENDER_EMAIL', 'noreply@example.com'),
    'senderName' => (string) Env::get('SENDER_NAME', 'SIMRS Mailer'),
    'jwtSecret' => (string) Env::get('JWT_SECRET', ''),
    'jwtTtl' => (int) Env::get('JWT_TTL', 604800),
];
