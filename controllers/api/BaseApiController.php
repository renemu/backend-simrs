<?php

declare(strict_types=1);

namespace app\controllers\api;

use Yii;
use yii\db\ActiveQuery;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\ContentNegotiator;
use yii\filters\Cors;
use yii\web\Controller;
use yii\web\Response;
use app\components\CorsHelper;

/**
 * Base API Controller with CORS, JSON formatting, strict HTTP Bearer JWT auth,
 * and shared response/pagination helpers.
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
            'class' => HttpBearerAuth::class,
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

    /**
     * Builds a standardized JSON error response with matching HTTP status code.
     */
    protected function errorResponse(int $status, string $message, ?array $errors = null): array
    {
        Yii::$app->response->statusCode = $status;
        $payload = [
            'success' => false,
            'status' => $status,
            'error' => Response::$httpStatuses[$status] ?? 'Error',
            'message' => $message,
        ];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        return $payload;
    }

    /**
     * Shortcut for 422 validation error responses.
     */
    protected function validationError(array $errors, string $message = 'Validation failed.'): array
    {
        return $this->errorResponse(422, $message, $errors);
    }

    /**
     * Applies standardized page/per_page pagination to an ActiveQuery and returns a JSON envelope.
     */
    protected function paginateQuery(ActiveQuery $query, array $orderBy, int $defaultPerPage = 20): array
    {
        $request = Yii::$app->request;
        $total = (int) (clone $query)->count();
        $page = max(1, (int) $request->get('page', 1));
        $perPage = min(100, max(1, (int) $request->get('per_page', $defaultPerPage)));

        $items = $query
            ->orderBy($orderBy)
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return [
            'success' => true,
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }
}
