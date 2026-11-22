<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../components/Env.php';

\app\components\Env::load(dirname(__DIR__) . '/.env');

defined('YII_DEBUG') or define('YII_DEBUG', (bool) \app\components\Env::get('APP_DEBUG', true));
defined('YII_ENV') or define('YII_ENV', (string) \app\components\Env::get('APP_ENV', 'dev'));

require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();
