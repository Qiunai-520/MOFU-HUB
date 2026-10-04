<?php

declare(strict_types=1);

namespace Mofu\Core\Security;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;

/**
 * 认证服务：凭证校验与会话生命周期。
 */
final class Auth
{
    public function __construct(
        private readonly Query $query,
        private readonly SessionManager $sessions,
    ) {
    }

    /**
     * 校验登录凭证（邮箱或用户名 + 密码）。
     *
     * @return array<string, mixed> 用户记录
     */
    public function validateCredentials(string $login, string $password): ?array
    {
        $login = mb_strtolower(trim($login));

        $user = $this->query->one(
            'SELECT * FROM users WHERE email = :login_email OR username = :login_user LIMIT 1',
            ['login_email' => $login, 'login_user' => $login],
        );

        if ($user === null) {
            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        if (($user['status'] ?? 'active') !== 'active') {
            throw HttpException::forbidden('该账号当前不可登录');
        }

        return $user;
    }

    /**
     * 登录：轮换会话，防止会话固定攻击。
     *
     * @param array<string, mixed> $user
     */
    public function login(SessionState $state, array $user): void
    {
        if ($state->session === null) {
            return;
        }

        $session = $this->sessions->rotate($state->session, (int) $user['id']);

        $state->session = $session;
        $state->cookieValue = (string) $session['id'];
    }

    public function logout(SessionState $state): void
    {
        if ($state->session !== null && isset($state->session['id'])) {
            $this->sessions->destroy((string) $state->session['id']);
        }

        $state->session = null;
        $state->user = null;
        $state->clearCookie = true;
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
