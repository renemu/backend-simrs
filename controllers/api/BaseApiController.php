<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\filters\auth\CompositeAuth;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\auth\QueryParamAuth;
use yii\filters\ContentNegotiator;
use yii\filters\Cors;
use yii\web\Controller;
use yii\web\Response;
use app\components\CorsHelper;

/**
 * Base API Controller with CORS, JSON formatting, and Bearer/Token authentication.
 */
abstract class BaseApiController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * Actions that do not require authentication.
     *
     * @return string[]
     */
    protected function authOptionalActions(): array
    {
        return [];
    }

    public function behaviors(): array
    {
        $behaviors = parent::behaviors();

        // Remove authenticator temporarily so corsFilter executes first
        unset($behaviors['authenticator']);

        $allowedOrigins = CorsHelper::getAllowedOrigins();
        $allowCredentials = CorsHelper::getAllowCredentials() && !in_array('*', $allowedOrigins, true);

        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => $allowedOrigins,
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => $allowCredentials,
                'Access-Control-Max-Age' => CorsHelper::getMaxAge(),
                'Access-Control-Expose-Headers' => array_map('trim', explode(',', CorsHelper::getExposedHeaders())),
            ],
        ];

        $behaviors['contentNegotiator'] = [
            'class' => ContentNegotiator::class,
            'formats' => [
                'application/json' => Response::FORMAT_JSON,
            ],
        ];

        $behaviors['authenticator'] = [
            'class' => CompositeAuth::class,
            'authMethods' => [
                HttpBearerAuth::class,
                [
                    'class' => QueryParamAuth::class,
                    'tokenParam' => 'access_token',
                ],
            ],
            'except' => ['options'],
            'optional' => $this->authOptionalActions(),
        ];

        return $behaviors;
    }

    public function actionOptions(): array
    {
        CorsHelper::applyHeaders(Yii::$app->response);
        Yii::$app->response->statusCode = 200;
        return ['status' => 'ok'];
    }

    protected function getBodyParams(): array
    {
        $params = Yii::$app->request->getBodyParams();
        if (empty($params)) {
            $raw = Yii::$app->request->getRawBody();
            if (!empty($raw)) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return is_array($params) ? $params : [];
    }
}
