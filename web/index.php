<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Env.php';

\app\components\Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string) \app\components\Env::get('APP_TIMEZONE', 'Asia/Jakarta'));

$appEnv = strtolower(trim((string) \app\components\Env::get('APP_ENV', 'dev')));
$isProduction = in_array($appEnv, ['prod', 'production'], true);
$appDebug = $isProduction ? false : (bool) \app\components\Env::get('APP_DEBUG', true);

defined('YII_DEBUG') or define('YII_DEBUG', $appDebug);
defined('YII_ENV') or define('YII_ENV', $isProduction ? 'prod' : $appEnv);

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
