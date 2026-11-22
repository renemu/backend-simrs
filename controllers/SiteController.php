<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\web\Controller;

class SiteController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * GET /
     * Displays API root info in JSON format using APP_NAME, APP_VERSION, and APP_TIMEZONE from .env.
     */
    public function actionIndex(): array
    {
        return [
            'success' => true,
            'name' => Yii::$app->name,
            'version' => Yii::$app->version,
            'environment' => YII_ENV,
            'timezone' => Yii::$app->timeZone,
            'server_time' => date('Y-m-d H:i:s'),
            'status' => 'online',
        ];
    }

    /**
     * Fallback error action returning JSON.
     */
    public function actionError(): array
    {
        $exception = Yii::$app->errorHandler->exception;
        $statusCode = $exception instanceof \yii\web\HttpException ? $exception->statusCode : 500;
        Yii::$app->response->statusCode = $statusCode;

        return [
            'success' => false,
            'status' => $statusCode,
            'error' => \yii\web\Response::$httpStatuses[$statusCode] ?? 'Error',
            'message' => $exception ? $exception->getMessage() : 'Not Found',
        ];
    }
}
