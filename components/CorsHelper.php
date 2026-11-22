<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\web\Response;

/**
 * Handles CORS headers and preflight OPTIONS requests globally across the API
 * (including normal responses, preflight requests, and error/exception responses).
 * Configured via .env (CORS_ALLOWED_ORIGINS, CORS_ALLOW_CREDENTIALS, etc.).
 */
class CorsHelper
{
    /**
     * Parses comma-separated origins from .env.
     *
     * @return string[]
     */
    public static function getAllowedOrigins(): array
    {
        $raw = (string) Env::get(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,https://frontend.example.com,http://frontend.example.com'
        );

        $items = array_filter(array_map('trim', explode(',', $raw)), fn(string $v) => $v !== '');
        return !empty($items) ? array_values($items) : ['*'];
    }

    public static function getAllowCredentials(): bool
    {
        return (bool) Env::get('CORS_ALLOW_CREDENTIALS', true);
    }

    public static function getAllowedMethods(): string
    {
        return (string) Env::get('CORS_ALLOWED_METHODS', 'GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS');
    }

    public static function getAllowedHeaders(): string
    {
        return (string) Env::get(
            'CORS_ALLOWED_HEADERS',
            'Origin, X-Requested-With, Content-Type, Accept, Authorization, Cache-Control, Pragma'
        );
    }

    public static function getExposedHeaders(): string
    {
        return (string) Env::get(
            'CORS_EXPOSED_HEADERS',
            'X-Pagination-Total-Count, X-Pagination-Page-Count, X-Pagination-Current-Page, X-Pagination-Per-Page'
        );
    }

    public static function getMaxAge(): int
    {
        return (int) Env::get('CORS_MAX_AGE', 86400);
    }

    /**
     * Resolves the allowed Origin header value for the incoming request.
     */
    public static function resolveOrigin(?string $requestOrigin): ?string
    {
        $allowed = self::getAllowedOrigins();

        if (in_array('*', $allowed, true)) {
            if (self::getAllowCredentials() && $requestOrigin !== null && $requestOrigin !== '') {
                return $requestOrigin;
            }
            return '*';
        }

        if ($requestOrigin !== null && $requestOrigin !== '' && in_array($requestOrigin, $allowed, true)) {
            return $requestOrigin;
        }

        return null;
    }

    /**
     * Applies CORS headers to a Yii2 Response object or native PHP headers.
     */
    public static function applyHeaders(?Response $response = null): void
    {
        $requestOrigin = null;
        if (Yii::$app !== null && Yii::$app->has('request') && Yii::$app->request instanceof \yii\web\Request) {
            $requestOrigin = Yii::$app->request->headers->get('Origin');
        }
        if ($requestOrigin === null && isset($_SERVER['HTTP_ORIGIN'])) {
            $requestOrigin = (string) $_SERVER['HTTP_ORIGIN'];
        }

        $resolvedOrigin = self::resolveOrigin($requestOrigin);
        if ($resolvedOrigin === null) {
            return;
        }

        $allowCredentials = self::getAllowCredentials() && $resolvedOrigin !== '*';

        $headers = [
            'Access-Control-Allow-Origin' => $resolvedOrigin,
            'Access-Control-Allow-Methods' => self::getAllowedMethods(),
            'Access-Control-Allow-Headers' => self::getAllowedHeaders(),
            'Access-Control-Expose-Headers' => self::getExposedHeaders(),
            'Access-Control-Max-Age' => (string) self::getMaxAge(),
            'Vary' => 'Origin',
        ];

        if ($allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($response !== null) {
            $headerCollection = $response->getHeaders();
            foreach ($headers as $name => $value) {
                $headerCollection->set($name, $value);
            }
        } elseif (!headers_sent()) {
            foreach ($headers as $name => $value) {
                header("$name: $value");
            }
        }
    }
}
