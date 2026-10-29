<?php

declare(strict_types=1);

namespace Mofu\Modules\Auth;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Auth;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\View\Page;
use Mofu\Modules\Invites\InviteService;
use Mofu\Modules\Membership\TaskService;
use Mofu\Modules\Membership\VerificationService;
use Mofu\Modules\Settings\SettingsService;
use Mofu\Modules\Users\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 认证：登录 / 邮箱注册（验证码）/ 邮箱重置密码。
 * 注册不再要求密码，账号创建后可在「设置 - 账号与安全」中设置密码。
 */
final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly Query $query,
        private readonly RateLimiter $limiter,
        private readonly SettingsService $settings,
        private readonly Page $page,
        private readonly VerificationService $verification,
        private readonly TaskService $tasks,
        private readonly InviteService $invites,
    ) {
    }

    // ------------------------------------------------------------------ 登录

    public function showLogin(Request $request, Response $response): Response
    {
        if (Ctx::state($request)->isAuthenticated()) {
            return $this->redirect($response, '/');
        }

        $query = $request->getQueryParams();

        return $this->page->render($request, 'pages/login.twig', [
            'errors' => [],
            'values' => ['login' => ''],
            'redirect' => $this->safeRedirect((string) ($query['redirect'] ?? '')),
            'notice' => (string) ($query['notice'] ?? ''),
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

        // 再按账号维度限流一层，避免更换 IP 后继续撞库
        if (!$this->limiter->attempt('login-account:' . mb_strtolower($login), 10, 900)) {
            return $renderError('尝试次数过多，请 15 分钟后再试');
        }

        $user = $this->auth->validateCredentials($login, $password);

        if ($user === null) {
            return $renderError('账号或密码不正确');
        }

        if ((int) ($user['password_set'] ?? 1) !== 1) {
            return $renderError('该账号还没有设置密码，请先通过邮箱设置密码');
        }

        $this->auth->login($state, $user);
        $state->user = $this->users->findForSession((int) $user['id']);
        $this->limiter->clear('login:' . $ip);
        $this->limiter->clear('login-account:' . mb_strtolower($login));

        // 每日登录经验（幂等，同一天只发一次）
        $this->tasks->award((int) $user['id'], 'login');

        return $this->redirect($response, $target);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout(Ctx::state($request));

        return $this->redirect($response, '/');
    }

    // ------------------------------------------------------------------ 注册

    public function showRegister(Request $request, Response $response): Response
    {
        if (Ctx::state($request)->isAuthenticated()) {
            return $this->redirect($response, '/');
        }

        $query = $request->getQueryParams();

        return $this->page->render($request, 'pages/register.twig', [
            'errors' => [],
            'step' => 'email',
            'values' => ['email' => '', 'nickname' => '', 'invite' => strtoupper(trim((string) ($query['invite'] ?? '')))],
            'mailConfigured' => $this->verification->mailConfigured(),
            'registrationOpen' => $this->settings->bool('site.registration_open', true),
        ]);
    }

    /** 发送邮箱验证码 */
    public function sendRegisterCode(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $nickname = trim((string) ($body['nickname'] ?? ''));
        $invite = strtoupper(trim((string) ($body['invite'] ?? '')));

        if ($invite !== '' && !$this->invites->validate($invite)['ok']) {
            return $this->renderRegister($request, ['invite' => $this->invites->validate($invite)['error']], 'email', $email, $nickname, 422, $invite);
        }

        if (!$this->settings->bool('site.registration_open', true)) {
            return $this->renderRegister($request, ['register' => '当前暂未开放注册'], 'email', $email, $nickname, 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return $this->renderRegister($request, ['email' => '邮箱格式不正确'], 'email', $email, $nickname, 422);
        }

        if ($this->query->value('SELECT id FROM users WHERE email = :email', ['email' => $email]) !== null) {
            // 不泄露邮箱是否已注册：与正常流程一样进入验证码页（实际不发送邮件），
            // 真正的用户看到"收不到验证码"会去用页面上的登录 / 重置入口。
            return $this->page->render($request, 'pages/register.twig', [
                'errors' => [],
                'step' => 'code',
                'values' => ['email' => $email, 'nickname' => $nickname],
                'mailConfigured' => $this->verification->mailConfigured(),
                'registrationOpen' => true,
            ]);
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        if (!$this->limiter->attempt('register:' . $ip, 10, 3600)) {
            return $this->renderRegister($request, ['register' => '注册过于频繁，请稍后再试'], 'email', $email, $nickname, 422);
        }

        $result = $this->verification->send($email, 'register');

        if (!$result['ok']) {
            return $this->renderRegister($request, ['register' => $result['error']], 'email', $email, $nickname, 422);
        }

        return $this->page->render($request, 'pages/register.twig', [
            'errors' => [],
            'step' => 'code',
            'values' => ['email' => $email, 'nickname' => $nickname],
            'mailConfigured' => $this->verification->mailConfigured(),
            'registrationOpen' => true,
        ]);
    }

    /** 校验验证码并创建账号 */
    public function verifyRegister(Request $request, Response $response): Response
    {
        $state = Ctx::state($request);
        $body = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $nickname = trim((string) ($body['nickname'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));
        $invite = strtoupper(trim((string) ($body['invite'] ?? '')));

        if ($invite !== '' && !$this->invites->validate($invite)['ok']) {
            return $this->renderRegister($request, ['invite' => $this->invites->validate($invite)['error']], 'code', $email, $nickname, 422, $invite);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->renderRegister($request, ['email' => '邮箱格式不正确'], 'email', $email, $nickname, 422);
        }

        if ($this->query->value('SELECT id FROM users WHERE email = :email', ['email' => $email]) !== null) {
            return $this->renderRegister($request, ['email' => '该邮箱已注册'], 'email', $email, $nickname, 422);
        }

        if (!$this->verification->verify($email, 'register', $code)) {
            return $this->renderRegister($request, ['code' => '验证码不正确或已过期'], 'code', $email, $nickname, 422);
        }

        // 密码可选：填了才校验（放在验证码校验之前，避免密码不合规白白消耗验证码）
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');

        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                return $this->renderRegister($request, ['password' => '密码至少 8 位'], 'code', $email, $nickname, 422);
            }

            if ($password !== $passwordConfirm) {
                return $this->renderRegister($request, ['password_confirm' => '两次输入的密码不一致'], 'code', $email, $nickname, 422);
            }
        }

        $now = date('Y-m-d H:i:s');
        $username = $this->generateUsername($email);

        $userId = $this->query->transaction(function (Query $q) use ($username, $email, $nickname, $now, $password): int {
            $id = $q->insert('users', [
                'username' => $username,
                'email' => $email,
                'email_verified_at' => $now,
                'password_hash' => $this->auth->hashPassword($password !== '' ? $password : bin2hex(random_bytes(16))),
                'password_set' => $password !== '' ? 1 : 0,
                'status' => 'active',
                // 正式会员资格由邀请码或答题流程授予；注册本身不赠送权限。
                'is_formal' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $q->insert('user_profiles', [
                'user_id' => $id,
                'nickname' => $nickname !== '' ? mb_substr($nickname, 0, 24) : $username,
                'bio' => '',
                'updated_at' => $now,
            ]);

            // 管理员只能由安装向导或命令行创建，注册接口不得隐式提权。
            $role = $q->one('SELECT id FROM roles WHERE slug = :slug', ['slug' => 'user']);

            if ($role !== null) {
                $q->insert('user_roles', ['user_id' => $id, 'role_id' => (int) $role['id'], 'assigned_at' => $now]);
            }

            return $id;
        });

        $this->tasks->awardOnce($userId, 'bind_email');

        if ($password !== '') {
            $this->tasks->awardOnce($userId, 'set_password');
        }

        // 邀请码：免答题直接转为正式会员（Lv.1）
        if ($invite !== '') {
            $this->invites->redeem($invite, $userId);
        }

        $user = $this->query->one('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        if ($user !== null) {
            $this->auth->login($state, $user);
            $state->user = $this->users->findForSession($userId);
        }

        return $this->redirect($response, '/settings/security?welcome=1');
    }

    // ------------------------------------------------------------------ 重置 / 设置密码

    public function showReset(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();

        return $this->page->render($request, 'pages/password-reset.twig', [
            'errors' => [],
            'step' => 'email',
            'values' => ['email' => ''],
            'mailConfigured' => $this->verification->mailConfigured(),
        ]);
    }

    public function sendResetCode(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->page->render($request, 'pages/password-reset.twig', [
                'errors' => ['email' => '邮箱格式不正确'],
                'step' => 'email',
                'values' => ['email' => $email],
                'mailConfigured' => $this->verification->mailConfigured(),
            ], 422);
        }

        $exists = $this->query->value('SELECT id FROM users WHERE email = :email', ['email' => $email]);

        // 不泄露邮箱是否已注册：不存在时同样进入验证码页，只是不发信
        if ($exists !== null) {
            $result = $this->verification->send($email, 'reset');

            if (!$result['ok']) {
                return $this->page->render($request, 'pages/password-reset.twig', [
                    'errors' => ['email' => $result['error']],
                    'step' => 'email',
                    'values' => ['email' => $email],
                    'mailConfigured' => $this->verification->mailConfigured(),
                ], 422);
            }
        }

        return $this->page->render($request, 'pages/password-reset.twig', [
            'errors' => [],
            'step' => 'code',
            'values' => ['email' => $email],
            'mailConfigured' => $this->verification->mailConfigured(),
        ]);
    }

    public function verifyReset(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm = (string) ($body['password_confirm'] ?? '');

        $fail = fn (array $errors, string $step = 'code'): Response => $this->page->render($request, 'pages/password-reset.twig', [
            'errors' => $errors,
            'step' => $step,
            'values' => ['email' => $email],
            'mailConfigured' => $this->verification->mailConfigured(),
        ], 422);

        $errors = [];

        if (mb_strlen($password) < 8) {
            $errors['password'] = '密码至少 8 位';
        }

        if ($password !== $confirm) {
            $errors['password_confirm'] = '两次输入的密码不一致';
        }

        if ($errors !== []) {
            return $fail($errors);
        }

        $user = $this->query->one('SELECT id FROM users WHERE email = :email', ['email' => $email]);

        if ($user === null) {
            // 与验证码错误保持同样的提示，避免暴露邮箱是否注册
            return $fail(['code' => '验证码不正确或已过期']);
        }

        if (!$this->verification->verify($email, 'reset', $code)) {
            return $fail(['code' => '验证码不正确或已过期']);
        }

        $userId = (int) $user['id'];

        $this->query->update('users', [
            'password_hash' => $this->auth->hashPassword($password),
            'password_set' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $userId]);

        // 重置密码后使其他会话失效
        $this->query->run('DELETE FROM user_sessions WHERE user_id = :user_id', ['user_id' => $userId]);

        $this->tasks->awardOnce($userId, 'set_password');

        return $this->redirect($response, '/login?notice=' . rawurlencode('密码已更新，请使用新密码登录'));
    }

    // ------------------------------------------------------------------ 内部

    /** @param array<string, string> $errors */
    private function renderRegister(Request $request, array $errors, string $step, string $email, string $nickname, int $status, string $invite = ''): Response
    {
        return $this->page->render($request, 'pages/register.twig', [
            'errors' => $errors,
            'step' => $step,
            'values' => ['email' => $email, 'nickname' => $nickname, 'invite' => $invite],
            'mailConfigured' => $this->verification->mailConfigured(),
            'registrationOpen' => true,
        ], $status);
    }

    /** 由邮箱本地部分生成唯一用户名（仅小写字母、数字、下划线，3-20 位） */
    private function generateUsername(string $email): string
    {
        $local = strtolower((string) strstr($email, '@', true));
        $base = preg_replace('/[^a-z0-9_]/', '', $local) ?? '';
        $base = mb_substr($base, 0, 20);

        if (mb_strlen($base) < 3) {
            $base = 'user' . $base;
        }

        $candidate = $base;
        $suffix = 0;

        while ($this->query->value('SELECT id FROM users WHERE username = :username', ['username' => $candidate]) !== null) {
            $suffix++;
            $candidate = $base . $suffix;
        }

        return $candidate;
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
