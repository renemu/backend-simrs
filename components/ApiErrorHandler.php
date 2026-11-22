<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\base\UserException;
use yii\web\ErrorHandler;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Custom API ErrorHandler that always outputs structured JSON responses
 * (with CORS headers attached) for 404 Not Found, 401 Unauthorized, 403 Forbidden,
 * 405 Method Not Allowed, 500 Internal Server Error, and uncaught PHP exceptions.
 */
class ApiErrorHandler extends ErrorHandler
{
    public static function formatExceptionPayload(\Throwable $exception): array
    {
        $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
        if ($statusCode < 100 || $statusCode >= 600) {
            $statusCode = 500;
        }

        $message = $exception->getMessage();
        if ($message === '') {
            $message = Response::$httpStatuses[$statusCode] ?? 'An internal server error occurred.';
        }

        if (!YII_DEBUG && !$exception instanceof UserException && !$exception instanceof HttpException) {
            $message = 'An internal server error occurred.';
        }

        $data = [
            'success' => false,
            'status' => $statusCode,
            'error' => Response::$httpStatuses[$statusCode] ?? 'Error',
            'message' => $message,
        ];

        if (YII_DEBUG) {
            $data['debug'] = [
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];
        }

        return $data;
    }

    protected function renderException($exception): void
    {
        $data = self::formatExceptionPayload($exception);
        $statusCode = $data['status'];

        if (Yii::$app->has('response') && !headers_sent()) {
            $response = Yii::$app->getResponse();
            $response->isSent = false;
            $response->stream = null;
            $response->content = null;
            $response->format = Response::FORMAT_JSON;
            $response->setStatusCode($statusCode);
            CorsHelper::applyHeaders($response);
            $response->data = $data;
            $response->send();
            return;
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            CorsHelper::applyHeaders(null);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
