<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Bootstrap\App;
use Mofu\Core\Database\Connection;
use Mofu\Core\Database\Query;
use Mofu\Tests\Support\TestIp;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * 阶段一升级功能集成测试：全站搜索、账号安全、通知偏好、个人中心标签页与动态编辑。
 * 使用随机用户，结束后清理。
 */
final class UpgradeFlowTest extends TestCase
{
    private static ?SlimApp $app = null;

    /** @var list<string> */
    private static array $createdUsernames = [];

    public static function setUpBeforeClass(): void
    {
        self::$app = App::create(dirname(__DIR__, 2));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdUsernames === []) {
            return;
        }

        $root = dirname(__DIR__, 2);
        $pdo = Connection::connect(\Mofu\Bootstrap\Config::fromEnvironment($root));
        $query = new Query($pdo);

        foreach (self::$createdUsernames as $username) {
            // 测试用户可能创建过圈子；communities.owner_id 现为 ON DELETE RESTRICT
            $userId = $query->value('SELECT id FROM users WHERE username = :username', ['username' => $username]);

            if ($userId !== null) {
                $query->run('DELETE FROM communities WHERE owner_id = :id', ['id' => (int) $userId]);
            }

            $query->delete('users', 'username = :username', ['username' => $username]);
        }

        $query->run(
            "DELETE FROM topics
             WHERE (name = '测试话题' OR name LIKE '检索话题%')
               AND NOT EXISTS (SELECT 1 FROM post_topics pt WHERE pt.topic_id = topics.id)",
        );
    }

    public function testSearchFindsPublicPostAndRespectsVisibility(): void
    {
        $marker = 'MARK' . strtoupper(bin2hex(random_bytes(4)));
        $privateMarker = 'PRIV' . strtoupper(bin2hex(random_bytes(4)));
        $topicName = '检索话题' . bin2hex(random_bytes(2));

        [$cookie, $csrf] = $this->registerUser('search');

        // 公开动态（含唯一词与话题）
        $store = $this->postJson('/api/v1/posts', [
            'content' => '搜索验收内容 ' . $marker,
            'visibility' => 'public',
            'topics' => [$topicName],
            'media_ids' => [],
        ], $cookie, $csrf);
        self::assertSame(201, $store->getStatusCode(), (string) $store->getBody());
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);

        // 私密动态（不应被搜索到）
        $private = $this->postJson('/api/v1/posts', [
            'content' => '私密验收内容 ' . $privateMarker,
            'visibility' => 'private',
            'media_ids' => [],
        ], $cookie, $csrf);
        $privateId = (int) (json_decode((string) $private->getBody(), true)['data']['id'] ?? 0);

        // 1. 关键词命中公开动态
        $hit = $this->get('/search?q=' . urlencode($marker));
        self::assertSame(200, $hit->getStatusCode());
        self::assertStringContainsString('data-post-id="' . $postId . '"', (string) $hit->getBody());

        // 2. 通过话题名命中（内容不含话题名的 EXISTS 分支）
        $topicHit = $this->get('/search?q=' . urlencode($topicName));
        self::assertStringContainsString('data-post-id="' . $postId . '"', (string) $topicHit->getBody());

        // 3. 私密动态不外泄
        $privateHit = $this->get('/search?q=' . urlencode($privateMarker));
        self::assertStringNotContainsString('data-post-id="' . $privateId . '"', (string) $privateHit->getBody());
        self::assertStringContainsString('没有找到', (string) $privateHit->getBody());

        // 4. 话题页可用；不存在的话题 404
        $slug = (string) (new Query($this->pdo()))->value(
            'SELECT slug FROM topics WHERE name = :name',
            ['name' => $topicName],
        );
        self::assertNotSame('', $slug);
        $topicPage = $this->get('/topics/' . rawurlencode($slug));
        self::assertSame(200, $topicPage->getStatusCode());
        self::assertStringContainsString($marker, (string) $topicPage->getBody());
        self::assertSame(404, $this->get('/topics/no-such-topic-' . bin2hex(random_bytes(3)))->getStatusCode());

        // 5. LIKE 通配符转义：% 不应匹配一切
        $wild = $this->get('/search?q=' . urlencode('%' . $marker));
        self::assertStringNotContainsString('data-post-id="' . $postId . '"', (string) $wild->getBody());
    }

    public function testAccountSecurityFlow(): void
    {
        $password = 'Passw0rd!123';
        [$cookie, $csrf, $username] = $this->registerUser('security');

        // 安全页包含当前设备与会话撤销表单
        $security = $this->get('/settings/security', 'text/html', $cookie);
        self::assertSame(200, $security->getStatusCode());
        self::assertStringContainsString('当前设备', (string) $security->getBody());

        // 当前密码错误 → 422
        $wrong = $this->send('POST', '/settings/security', [
            'form' => [
                'current_password' => 'WrongPass!1',
                'new_password' => 'BrandNew#2026x',
                'confirm_password' => 'BrandNew#2026x',
                '_csrf' => $csrf,
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('当前密码不正确', (string) $wrong->getBody());

        // 正确修改 → 302
        $change = $this->send('POST', '/settings/security', [
            'form' => [
                'current_password' => $password,
                'new_password' => 'BrandNew#2026x',
                'confirm_password' => 'BrandNew#2026x',
                '_csrf' => $csrf,
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $change->getStatusCode());

        // 旧密码失效、新密码可用
        self::assertSame(422, $this->loginAttempt($username, $password));
        self::assertSame(302, $this->loginAttempt($username, 'BrandNew#2026x'));

        // 会话管理：第二条会话可被第一条撤销
        [$cookieB] = $this->loginWithCookie($username, 'BrandNew#2026x');
        $revoke = $this->send('POST', '/settings/security/sessions/' . $cookieB . '/revoke', [
            'form' => ['_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $revoke->getStatusCode());
        $revokedSession = $this->get('/notifications', 'text/html', $cookieB);
        self::assertSame(302, $revokedSession->getStatusCode(), '被撤销的会话应失效并跳登录');
        self::assertStringContainsString('/login', $revokedSession->getHeaderLine('Location'));

        // 当前会话不可撤销
        $selfRevoke = $this->send('POST', '/settings/security/sessions/' . $cookie . '/revoke', [
            'form' => ['_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(422, $selfRevoke->getStatusCode());
    }

    public function testNotificationPreferencesGateDelivery(): void
    {
        [$cookieA, $csrfA, $usernameA] = $this->registerUser('notify_a');
        [$cookieB, $csrfB] = $this->registerUser('notify_b');

        // A 发布动态
        $store = $this->postJson('/api/v1/posts', [
            'content' => '通知偏好验收动态',
            'visibility' => 'public',
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        $userIdA = (int) (new Query($this->pdo()))->value('SELECT id FROM users WHERE username = :u', ['u' => $usernameA]);
        $count = fn (): int => (int) (new Query($this->pdo()))->value(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = :type',
            ['id' => $userIdA, 'type' => 'post_liked'],
        );

        // A 关闭「点赞」通知
        $save = $this->send('POST', '/settings/notifications', [
            'form' => ['_csrf' => $csrfA, 'pref_comments' => '1', 'pref_follows' => '1'],
            'cookie' => $cookieA,
        ]);
        self::assertSame(302, $save->getStatusCode());

        $before = $count();
        $this->toggleLike($cookieB, $csrfB, $postId);
        self::assertSame($before, $count(), '关闭点赞通知后不应产生通知');

        // 重新开启后应送达
        $this->send('POST', '/settings/notifications', [
            'form' => ['_csrf' => $csrfA, 'pref_likes' => '1', 'pref_comments' => '1', 'pref_follows' => '1'],
            'cookie' => $cookieA,
        ]);
        $this->toggleLike($cookieB, $csrfB, $postId);
        $this->toggleLike($cookieB, $csrfB, $postId);
        self::assertSame($before + 1, $count(), '开启后应收到点赞通知');
    }

    public function testProfileLikesAndCommentsTabs(): void
    {
        [$cookieA, $csrfA, $usernameA] = $this->registerUser('profile_a');
        [$cookieB, $csrfB] = $this->registerUser('profile_b');

        // B 发帖，A 点赞并评论
        $store = $this->postJson('/api/v1/posts', [
            'content' => '个人中心验收动态',
            'visibility' => 'public',
            'media_ids' => [],
        ], $cookieB, $csrfB);
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);

        $this->toggleLike($cookieA, $csrfA, $postId);
        $comment = $this->postJson('/api/v1/comments', [
            'post_id' => $postId,
            'content' => '个人中心验收评论',
        ], $cookieA, $csrfA);
        self::assertSame(201, $comment->getStatusCode());

        // 本人：点赞/评论标签可见内容
        $likes = $this->get('/u/' . $usernameA . '?tab=likes', 'text/html', $cookieA);
        self::assertSame(200, $likes->getStatusCode());
        self::assertStringContainsString('data-post-id="' . $postId . '"', (string) $likes->getBody());

        $comments = $this->get('/u/' . $usernameA . '?tab=comments', 'text/html', $cookieA);
        self::assertSame(200, $comments->getStatusCode());
        self::assertStringContainsString('个人中心验收评论', (string) $comments->getBody());
        self::assertStringContainsString('查看来源', (string) $comments->getBody());

        // 访客：仅看到隐私提示
        $guestLikes = $this->get('/u/' . $usernameA . '?tab=likes');
        self::assertStringContainsString('点赞仅本人可见', (string) $guestLikes->getBody());
        $guestComments = $this->get('/u/' . $usernameA . '?tab=comments');
        self::assertStringContainsString('评论仅本人可见', (string) $guestComments->getBody());
    }

    public function testPostEditFlow(): void
    {
        [$cookieA, $csrfA] = $this->registerUser('edit_a');
        [$cookieB, $csrfB] = $this->registerUser('edit_b');

        $store = $this->postJson('/api/v1/posts', [
            'content' => '编辑前内容原样',
            'visibility' => 'public',
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);

        // 编辑页：本人可访问且预填
        $editPage = $this->get('/posts/' . $postId . '/edit', 'text/html', $cookieA);
        self::assertSame(200, $editPage->getStatusCode());
        self::assertStringContainsString('编辑动态', (string) $editPage->getBody());
        self::assertStringContainsString('编辑前内容原样', (string) $editPage->getBody());

        // 访客跳登录；他人 403
        $guestEdit = $this->get('/posts/' . $postId . '/edit');
        self::assertSame(302, $guestEdit->getStatusCode());
        self::assertStringContainsString('/login?redirect=', $guestEdit->getHeaderLine('Location'));
        self::assertSame(403, $this->get('/posts/' . $postId . '/edit', 'text/html', $cookieB)->getStatusCode());

        // PATCH 更新（仅本人）；他人 PATCH 被拒
        $patch = $this->send('PATCH', '/api/v1/posts/' . $postId, [
            'accept' => 'application/json',
            'json' => ['content' => '编辑后内容已更新', 'visibility' => 'public', 'topics' => [], 'media_ids' => []],
            'cookie' => $cookieB,
            'csrf' => $csrfB,
        ]);
        self::assertSame(403, $patch->getStatusCode());

        $patch = $this->send('PATCH', '/api/v1/posts/' . $postId, [
            'accept' => 'application/json',
            'json' => ['content' => '编辑后内容已更新', 'visibility' => 'public', 'topics' => [], 'media_ids' => []],
            'cookie' => $cookieA,
            'csrf' => $csrfA,
        ]);
        self::assertSame(200, $patch->getStatusCode(), (string) $patch->getBody());

        $detail = $this->get('/posts/' . $postId);
        self::assertStringContainsString('编辑后内容已更新', (string) $detail->getBody());
    }

    public function testDndSuppressesGroupedNotifications(): void
    {
        [$cookieA, $csrfA, $usernameA] = $this->registerUser('dnd_a');
        [$cookieB, $csrfB] = $this->registerUser('dnd_b');

        $store = $this->postJson('/api/v1/posts', [
            'content' => '免打扰验收动态',
            'visibility' => 'public',
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        $userIdA = (int) (new Query($this->pdo()))->value('SELECT id FROM users WHERE username = :u', ['u' => $usernameA]);
        $count = fn (): int => (int) (new Query($this->pdo()))->value(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = :type',
            ['id' => $userIdA, 'type' => 'post_liked'],
        );

        $hour = (int) date('G');

        // 免打扰窗口覆盖当前小时：点赞通知应被抑制
        self::assertSame(302, $this->send('POST', '/settings/notifications', [
            'form' => [
                '_csrf' => $csrfA,
                'pref_likes' => '1',
                'pref_comments' => '1',
                'pref_follows' => '1',
                'dnd_enabled' => '1',
                'dnd_start' => (string) $hour,
                'dnd_end' => (string) (($hour + 2) % 24),
            ],
            'cookie' => $cookieA,
        ])->getStatusCode());

        $before = $count();
        $this->toggleLike($cookieB, $csrfB, $postId);
        self::assertSame($before, $count(), '免打扰时段内不应产生点赞通知');

        // 改到不覆盖当前小时的窗口：通知恢复
        self::assertSame(302, $this->send('POST', '/settings/notifications', [
            'form' => [
                '_csrf' => $csrfA,
                'pref_likes' => '1',
                'pref_comments' => '1',
                'pref_follows' => '1',
                'dnd_enabled' => '1',
                'dnd_start' => (string) (($hour + 3) % 24),
                'dnd_end' => (string) (($hour + 4) % 24),
            ],
            'cookie' => $cookieA,
        ])->getStatusCode());

        $this->toggleLike($cookieB, $csrfB, $postId);
        $this->toggleLike($cookieB, $csrfB, $postId);
        self::assertSame($before + 1, $count(), '不在免打扰时段应收到通知');

        // 设置页展示免打扰区块
        self::assertStringContainsString('免打扰时段', (string) $this->get('/settings/notifications', 'text/html', $cookieA)->getBody());
    }

    // ------------------------------------------------------------------ helpers

    private function pdo(): \PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            $pdo = Connection::connect(\Mofu\Bootstrap\Config::fromEnvironment(dirname(__DIR__, 2)));
        }

        return $pdo;
    }

    /** @return array{0: string, 1: string, 2: string} [cookie, csrf, username] */
    private function registerUser(string $prefix): array
    {
        $username = $prefix . '_' . bin2hex(random_bytes(4));
        self::$createdUsernames[] = $username;

        $page = $this->get('/register');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());
        self::assertNotNull($cookie);
        self::assertNotNull($csrf);

        $email = $username . '@example.com';

        // 邮箱注册第一步：发送验证码（默认 log 邮件驱动，验证码写入 storage/logs/mail.log）
        $sent = $this->send('POST', '/register', [
            'form' => ['email' => $email, 'nickname' => '测试' . $prefix, '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(200, $sent->getStatusCode(), (string) $sent->getBody());

        $code = $this->mailCode($email);
        self::assertNotNull($code, '未找到注册验证码');

        // 第二步：校验验证码并创建账号
        $register = $this->send('POST', '/register/verify', [
            'form' => [
                'email' => $email,
                'nickname' => '测试' . $prefix,
                'code' => $code,
                '_csrf' => (string) $this->csrfToken((string) $sent->getBody()),
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $register->getStatusCode(), (string) $register->getBody());

        $newCookie = $this->sessionCookie($register);
        self::assertNotNull($newCookie);

        // 邮箱注册不设密码；测试需要密码登录，等价于用户在设置页设置密码
        (new Query($this->pdo()))->run(
            'UPDATE users SET password_hash = :hash, password_set = 1 WHERE username = :username',
            ['hash' => password_hash('Passw0rd!123', PASSWORD_DEFAULT), 'username' => $username],
        );

        // 评论有等级门槛：测试用户默认提升为正式会员（Lv.2）
        \Mofu\Tests\Support\MemberFixture::promoteByUsername(new Query($this->pdo()), $username);

        $home = $this->get('/', 'text/html', $newCookie);
        $newCsrf = $this->csrfToken((string) $home->getBody());
        self::assertNotNull($newCsrf);

        return [$newCookie, $newCsrf, $username];
    }

    /** 从邮件日志中取该邮箱最近一次验证码 */
    private function mailCode(string $email): ?string
    {
        $log = dirname(__DIR__, 2) . '/storage/logs/mail.log';

        if (!is_file($log)) {
            return null;
        }

        $content = (string) file_get_contents($log);
        $pattern = '/to=' . preg_quote($email, '/') . ' subject=.*?验证码是：?(\\d{6})/s';

        if (preg_match_all($pattern, $content, $matches) === 0 || $matches[1] === []) {
            return null;
        }

        return (string) end($matches[1]);
    }

    private function loginAttempt(string $login, string $password): int
    {
        $page = $this->get('/login');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());

        return $this->send('POST', '/login', [
            'form' => ['login' => $login, 'password' => $password, '_csrf' => $csrf ?? ''],
            'cookie' => $cookie,
        ])->getStatusCode();
    }

    /** @return array{0: string, 1: string} [cookie, csrf] */
    private function loginWithCookie(string $login, string $password): array
    {
        $page = $this->get('/login');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());

        $loginResponse = $this->send('POST', '/login', [
            'form' => ['login' => $login, 'password' => $password, '_csrf' => $csrf ?? ''],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $loginResponse->getStatusCode());

        $newCookie = $this->sessionCookie($loginResponse);
        self::assertNotNull($newCookie);

        $home = $this->get('/', 'text/html', $newCookie);
        $newCsrf = $this->csrfToken((string) $home->getBody());
        self::assertNotNull($newCsrf);

        return [$newCookie, $newCsrf];
    }

    private function toggleLike(string $cookie, string $csrf, int $postId): void
    {
        $like = $this->postJson('/api/v1/reactions/toggle', [
            'target_type' => 'post',
            'target_id' => $postId,
            'kind' => 'like',
        ], $cookie, $csrf);
        self::assertSame(200, $like->getStatusCode(), (string) $like->getBody());
    }

    private function send(string $method, string $path, array $options = []): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $path, ['REMOTE_ADDR' => TestIp::next()] + ($_SERVER ?? []));

        $request = $request->withHeader('Accept', $options['accept'] ?? 'text/html');

        if (isset($options['cookie'])) {
            $request = $request->withCookieParams(['mofu_session' => $options['cookie']]);
        }

        if (isset($options['form'])) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
            $request = $request->withParsedBody($options['form']);
        } elseif (isset($options['json'])) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request = $request->withHeader('X-CSRF-Token', $options['csrf'] ?? '');
            $body = $request->getBody();
            $body->write((string) json_encode($options['json']));
            $request = $request->withParsedBody($options['json']);
        }

        self::assertNotNull(self::$app);

        return self::$app->handle($request);
    }

    private function get(string $path, string $accept = 'text/html', ?string $cookie = null): ResponseInterface
    {
        return $this->send('GET', $path, ['accept' => $accept, 'cookie' => $cookie]);
    }

    /** @param array<string, mixed> $json */
    private function postJson(string $path, array $json, ?string $cookie = null, ?string $csrf = null): ResponseInterface
    {
        return $this->send('POST', $path, [
            'accept' => 'application/json',
            'json' => $json,
            'cookie' => $cookie,
            'csrf' => $csrf,
        ]);
    }

    private function sessionCookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^mofu_session=([a-f0-9]{64})/', $header, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function csrfToken(string $html): ?string
    {
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/csrf:\s*"([a-f0-9]{64})"/', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
