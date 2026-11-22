<?php

use app\components\CorsHelper;
use app\components\Env;

Env::load();

$timezone = (string) Env::get('APP_TIMEZONE', 'Asia/Jakarta');
date_default_timezone_set($timezone);

$params = require __DIR__ . '/params.php';
$db = require __DIR__ . '/db.php';

$config = [
    'id' => (string) Env::get('APP_ID', 'simrs-backend-yii2'),
    'name' => (string) Env::get('APP_NAME', 'SIMRS Backend API (Yii2)'),
    'version' => (string) Env::get('APP_VERSION', '1.0.0'),
    'timeZone' => $timezone,
    'basePath' => dirname(__DIR__),
    'bootstrap' => ['log'],
    'on beforeRequest' => function () {
        $request = \Yii::$app->request;
        $response = \Yii::$app->response;
        CorsHelper::applyHeaders($response);

        // Immediately respond to any CORS preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            $response->statusCode = 204;
            $response->content = '';
            $response->send();
            \Yii::$app->end();
        }
    },
    'container' => [
        'singletons' => [
            \yii\mail\MailerInterface::class => [
                'class' => \yii\symfonymailer\Mailer::class,
                // send all mails to a file by default.
                'useFileTransport' => true,
                'viewPath' => '@app/mail',
            ],
        ],
    ],
    'aliases' => [
        '@bower' => '@vendor/bower-asset',
        '@npm'   => '@vendor/npm-asset',
    ],
    'components' => [
        'formatter' => [
            'defaultTimeZone' => $timezone,
            'timeZone' => $timezone,
        ],
        'request' => [
            'cookieValidationKey' => (string) Env::get('COOKIE_VALIDATION_KEY', 'mSiaHuTNwdauegmAUvyFdqp97p7SduO5'),
            'parsers' => [
                'application/json' => \yii\web\JsonParser::class,
            ],
        ],
        'response' => [
            'format' => \yii\web\Response::FORMAT_JSON,
            'charset' => 'UTF-8',
            'on beforeSend' => function ($event) {
                /** @var \yii\web\Response $response */
                $response = $event->sender;
                $response->format = \yii\web\Response::FORMAT_JSON;
                CorsHelper::applyHeaders($response);

                if (!$response->isSuccessful) {
                    $statusCode = $response->statusCode ?: 500;
                    $raw = $response->data;

                    if (is_array($raw) && array_key_exists('success', $raw)) {
                        if (!isset($raw['status'])) {
                            $response->data['status'] = $statusCode;
                        }
                        return;
                    }

                    $message = is_array($raw)
                        ? ($raw['message'] ?? (\yii\web\Response::$httpStatuses[$statusCode] ?? 'An error occurred.'))
                        : (is_string($raw) && $raw !== '' ? $raw : (\yii\web\Response::$httpStatuses[$statusCode] ?? 'An error occurred.'));

                    $payload = [
                        'success' => false,
                        'status' => $statusCode,
                        'error' => \yii\web\Response::$httpStatuses[$statusCode] ?? 'Error',
                        'message' => $message,
                    ];

                    if (is_array($raw) && isset($raw['errors'])) {
                        $payload['errors'] = $raw['errors'];
                    }
                    if (\app\components\ApiErrorHandler::isDebugEnabled() && is_array($raw) && isset($raw['debug'])) {
                        $payload['debug'] = $raw['debug'];
                    } elseif (isset($payload['debug'])) {
                        unset($payload['debug']);
                    }

                    $response->data = $payload;
                }
            },
        ],
        'cache' => [
            'class' => \yii\caching\FileCache::class,
        ],
        'user' => [
            'identityClass' => \app\models\User::class,
            'enableAutoLogin' => true,
            'enableSession' => false,
            'loginUrl' => null,
        ],
        'errorHandler' => [
            'class' => \app\components\ApiErrorHandler::class,
        ],
        'mailer' => \yii\mail\MailerInterface::class,
        'log' => [
            'traceLevel' => YII_DEBUG ? 3 : 0,
            'targets' => [
                [
                    'class' => \yii\log\FileTarget::class,
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'db' => $db,
        'urlManager' => [
            'enablePrettyUrl' => true,
            'showScriptName' => false,
            'rules' => [
                '' => 'site/index',

                // CORS preflight for all endpoints
                'OPTIONS <path:.*>' => 'api/auth/options',

                // Auth API
                'POST api/auth/login' => 'api/auth/login',
                'POST api/auth/register' => 'api/auth/register',
                'POST api/auth/logout' => 'api/auth/logout',
                'GET api/auth/me' => 'api/auth/me',
                'GET api/auth/permissions' => 'api/auth/permissions',
                'PUT api/auth/profile' => 'api/auth/profile',
                'PATCH api/auth/profile' => 'api/auth/profile',

                // Users API
                'GET api/users' => 'api/user/index',
                'POST api/users' => 'api/user/create',
                'GET api/users/<id:\d+>' => 'api/user/view',
                'PUT api/users/<id:\d+>' => 'api/user/update',
                'PATCH api/users/<id:\d+>' => 'api/user/update',
                'DELETE api/users/<id:\d+>' => 'api/user/delete',

                // Roles API
                'GET api/roles' => 'api/role/index',
                'POST api/roles' => 'api/role/create',
                'GET api/roles/<id:\d+>' => 'api/role/view',
                'PUT api/roles/<id:\d+>' => 'api/role/update',
                'PATCH api/roles/<id:\d+>' => 'api/role/update',
                'PUT api/roles/<id:\d+>/permissions' => 'api/role/permissions',
                'PATCH api/roles/<id:\d+>/permissions' => 'api/role/permissions',
                'DELETE api/roles/<id:\d+>' => 'api/role/delete',

                // Modules API
                'GET api/modules' => 'api/module/index',
                'GET api/modules/tree' => 'api/module/tree',
                'POST api/modules' => 'api/module/create',
                'GET api/modules/<id:\d+>' => 'api/module/view',
                'PUT api/modules/<id:\d+>' => 'api/module/update',
                'PATCH api/modules/<id:\d+>' => 'api/module/update',
                'DELETE api/modules/<id:\d+>' => 'api/module/delete',
            ],
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    // configuration adjustments for 'dev' environment
    $config['bootstrap'][] = 'debug';
    $config['modules']['debug'] = [
        'class' => \yii\debug\Module::class,
    ];

    $config['bootstrap'][] = 'gii';
    $config['modules']['gii'] = [
        'class' => \yii\gii\Module::class,
    ];
}

return $config;
