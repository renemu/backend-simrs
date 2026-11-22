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

        $behaviors['corsFilter'] = [
            'class' => Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => false,
                'Access-Control-Max-Age' => 86400,
                'Access-Control-Expose-Headers' => ['X-Pagination-Total-Count', 'X-Pagination-Page-Count', 'X-Pagination-Current-Page', 'X-Pagination-Per-Page'],
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
