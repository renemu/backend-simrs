<?php

declare(strict_types=1);

namespace app\components;

use Yii;
use yii\base\ActionFilter;
use yii\web\HttpException;
use yii\web\UnauthorizedHttpException;

/**
 * Verifies HMAC-SHA256 machine-to-machine signatures for internal service calls
 * (e.g., backend-node forwarding verified external RIS/PACS webhook callbacks).
 *
 * Required headers:
 *   - X-Service-Name: caller identifier (e.g., simrs-backend-node)
 *   - X-Service-Timestamp: Unix epoch seconds (must be within ±INTERNAL_SERVICE_MAX_SKEW_SEC)
 *   - X-Service-Signature: sha256=<hex_hmac_over_"${timestamp}.${rawBody}">
 */
class InternalServiceAuthFilter extends ActionFilter
{
    public function beforeAction($action): bool
    {
        $secret = trim((string) Env::get('INTERNAL_SERVICE_SECRET', ''));
        if ($secret === '') {
            throw new HttpException(503, 'Internal service authentication is not configured.');
        }

        $request = Yii::$app->request;
        $serviceName = trim((string) $request->headers->get('X-Service-Name', ''));
        $timestampRaw = trim((string) $request->headers->get('X-Service-Timestamp', ''));
        $signatureHeader = trim((string) $request->headers->get('X-Service-Signature', ''));

        if ($serviceName === '' || $timestampRaw === '' || $signatureHeader === '') {
            throw new UnauthorizedHttpException('Missing internal service authentication headers.');
        }

        if (!ctype_digit($timestampRaw)) {
            throw new UnauthorizedHttpException('Invalid internal service timestamp.');
        }

        $timestamp = (int) $timestampRaw;
        $maxSkew = max(30, (int) Env::get('INTERNAL_SERVICE_MAX_SKEW_SEC', '300'));
        if (abs(time() - $timestamp) > $maxSkew) {
            throw new UnauthorizedHttpException('Internal service request timestamp is expired or out of range.');
        }

        if (!str_starts_with($signatureHeader, 'sha256=')) {
            throw new UnauthorizedHttpException('Malformed internal service signature format.');
        }

        $providedSig = substr($signatureHeader, 7);
        $rawBody = (string) $request->getRawBody();
        $expectedSig = hash_hmac('sha256', $timestampRaw . '.' . $rawBody, $secret);

        if (!hash_equals($expectedSig, $providedSig)) {
            throw new UnauthorizedHttpException('Invalid internal service signature.');
        }

        return true;
    }
}
