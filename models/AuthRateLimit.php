<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * AuthRateLimit model matching table `auth_rate_limits` for brute-force login protection.
 *
 * @property string $key
 * @property int $attempts
 * @property string $expires_at
 */
class AuthRateLimit extends ActiveRecord
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_SECONDS = 900; // 15 minutes

    public static function tableName(): string
    {
        return '{{%auth_rate_limits}}';
    }

    public static function buildKey(string $identifier, ?string $ip): string
    {
        $normalized = strtolower(trim($identifier)) . '|' . trim((string) $ip);
        return hash('sha256', $normalized);
    }

    /**
     * Checks whether the key is currently blocked. Returns remaining seconds if blocked, or 0 if allowed.
     */
    public static function getRetryAfterSeconds(string $key): int
    {
        $record = static::findOne(['key' => $key]);
        if ($record === null) {
            return 0;
        }

        $expiresTs = strtotime((string) $record->expires_at);
        if ($expiresTs === false || $expiresTs <= time()) {
            $record->delete();
            return 0;
        }

        if ((int) $record->attempts >= self::MAX_ATTEMPTS) {
            return max(1, $expiresTs - time());
        }

        return 0;
    }

    /**
     * Records a failed login attempt for the given key.
     */
    public static function hit(string $key): void
    {
        try {
            $now = time();
            $expiresAt = date('Y-m-d H:i:s', $now + self::WINDOW_SECONDS);

            $record = static::findOne(['key' => $key]);
            if ($record === null) {
                $record = new self();
                $record->key = $key;
                $record->attempts = 1;
                $record->expires_at = $expiresAt;
                $record->save(false);
                return;
            }

            $expiresTs = strtotime((string) $record->expires_at);
            if ($expiresTs === false || $expiresTs <= $now) {
                $record->attempts = 1;
                $record->expires_at = $expiresAt;
            } else {
                $record->attempts = (int) $record->attempts + 1;
            }
            $record->save(false);
        } catch (\Throwable) {
            // Do not crash login flow if rate-limit table write fails
        }
    }

    /**
     * Clears rate limit tracking on successful authentication.
     */
    public static function clear(string $key): void
    {
        try {
            static::deleteAll(['key' => $key]);
        } catch (\Throwable) {
            // Ignore cleanup failure
        }
    }
}
