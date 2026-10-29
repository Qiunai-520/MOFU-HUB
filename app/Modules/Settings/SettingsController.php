<?php

declare(strict_types=1);

namespace Mofu\Modules\Settings;

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Auth;
use Mofu\Core\Security\Guard;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\Support\AuditLogger;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Page;
use Mofu\Core\View\Present;
use Mofu\Modules\Follows\FollowService;
use Mofu\Modules\Media\MediaService;
use Mofu\Modules\Membership\TaskService;
use Mofu\Modules\Membership\VerificationService;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Users\BlockService;
use Mofu\Modules\Users\NicknameService;
use Mofu\Modules\Users\ProfileDecorations;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class SettingsController
{
    public function __construct(
        private readonly Query $query,
        private readonly Auth $auth,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
        private readonly MediaService $media,
        private readonly PreferenceService $preferences,
        private readonly BlockService $blocks,
        private readonly FollowService $follows,
        private readonly Config $config,
        private readonly Page $page,
        private readonly TaskService $tasks,
        private readonly VerificationService $verification,
        private readonly NicknameService $nicknames,
    ) {
    }

    public function profile(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $profile = $this->query->one(
            'SELECT p.*, m.thumb_path AS avatar_thumb, m.disk_path AS avatar_path,
                    cm.thumb_path AS cover_thumb, cm.disk_path AS cover_path
             FROM user_profiles p
             LEFT JOIN media m ON m.id = p.avatar_media_id
             LEFT JOIN media cm ON cm.id = p.cover_media_id
             WHERE p.user_id = :id',
            ['id' => $user['id']],
        ) ?? [];

        $profile['avatar_url'] = Present::uploadUrl(
            isset($profile['avatar_thumb']) ? (string) $profile['avatar_thumb'] : null,
            isset($profile['avatar_path']) ? (string) $profile['avatar_path'] : null,
        );

        $profile['cover_url'] = Present::uploadUrl(
            isset($profile['cover_thumb']) ? (string) $profile['cover_thumb'] : null,
            isset($profile['cover_path']) ? (string) $profile['cover_path'] : null,
        );
        $profile = $profile + ProfileDecorations::present($profile);

        return $this->page->render($request, 'pages/settings/profile.twig', [
            'profile' => $profile,
            'nicknameStatus' => $this->nicknames->status((int) $user['id']),
            'errors' => [],
            'saved' => isset($request->getQueryParams()['ok']),
        ]);
    }

    public function saveProfile(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();

        $nickname = trim((string) ($body['nickname'] ?? ''));
        $bio = trim((string) ($body['bio'] ?? ''));
        $location = trim((string) ($body['location'] ?? ''));
        $website = trim((string) ($body['website'] ?? ''));

        $errors = [];

        if ($nickname === '' || mb_strlen($nickname) > 24) {
            $errors['nickname'] = '昵称需为 1-24 字';
        }

        if (mb_strlen($bio) > 200) {
            $errors['bio'] = '简介最多 200 字';
        }

        if (mb_strlen($location) > 32) {
            $errors['location'] = '位置最多 32 字';
        }

        if ($website !== '' && (!filter_var($website, FILTER_VALIDATE_URL) || !str_starts_with($website, 'http'))) {
            $errors['website'] = '网站链接需为 http(s) 完整地址';
        }

        if ($errors !== []) {
            return $this->page->render($request, 'pages/settings/profile.twig', [
                'profile' => [
                    'nickname' => $nickname,
                    'bio' => $bio,
                    'location' => $location,
                    'website' => $website,
                ],
                'nicknameStatus' => $this->nicknames->status((int) $user['id']),
                'errors' => $errors,
                'saved' => false,
            ], 422);
        }

        // 昵称走计费服务（首次免费、之后 6 M币、间隔 ≥3 天）；其余字段直接更新
        try {
            $this->nicknames->change((int) $user['id'], $nickname);
        } catch (HttpException $e) {
            return $this->page->render($request, 'pages/settings/profile.twig', [
                'profile' => [
                    'nickname' => $nickname,
                    'bio' => $bio,
                    'location' => $location,
                    'website' => $website,
                ],
                'nicknameStatus' => $this->nicknames->status((int) $user['id']),
                'errors' => ['nickname' => $e->getMessage()],
                'saved' => false,
            ], 422);
        }

        $this->query->update('user_profiles', [
            'bio' => $bio,
            'location' => $location,
            'website' => $website,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'user_id = :id', ['id' => $user['id']]);

        return $response->withHeader('Location', '/settings/profile?ok=1')->withStatus(302);
    }

    // ---------------------------------------------------------------- 头像

    public function uploadAvatar(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        if (!$this->limiter->attempt('avatar:' . (int) $user['id'], 10, 3600)) {
            throw HttpException::tooManyRequests('上传太频繁啦，稍后再试');
        }

        $file = $request->getUploadedFiles()['avatar'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            throw HttpException::badRequest('请选择要上传的图片');
        }

        $media = $this->media->storeImage((int) $user['id'], $file);

        $this->query->run(
            'UPDATE user_profiles SET avatar_media_id = :media_id, updated_at = :now WHERE user_id = :id',
            ['media_id' => (int) $media['id'], 'now' => date('Y-m-d H:i:s'), 'id' => (int) $user['id']],
        );

        $this->tasks->awardOnce((int) $user['id'], 'set_avatar');

        return $response->withHeader('Location', '/settings/profile?ok=1')->withStatus(302);
    }

    // ---------------------------------------------------------------- 主页封面

    public function uploadCover(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        if (!$this->limiter->attempt('cover:' . (int) $user['id'], 10, 3600)) {
            throw HttpException::tooManyRequests('上传太频繁啦，稍后再试');
        }

        $file = $request->getUploadedFiles()['cover'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            throw HttpException::badRequest('请选择要上传的图片');
        }

        $media = $this->media->storeImage((int) $user['id'], $file);

        $this->query->run(
            'UPDATE user_profiles SET cover_media_id = :media_id, updated_at = :now WHERE user_id = :id',
            ['media_id' => (int) $media['id'], 'now' => date('Y-m-d H:i:s'), 'id' => (int) $user['id']],
        );

        return $response->withHeader('Location', '/settings/profile?ok=1')->withStatus(302);
    }

    public function removeCover(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        $this->query->run(
            'UPDATE user_profiles SET cover_media_id = NULL, updated_at = :now WHERE user_id = :id',
            ['now' => date('Y-m-d H:i:s'), 'id' => (int) $user['id']],
        );

        return $response->withHeader('Location', '/settings/profile?ok=1')->withStatus(302);
    }

    // ---------------------------------------------------------------- 账号安全

    /** 设置首页（二级列表菜单）：点每一项进入对应的三级设置页 */
    public function index(Request $request, Response $response): Response
    {
        Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/settings/index.twig', [
            'title' => '设置',
        ]);
    }

    public function security(Request $request, Response $response): Response    {
        $user = Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/settings/security.twig', $this->securityData($request, $user));
    }

    public function changePassword(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();

        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        $confirm = (string) ($body['confirm_password'] ?? '');
        $revokeOthers = (string) ($body['revoke_others'] ?? '') === '1';

        $row = $this->query->one('SELECT password_hash, password_set FROM users WHERE id = :id', ['id' => (int) $user['id']]);
        $hasPassword = (int) ($row['password_set'] ?? 1) === 1;

        $errors = [];

        if (!$this->limiter->attempt('password:' . (int) $user['id'], 10, 3600)) {
            $errors['current_password'] = '尝试次数过多，请 1 小时后再试';
        }

        if ($errors === []) {
            // 邮箱注册的账号尚未设置密码：首次设置不需要当前密码
            if ($hasPassword && !password_verify($current, (string) ($row['password_hash'] ?? ''))) {
                $errors['current_password'] = '当前密码不正确';
            }

            if (mb_strlen($new) < 8) {
                $errors['new_password'] = '新密码至少 8 位';
            } elseif ($hasPassword && $new === $current) {
                $errors['new_password'] = '新密码不能与当前密码相同';
            }

            if ($new !== $confirm) {
                $errors['confirm_password'] = '两次输入的新密码不一致';
            }
        }

        if ($errors !== []) {
            return $this->page->render(
                $request,
                'pages/settings/security.twig',
                $this->securityData($request, $user, $errors),
                422,
            );
        }

        $this->query->update('users', [
            'password_hash' => $this->auth->hashPassword($new),
            'password_set' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $user['id']]);

        if (!$hasPassword) {
            $this->tasks->awardOnce((int) $user['id'], 'set_password');
        }

        if ($revokeOthers) {
            $this->revokeOtherSessionsFor($request, (int) $user['id']);
        }

        $this->audit->log((int) $user['id'], 'account.password_change', 'user', (int) $user['id'], ['revoke_others' => $revokeOthers]);
        $this->limiter->clear('password:' . (int) $user['id']);

        return $response->withHeader('Location', '/settings/security?ok=1')->withStatus(302);
    }

    /** 更换邮箱第一步：向新邮箱发送验证码 */
    public function sendEmailChange(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];
        $newEmail = strtolower(trim((string) (((array) $request->getParsedBody())['new_email'] ?? '')));
        $currentEmail = strtolower((string) $this->query->value('SELECT email FROM users WHERE id = :id', ['id' => $userId]) ?? '');

        $errors = $this->emailChangeErrors($userId, $newEmail, $currentEmail);

        // 频率限制只在「发送验证码」这一步消耗，避免用户输错验证码时被提前限流
        if ($errors === [] && !$this->limiter->attempt('email-change:' . $userId, 5, 3600)) {
            $errors['new_email'] = '操作过于频繁，请 1 小时后再试';
        }

        if ($errors !== []) {
            $data = $this->securityData($request, $user);
            $data['emailErrors'] = $errors;

            return $this->page->render($request, 'pages/settings/security.twig', $data, 422);
        }

        $result = $this->verification->send($newEmail, 'change_email');

        if (!$result['ok']) {
            $data = $this->securityData($request, $user);
            $data['emailErrors'] = ['new_email' => $result['error']];

            return $this->page->render($request, 'pages/settings/security.twig', $data, 422);
        }

        return $response->withHeader('Location', '/settings/security?email_pending=' . rawurlencode($newEmail))->withStatus(302);
    }

    /** 更换邮箱第二步：校验验证码并改绑 */
    public function verifyEmailChange(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];
        $body = (array) $request->getParsedBody();
        $newEmail = strtolower(trim((string) ($body['new_email'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));

        $errors = $this->emailChangeErrors($userId, $newEmail, '');

        if ($errors === [] && !$this->verification->verify($newEmail, 'change_email', $code)) {
            $errors['code'] = '验证码不正确或已过期';
        }

        if ($errors !== []) {
            $data = $this->securityData($request, $user);
            $data['emailErrors'] = $errors;
            $data['emailPending'] = $newEmail;

            return $this->page->render($request, 'pages/settings/security.twig', $data, 422);
        }

        $this->query->update('users', [
            'email' => $newEmail,
            'email_verified_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $userId]);

        $this->audit->log($userId, 'account.email_change', 'user', $userId);
        $this->limiter->clear('email-change:' . $userId);

        return $response->withHeader('Location', '/settings/security?ok=1')->withStatus(302);
    }

    /**
     * 更换邮箱的校验：格式、是否与当前相同、是否被占用、频率限制
     *
     * @return array<string, string>
     */
    private function emailChangeErrors(int $userId, string $newEmail, string $currentEmail): array
    {
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($newEmail) > 190) {
            return ['new_email' => '邮箱格式不正确'];
        }

        if ($currentEmail !== '' && $newEmail === $currentEmail) {
            return ['new_email' => '新邮箱与当前邮箱相同'];
        }

        if ($this->query->value('SELECT id FROM users WHERE email = :email AND id != :id', ['email' => $newEmail, 'id' => $userId]) !== null) {
            return ['new_email' => '该邮箱已被其他账号使用'];
        }

        return [];
    }

    /** @param array<string, string> $args */
    public function revokeSession(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $sessionId = (string) ($args['id'] ?? '');
        $currentId = (string) (Ctx::state($request)->session['id'] ?? '');

        if (!preg_match('/^[a-f0-9]{64}$/', $sessionId)) {
            throw HttpException::badRequest('会话标识无效');
        }

        if ($sessionId === $currentId) {
            throw HttpException::badRequest('不能撤销当前设备，请使用退出登录');
        }

        $deleted = $this->query->run(
            'DELETE FROM user_sessions WHERE id = :id AND user_id = :uid',
            ['id' => $sessionId, 'uid' => (int) $user['id']],
        )->rowCount();

        if ($deleted === 0) {
            throw HttpException::notFound('会话不存在或已过期');
        }

        $this->audit->log((int) $user['id'], 'account.session_revoke', 'session', null, ['session' => substr($sessionId, 0, 8)]);

        return $response->withHeader('Location', '/settings/security?ok=1')->withStatus(302);
    }

    public function revokeOtherSessions(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        $this->revokeOtherSessionsFor($request, (int) $user['id']);
        $this->audit->log((int) $user['id'], 'account.sessions_revoke_others', 'session');

        return $response->withHeader('Location', '/settings/security?ok=1')->withStatus(302);
    }

    private function revokeOtherSessionsFor(Request $request, int $userId): void
    {
        $currentId = (string) (Ctx::state($request)->session['id'] ?? '');

        $this->query->run(
            'DELETE FROM user_sessions WHERE user_id = :uid AND id != :current',
            ['uid' => $userId, 'current' => $currentId],
        );
    }

    /**
     * @param array<string, mixed>  $user
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private function securityData(Request $request, array $user, array $errors = []): array
    {
        $currentId = (string) (Ctx::state($request)->session['id'] ?? '');

        $sessions = array_map(static function (array $row) use ($currentId): array {
            return [
                'id' => (string) $row['id'],
                'device' => self::describeAgent((string) $row['user_agent']),
                'ip' => (string) $row['ip_address'],
                'current' => (string) $row['id'] === $currentId,
                'last_seen' => Text::relativeTime((string) $row['last_seen_at']),
                'created_at' => date('Y-m-d', strtotime((string) $row['created_at']) ?: time()),
            ];
        }, $this->query->all(
            'SELECT id, ip_address, user_agent, created_at, last_seen_at
             FROM user_sessions
             WHERE user_id = :id AND expires_at > :now
             ORDER BY last_seen_at DESC',
            ['id' => (int) $user['id'], 'now' => date('Y-m-d H:i:s')],
        ));

        return [
            'errors' => $errors,
            'saved' => isset($request->getQueryParams()['ok']),
            'welcome' => isset($request->getQueryParams()['welcome']),
            // 更换邮箱：待验证的新邮箱（发送成功后带回）与错误
            'emailPending' => (string) ($request->getQueryParams()['email_pending'] ?? ''),
            'emailErrors' => [],
            'passwordSet' => (int) ($this->query->value('SELECT password_set FROM users WHERE id = :id', ['id' => (int) $user['id']]) ?? 1) === 1,
            'email' => (string) ($this->query->value('SELECT email FROM users WHERE id = :id', ['id' => (int) $user['id']]) ?? ''),
            'sessions' => $sessions,
        ];
    }

    /** 将 User-Agent 归一化为「系统 · 浏览器」 */
    private static function describeAgent(string $agent): string
    {
        $browser = '未知浏览器';

        foreach (['Edg' => 'Edge', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $needle => $label) {
            if (stripos($agent, $needle) !== false) {
                $browser = $label;
                break;
            }
        }

        $os = '未知系统';

        foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Macintosh' => 'macOS', 'Linux' => 'Linux'] as $needle => $label) {
            if (stripos($agent, $needle) !== false) {
                $os = $label;
                break;
            }
        }

        return $os . ' · ' . $browser;
    }

    // ---------------------------------------------------------------- 通知偏好

    public function notifications(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/settings/notifications.twig', [
            'prefs' => $this->preferences->notificationPrefs((int) $user['id']),
            'saved' => isset($request->getQueryParams()['ok']),
        ]);
    }

    public function saveNotifications(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();

        $prefs = [];

        foreach (array_keys(NotificationService::PREF_GROUPS) as $group) {
            $prefs[$group] = (string) ($body['pref_' . $group] ?? '0') === '1';
        }

        $prefs['dnd'] = [
            'enabled' => (string) ($body['dnd_enabled'] ?? '0') === '1',
            'start' => (int) ($body['dnd_start'] ?? 23),
            'end' => (int) ($body['dnd_end'] ?? 7),
        ];

        $this->preferences->saveNotificationPrefs((int) $user['id'], $prefs);

        return $response->withHeader('Location', '/settings/notifications?ok=1')->withStatus(302);
    }

    // ---------------------------------------------------------------- 隐私设置

    public function privacy(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];

        return $this->page->render($request, 'pages/settings/privacy.twig', [
            'prefs' => $this->preferences->privacyPrefs($userId),
            'blockedUsers' => $this->follows->presentUsers($this->blocks->listBlocked($userId), $userId),
            'saved' => isset($request->getQueryParams()['ok']),
        ]);
    }

    public function savePrivacy(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();

        $this->preferences->savePrivacyPrefs((int) $user['id'], [
            'dm_policy' => (string) ($body['dm_policy'] ?? 'all'),
            'connections_visibility' => (string) ($body['connections_visibility'] ?? 'public'),
            'read_receipt' => ($body['read_receipt'] ?? '1') === '1',
        ]);

        return $response->withHeader('Location', '/settings/privacy?ok=1')->withStatus(302);
    }

    // ---------------------------------------------------------------- 头像框 / 昵称挂件

    public function decorations(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $row = $this->query->one(
            'SELECT avatar_frame, name_badge FROM user_profiles WHERE user_id = :id',
            ['id' => $user['id']],
        ) ?? [];

        [$ownedFrames, $ownedBadges] = $this->ownedDecorations((int) $user['id']);
        $current = ProfileDecorations::present($row);

        $frames = [];
        foreach (ProfileDecorations::FRAMES as $key => $frame) {
            $free = ProfileDecorations::isFreeFrame((string) $key);
            $frames[] = [
                'key' => (string) $key,
                'label' => (string) $frame['label'],
                'class' => (string) $frame['class'],
                'free' => $free,
                'owned' => $free || in_array((string) $key, $ownedFrames, true),
                'selected' => $current['avatar_frame'] === (string) $key,
            ];
        }

        $badges = [];
        foreach (ProfileDecorations::BADGES as $key => $badge) {
            $free = ProfileDecorations::isFreeBadge((string) $key);
            $badges[] = [
                'key' => (string) $key,
                'label' => (string) $badge['label'],
                'emoji' => (string) $badge['emoji'],
                'image' => (string) ($badge['image'] ?? ''),
                'free' => $free,
                'owned' => $free || in_array((string) $key, $ownedBadges, true),
                'selected' => $current['name_badge'] === (string) $key,
            ];
        }

        return $this->page->render($request, 'pages/settings/decorations.twig', [
            'frames' => $frames,
            'badges' => $badges,
            'current' => $current,
            'saved' => isset($request->getQueryParams()['ok']),
        ]);
    }

    public function saveDecorations(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();
        $frame = (string) ($body['avatar_frame'] ?? '');
        $badge = (string) ($body['name_badge'] ?? '');
        [$ownedFrames, $ownedBadges] = $this->ownedDecorations((int) $user['id']);

        if (!array_key_exists($frame, ProfileDecorations::FRAMES)
            || (!ProfileDecorations::isFreeFrame($frame) && !in_array($frame, $ownedFrames, true))) {
            throw HttpException::badRequest('该头像框尚未拥有');
        }

        if (!array_key_exists($badge, ProfileDecorations::BADGES)
            || (!ProfileDecorations::isFreeBadge($badge) && !in_array($badge, $ownedBadges, true))) {
            throw HttpException::badRequest('该昵称挂件尚未拥有');
        }

        $this->query->run(
            'UPDATE user_profiles SET avatar_frame = :frame, name_badge = :badge, updated_at = :now WHERE user_id = :id',
            [
                'frame' => $frame,
                'badge' => $badge,
                'now' => date('Y-m-d H:i:s'),
                'id' => $user['id'],
            ],
        );

        return $response->withHeader('Location', '/settings/decorations?ok=1')->withStatus(302);
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function ownedDecorations(int $userId): array
    {
        $rows = $this->query->all(
            "SELECT p.type, p.payload
             FROM user_inventory i JOIN shop_products p ON p.id = i.product_id
             WHERE i.user_id = :id AND p.type IN ('avatar_frame', 'badge')",
            ['id' => $userId],
        );

        $frames = [];
        $badges = [];

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload'] ?? ''), true);

            if (!is_array($payload)) {
                continue;
            }

            if ((string) $row['type'] === 'avatar_frame' && isset($payload['frame'])) {
                $frames[] = (string) $payload['frame'];
            } elseif ((string) $row['type'] === 'badge' && isset($payload['badge'])) {
                $badges[] = (string) $payload['badge'];
            }
        }

        return [array_values(array_unique($frames)), array_values(array_unique($badges))];
    }

    // ---------------------------------------------------------------- 背景图

    public function background(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];
        $prefs = $this->preferences->appearancePrefs($userId);

        $bg = $prefs['background'];
        $mediaUrl = '';

        if ((int) $bg['media_id'] > 0) {
            $row = $this->query->one(
                'SELECT disk_path, thumb_path FROM media WHERE id = :id AND user_id = :uid',
                ['id' => (int) $bg['media_id'], 'uid' => $userId],
            );

            if ($row !== null) {
                $mediaUrl = Present::uploadUrl(
                    isset($row['thumb_path']) ? (string) $row['thumb_path'] : null,
                    isset($row['disk_path']) ? (string) $row['disk_path'] : null,
                );
            }
        }

        return $this->page->render($request, 'pages/settings/background.twig', [
            'prefs' => $prefs,
            'mediaUrl' => $mediaUrl,
            'uploadMaxMb' => $this->configMaxMb(),
            'saved' => isset($request->getQueryParams()['ok']),
        ]);
    }

    public function saveBackground(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();
        $userId = (int) $user['id'];

        $url = trim((string) ($body['background_url'] ?? ''));
        $opacity = max(0, min(100, (int) ($body['background_opacity'] ?? 45)));
        $remove = isset($body['remove']);

        if ($remove) {
            $url = '';
        } elseif ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL) || ($url !== '' && !str_starts_with($url, 'http'))) {
            throw HttpException::badRequest('图片链接需为 http(s) 完整地址');
        }

        $current = $this->preferences->appearancePrefs($userId)['background'];
        $mediaId = $url !== '' ? 0 : (int) $current['media_id'];

        $this->preferences->saveAppearancePrefs($userId, [
            'background' => ['media_id' => $mediaId, 'url' => $url, 'opacity' => $opacity],
        ]);

        if ($url !== '' || $mediaId > 0) {
            $this->tasks->awardOnce($userId, 'set_background');
        }

        return $response->withHeader('Location', '/settings/background?ok=1')->withStatus(302);
    }

    public function uploadBackground(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        if (!$this->limiter->attempt('background:' . (int) $user['id'], 10, 3600)) {
            throw HttpException::tooManyRequests('上传太频繁啦，稍后再试');
        }

        $file = $request->getUploadedFiles()['background'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            throw HttpException::badRequest('请选择要上传的图片');
        }

        $media = $this->media->storeImage((int) $user['id'], $file);

        $current = $this->preferences->appearancePrefs((int) $user['id'])['background'];

        $this->preferences->saveAppearancePrefs((int) $user['id'], [
            'background' => ['media_id' => (int) $media['id'], 'url' => '', 'opacity' => (int) $current['opacity']],
        ]);

        $this->tasks->awardOnce((int) $user['id'], 'set_background');

        return $response->withHeader('Location', '/settings/background?ok=1')->withStatus(302);
    }

    private function configMaxMb(): int
    {
        return (int) $this->config->get('upload.max_mb', 8);
    }
}
