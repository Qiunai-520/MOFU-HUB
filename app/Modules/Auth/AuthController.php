<?php

declare(strict_types=1);

namespace Mofu\Modules\Auth;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Auth;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\View\Page;
use Mofu\Modules\Settings\SettingsService;
use Mofu\Modules\Users\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly Query $query,
        private readonly RateLimiter $limiter,
        private readonly SettingsService $settings,
        private readonly Page $page,
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if (Ctx::state($request)->isAuthenticated()) {
            return $this->redirect($response, '/');
        }

        return $this->page->render($request, 'pages/login.twig', [
            'errors' => [],
            'values' => ['login' => ''],
            'redirect' => $this->safeRedirect((string) ($request->getQueryParams()['redirect'] ?? '')),
        ]);
    }

    public function login(Request $request, Response $response): Response
    {
        $state = Ctx::state($request);
        $body = (array) $request->getParsedBody();
        $login = trim((string) ($body['login'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $target = $this->safeRedirect((string) ($body['redirect'] ?? ''));
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        $renderError = fn (string $message): Response => $this->page->render($request, 'pages/login.twig', [
            'errors' => ['login' => $message],
            'values' => ['login' => $login],
            'redirect' => $target,
        ], 422);

        if ($login === '' || $password === '') {
            return $renderError('请输入账号和密码');
        }

        if (!$this->limiter->attempt('login:' . $ip, 20, 900)) {
            return $renderError('尝试次数过多，请 15 分钟后再试');
        }

        try {
            $user = $this->auth->validateCredentials($login, $password);
        } catch (HttpException $e) {
            // 封禁 / 停用账号：渲染登录页并引导申诉，而不是直接错误页
            if ($e->getStatusCode() === 403) {
                return $this->page->render($request, 'pages/login.twig', [
                    'errors' => ['login' => $e->getMessage()],
                    'values' => ['login' => $login],
                    'canAppeal' => true,
                    'redirect' => $target,
                ], 403);
            }

            throw $e;
        }

        if ($user === null) {
            return $renderError('账号或密码不正确');
        }

        $this->auth->login($state, $user);
        $state->user = $this->users->findForSession((int) $user['id']);
        $this->limiter->clear('login:' . $ip);

        return $this->redirect($response, $target);
    }

    public function showRegister(Request $request, Response $response): Response
    {
        if (Ctx::state($request)->isAuthenticated()) {
            return $this->redirect($response, '/');
        }

        return $this->page->render($request, 'pages/register.twig', [
            'errors' => [],
            'values' => ['username' => '', 'email' => '', 'nickname' => ''],
            'registrationOpen' => $this->settings->bool('site.registration_open', true),
        ]);
    }

    public function register(Request $request, Response $response): Response
    {
        $state = Ctx::state($request);
        $body = (array) $request->getParsedBody();

        $username = strtolower(trim((string) ($body['username'] ?? '')));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');
        $nickname = trim((string) ($body['nickname'] ?? ''));
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        $errors = [];

        if (!preg_match('/^[a-z0-9_]{3,20}$/', $username)) {
            $errors['username'] = '用户名需为 3-20 位字母、数字或下划线';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $errors['email'] = '邮箱格式不正确';
        }

        if (mb_strlen($password) < 8) {
            $errors['password'] = '密码至少 8 位';
        }

        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = '两次输入的密码不一致';
        }

        if (mb_strlen($nickname) > 24) {
            $errors['nickname'] = '昵称最多 24 字';
        }

        if (!$this->settings->bool('site.registration_open', true)) {
            $errors['register'] = '当前暂未开放注册';
        }

        if ($errors === []) {
            if ($this->query->value('SELECT id FROM users WHERE username = :u', ['u' => $username]) !== null) {
                $errors['username'] = '该用户名已被使用';
            }

            if ($this->query->value('SELECT id FROM users WHERE email = :e', ['e' => $email]) !== null) {
                $errors['email'] = '该邮箱已注册';
            }
        }

        if ($errors === [] && !$this->limiter->attempt('register:' . $ip, 10, 3600)) {
            $errors['register'] = '注册过于频繁，请稍后再试';
        }

        if ($errors !== []) {
            return $this->page->render($request, 'pages/register.twig', [
                'errors' => $errors,
                'values' => ['username' => $username, 'email' => $email, 'nickname' => $nickname],
                'registrationOpen' => true,
            ], 422);
        }

        $now = date('Y-m-d H:i:s');
        $isFirstUser = $this->users->countAll() === 0;

        $userId = $this->query->transaction(function (Query $q) use ($username, $email, $password, $nickname, $now, $isFirstUser): int {
            $id = $q->insert('users', [
                'username' => $username,
                'email' => $email,
                'password_hash' => $this->auth->hashPassword($password),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $q->insert('user_profiles', [
                'user_id' => $id,
                'nickname' => $nickname !== '' ? $nickname : $username,
                'bio' => '',
                'updated_at' => $now,
            ]);

            // 首位注册者自动成为超级管理员
            $roleSlug = $isFirstUser ? 'super_admin' : 'user';
            $role = $q->one('SELECT id FROM roles WHERE slug = :slug', ['slug' => $roleSlug]);

            if ($role !== null) {
                $q->insert('user_roles', [
                    'user_id' => $id,
                    'role_id' => (int) $role['id'],
                    'assigned_at' => $now,
                ]);
            }

            return $id;
        });

        $user = $this->query->one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        if ($user !== null) {
            $this->auth->login($state, $user);
            $state->user = $this->users->findForSession($userId);
        }

        return $this->redirect($response, '/');
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout(Ctx::state($request));

        return $this->redirect($response, '/');
    }

    /** 仅接受站内绝对路径，避免开放重定向 */
    private function safeRedirect(string $to): string
    {
        $to = trim($to);

        if ($to === '' || !str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            return '/';
        }

        return $to;
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
