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
            'cookieValidationKey' => (string) Env::get('COOKIE_VALIDATION_KEY', ''),
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

                // Activity Logs API (read-only audit trail)
                'GET api/user-logs' => 'api/user-log/index',
                'GET api/user-logs/<id:\d+>' => 'api/user-log/view',

                // Master Data API (Batch 2 — FR-01)
                'GET api/patients' => 'api/patient/index',
                'POST api/patients' => 'api/patient/create',
                'GET api/patients/<id:\d+>' => 'api/patient/view',
                'PUT api/patients/<id:\d+>' => 'api/patient/update',
                'PATCH api/patients/<id:\d+>' => 'api/patient/update',
                'DELETE api/patients/<id:\d+>' => 'api/patient/delete',

                'GET api/departments' => 'api/department/index',
                'POST api/departments' => 'api/department/create',
                'GET api/departments/<id:\d+>' => 'api/department/view',
                'PUT api/departments/<id:\d+>' => 'api/department/update',
                'PATCH api/departments/<id:\d+>' => 'api/department/update',
                'DELETE api/departments/<id:\d+>' => 'api/department/delete',

                'GET api/doctors' => 'api/doctor/index',
                'POST api/doctors' => 'api/doctor/create',
                'GET api/doctors/<id:\d+>' => 'api/doctor/view',
                'PUT api/doctors/<id:\d+>' => 'api/doctor/update',
                'PATCH api/doctors/<id:\d+>' => 'api/doctor/update',
                'DELETE api/doctors/<id:\d+>' => 'api/doctor/delete',

                'GET api/doctor-schedules' => 'api/doctor-schedule/index',
                'POST api/doctor-schedules' => 'api/doctor-schedule/create',
                'GET api/doctor-schedules/<id:\d+>' => 'api/doctor-schedule/view',
                'PUT api/doctor-schedules/<id:\d+>' => 'api/doctor-schedule/update',
                'PATCH api/doctor-schedules/<id:\d+>' => 'api/doctor-schedule/update',
                'DELETE api/doctor-schedules/<id:\d+>' => 'api/doctor-schedule/delete',

                'GET api/procedures' => 'api/procedure/index',
                'POST api/procedures' => 'api/procedure/create',
                'GET api/procedures/<id:\d+>' => 'api/procedure/view',
                'PUT api/procedures/<id:\d+>' => 'api/procedure/update',
                'PATCH api/procedures/<id:\d+>' => 'api/procedure/update',
                'DELETE api/procedures/<id:\d+>' => 'api/procedure/delete',

                'GET api/medicines' => 'api/medicine/index',
                'POST api/medicines' => 'api/medicine/create',
                'GET api/medicines/<id:\d+>' => 'api/medicine/view',
                'PUT api/medicines/<id:\d+>' => 'api/medicine/update',
                'PATCH api/medicines/<id:\d+>' => 'api/medicine/update',
                'DELETE api/medicines/<id:\d+>' => 'api/medicine/delete',

                // Outpatient Visits & Queue API (Batch 3 — FR-02)
                'GET api/visits' => 'api/visit/index',
                'POST api/visits' => 'api/visit/create',
                'GET api/visits/<id:\d+>' => 'api/visit/view',
                'PATCH api/visits/<id:\d+>/queue-status' => 'api/visit/queue-status',
                'PATCH api/visits/<id:\d+>/cancel' => 'api/visit/cancel',

                // Electronic Medical Record & Visit Procedures API (Batch 3 — FR-03, FR-04)
                'GET api/visits/<visitId:\d+>/medical-record' => 'api/medical-record/view',
                'POST api/visits/<visitId:\d+>/medical-record' => 'api/medical-record/upsert',
                'PUT api/visits/<visitId:\d+>/medical-record' => 'api/medical-record/upsert',
                'POST api/visits/<visitId:\d+>/procedures' => 'api/medical-record/add-procedure',
                'PATCH api/visit-procedures/<id:\d+>/cancel' => 'api/medical-record/cancel-procedure',

                // Prescriptions & Pharmacy Fulfillment API (Batch 3 — FR-05)
                'GET api/prescriptions' => 'api/prescription/index',
                'GET api/prescriptions/<id:\d+>' => 'api/prescription/view',
                'POST api/visits/<visitId:\d+>/prescriptions' => 'api/prescription/create',
                'POST api/prescriptions/<id:\d+>/dispense' => 'api/prescription/dispense',
                'PATCH api/prescriptions/<id:\d+>/cancel' => 'api/prescription/cancel',
                'GET api/medicine-stock-movements' => 'api/prescription/stock-movements',

                // Laboratory Orders & Results API (Batch 3 — FR-06)
                'GET api/lab-orders' => 'api/lab-order/index',
                'GET api/lab-orders/<id:\d+>' => 'api/lab-order/view',
                'POST api/visits/<visitId:\d+>/lab-orders' => 'api/lab-order/create',
                'PATCH api/lab-orders/<id:\d+>/status' => 'api/lab-order/update-status',
                'POST api/lab-orders/<id:\d+>/result' => 'api/lab-order/save-result',
                'PUT api/lab-orders/<id:\d+>/result' => 'api/lab-order/save-result',

                // Billing & Idempotent Payments API (Batch 4 — FR-07)
                'GET api/invoices' => 'api/billing/index',
                'GET api/invoices/<id:\d+>' => 'api/billing/view',
                'POST api/visits/<visitId:\d+>/invoices' => 'api/billing/generate',
                'POST api/invoices/<id:\d+>/revise' => 'api/billing/revise',
                'GET api/payments' => 'api/payment/index',
                'POST api/invoices/<invoiceId:\d+>/payments' => 'api/payment/create',

                // Dashboard Aggregations API (Batch 4 — FR-08)
                'GET api/dashboard/metrics' => 'api/dashboard/metrics',
            ],
        ],
    ],
    'params' => $params,
];

if (YII_ENV_DEV) {
    if (class_exists(\yii\debug\Module::class)) {
        $config['bootstrap'][] = 'debug';
        $config['modules']['debug'] = [
            'class' => \yii\debug\Module::class,
        ];
    }

    if (class_exists(\yii\gii\Module::class)) {
        $config['bootstrap'][] = 'gii';
        $config['modules']['gii'] = [
            'class' => \yii\gii\Module::class,
        ];
    }
}

return $config;
