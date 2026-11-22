<?php

use app\components\Env;

Env::load();

$timezone = (string) Env::get('APP_TIMEZONE', 'Asia/Jakarta');
date_default_timezone_set($timezone);

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => ((string) Env::get('APP_ID', 'simrs-backend-yii2')) . '-console',
    'name' => (string) Env::get('APP_NAME', 'SIMRS Backend API (Yii2)'),
    'version' => (string) Env::get('APP_VERSION', '1.0.0'),
    'timeZone' => $timezone,
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'controllerNamespace' => 'app\commands',
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
        '@tests' => '@app/tests',
    ],
    'components' => [
        'formatter' => [
            'defaultTimeZone' => $timezone,
            'timeZone' => $timezone,
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        'log' => [
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    if (class_exists(\yii\gii\Module::class)) {
        $config['bootstrap'][] = 'gii';
        $config['modules']['gii'] = [
            'class' => \yii\gii\Module::class,
        ];
    }

    if (class_exists(\yii\debug\Module::class)) {
        $config['bootstrap'][] = 'debug';
        $config['modules']['debug'] = [
            'class' => \yii\debug\Module::class,
        ];
    }
}

return $config;
