<?php

declare(strict_types=1);

namespace app\components;

use Yii;

/**
 * Stateless HMAC-SHA256 JWT Helper for API Authentication.
 */
class JwtHelper
{
    private static function getSecret(): string
    {
        return Yii::$app->params['jwtSecret'] ?? 'simrs-yii2-jwt-secret-key-2026-change-in-production';
    }

    private static function getTtl(): int
    {
        return (int) (Yii::$app->params['jwtTtl'] ?? 86400 * 7); // 7 days default
    }

    public static function generateToken(array $claims, ?int $ttl = null): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + ($ttl ?? self::getTtl()),
        ]);

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, self::getSecret(), true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    public static function verifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headB64, $bodyB64, $cryptoB64] = $parts;

        $header = json_decode(self::base64UrlDecode($headB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($bodyB64), true);
        if (!is_array($payload)) {
            return null;
        }

        $signature = self::base64UrlDecode($cryptoB64);
        $expectedSignature = hash_hmac('sha256', "$headB64.$bodyB64", self::getSecret(), true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
            return null;
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}
