<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * UserLog model matching table `user_logs`.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $actor_name
 * @property int|null $impersonator_id
 * @property string $action
 * @property string $module
 * @property int|null $target_id
 * @property string $description
 * @property string $created_at
 * @property string|null $ip
 * @property string|null $browser
 * @property string|null $location
 */
class UserLog extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%user_logs}}';
    }

    public function rules(): array
    {
        return [
            [['actor_name', 'action', 'module', 'description'], 'required'],
            [['user_id', 'impersonator_id', 'target_id'], 'integer'],
            [['actor_name', 'browser', 'location'], 'string', 'max' => 191],
            [['action'], 'string', 'max' => 40],
            [['module'], 'string', 'max' => 80],
            [['ip'], 'string', 'max' => 45],
            [['description'], 'string'],
            [['created_at'], 'safe'],
        ];
    }

    public static function record(
        string $action,
        string $module,
        string $description,
        ?User $actor = null,
        ?int $targetId = null
    ): void {
        try {
            if ($actor === null && Yii::$app->has('user') && !Yii::$app->user->isGuest) {
                /** @var User $actor */
                $actor = Yii::$app->user->identity;
            }

            $log = new self();
            $log->user_id = $actor?->id;
            $log->actor_name = $actor?->name ?: ($actor?->email ?: 'System');
            $log->action = $action;
            $log->module = $module;
            $log->target_id = $targetId;
            $log->description = $description;
            $log->created_at = date('Y-m-d H:i:s');
            if (Yii::$app->has('request') && Yii::$app->request instanceof \yii\web\Request) {
                $log->ip = Yii::$app->request->userIP;
                $log->browser = substr((string) Yii::$app->request->userAgent, 0, 191);
            }
            $log->save(false);
        } catch (\Throwable) {
            // Silently ignore logging failures so business logic isn't interrupted
        }
    }
}
