<?php

declare(strict_types=1);

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * LoginForm is the model behind the login form and API login endpoint.
 *
 * @property-read User|null $user
 */
class LoginForm extends Model
{
    public string $email = '';
    public string $username = '';
    public string $password = '';
    public bool $rememberMe = true;
    private ?User $_user = null;
    private bool $_userLoaded = false;

    public function rules(): array
    {
        return [
            [['password'], 'required'],
            [['email', 'username'], 'string'],
            ['email', 'validateIdentifier'],
            ['rememberMe', 'boolean'],
            ['password', 'validatePassword'],
        ];
    }

    public function validateIdentifier(string $attribute): void
    {
        if (trim($this->email) === '' && trim($this->username) === '') {
            $this->addError('email', 'Email or username is required.');
        }
    }

    public function validatePassword(string $attribute, ?array $params = null): void
    {
        if (!$this->hasErrors()) {
            $user = $this->getUser();

            if (!$user || !$user->validatePassword($this->password)) {
                $this->addError($attribute, 'Incorrect email or password.');
                return;
            }

            if (!$user->is_active) {
                $this->addError($attribute, 'User account is inactive.');
            }
        }
    }

    public function login(): bool
    {
        if ($this->validate()) {
            $user = $this->getUser();
            if ($user) {
                $user->last_login_at = date('Y-m-d H:i:s');
                $user->save(false, ['last_login_at', 'updated_at']);
                return Yii::$app->user->login($user, $this->rememberMe ? 3600 * 24 * 30 : 0);
            }
        }

        return false;
    }

    public function getUser(): ?User
    {
        if (!$this->_userLoaded) {
            $identifier = trim($this->email) !== '' ? trim($this->email) : trim($this->username);
            $this->_user = $identifier !== '' ? User::findByEmail($identifier) : null;
            $this->_userLoaded = true;
        }

        return $this->_user;
    }
}
