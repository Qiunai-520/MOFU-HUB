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
 * HTTP 集成测试：通过完整中间件链验证核心用户旅程。
 * 测试数据使用随机用户名，结束后清理。
 */
final class HttpFlowTest extends TestCase
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
            $query->delete('users', 'username = :username', ['username' => $username]);
        }
    }

    public function testHomePageRendersShell(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('MOFU', $html);
        self::assertStringContainsString('id="swup"', $html);
        self::assertStringContainsString('m3e-theme', $html);
    }

    public function testLoginPageContainsCsrfField(): void
    {
        $response = $this->get('/login');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="_csrf"', (string) $response->getBody());
    }

    public function testFeedApiReturnsJson(): void
    {
        $response = $this->get('/api/v1/feed', 'application/json');

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertTrue($payload['ok']);
        self::assertIsArray($payload['data']);
        self::assertArrayHasKey('pagination', $payload['meta']);
    }

    public function testGuestPostWithoutCsrfIsRejected(): void
    {
        $response = $this->postJson('/api/v1/posts', ['content' => 'hello']);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testUnknownPageReturns404(): void
    {
        $response = $this->get('/definitely-missing-' . bin2hex(random_bytes(4)));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testFullUserJourney(): void
    {
        $username = 'test_' . bin2hex(random_bytes(4));
        $email = $username . '@example.com';
        $password = 'Passw0rd!123';
        self::$createdUsernames[] = $username;

        // 1. 打开注册页，获得会话 cookie 与 CSRF token
        $page = $this->get('/register');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());
        self::assertNotNull($cookie, '注册页应下发会话 cookie');
        self::assertNotNull($csrf, '注册页应包含 CSRF token');

        // 2. 注册（成功后自动登录，会话被轮换）
        $register = $this->send('POST', '/register', [
            'form' => [
                'username' => $username,
                'email' => $email,
                'nickname' => '测试同学',
                'password' => $password,
                'password_confirm' => $password,
                '_csrf' => $csrf,
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $register->getStatusCode(), '注册应重定向');
        $cookie = $this->sessionCookie($register) ?? $cookie;

        // 3. 打开发布页（轮换后的会话 → 新 CSRF token）
        $compose = $this->get('/compose', 'text/html', $cookie);
        self::assertSame(200, $compose->getStatusCode());
        $csrf = $this->csrfToken((string) $compose->getBody());
        self::assertNotNull($csrf);

        // 4. 发布动态
        $store = $this->postJson('/api/v1/posts', [
            'content' => '集成测试动态内容 campus-flow',
            'visibility' => 'public',
            'topics' => ['测试话题'],
            'media_ids' => [],
        ], $cookie, $csrf);
        self::assertSame(201, $store->getStatusCode(), (string) $store->getBody());
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        // 5. 发表评论
        $comment = $this->postJson('/api/v1/comments', [
            'post_id' => $postId,
            'content' => '测试评论内容',
        ], $cookie, $csrf);
        self::assertSame(201, $comment->getStatusCode(), (string) $comment->getBody());

        // 6. 点赞（toggle 幂等语义）
        $like = $this->postJson('/api/v1/reactions/toggle', [
            'target_type' => 'post',
            'target_id' => $postId,
            'kind' => 'like',
        ], $cookie, $csrf);
        self::assertSame(200, $like->getStatusCode());
        $likeData = json_decode((string) $like->getBody(), true)['data'];
        self::assertTrue($likeData['active']);
        self::assertSame(1, $likeData['count']);

        // 7. 重复点赞应取消
        $unlike = $this->postJson('/api/v1/reactions/toggle', [
            'target_type' => 'post',
            'target_id' => $postId,
            'kind' => 'like',
        ], $cookie, $csrf);
        $unlikeData = json_decode((string) $unlike->getBody(), true)['data'];
        self::assertFalse($unlikeData['active']);
        self::assertSame(0, $unlikeData['count']);

        // 8. 详情页可见
        $detail = $this->get('/posts/' . $postId);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('集成测试动态内容', (string) $detail->getBody());

        // 9. 未登录访客不能看到「仅自己可见」动态（这里用私有动态验证）
        $private = $this->postJson('/api/v1/posts', [
            'content' => '私有测试动态',
            'visibility' => 'private',
        ], $cookie, $csrf);
        $privateId = (int) (json_decode((string) $private->getBody(), true)['data']['id'] ?? 0);
        $guestView = $this->get('/posts/' . $privateId);
        self::assertSame(404, $guestView->getStatusCode(), '访客不应看到私有动态');
    }

    public function testCommentCountIsIdempotentAndReactionCountConsistent(): void
    {
        $username = 'test_' . bin2hex(random_bytes(4));
        self::$createdUsernames[] = $username;

        $page = $this->get('/register');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());
        self::assertNotNull($cookie);
        self::assertNotNull($csrf);

        $register = $this->send('POST', '/register', [
            'form' => [
                'username' => $username,
                'email' => $username . '@example.com',
                'nickname' => '幂等测试',
                'password' => 'Passw0rd!123',
                'password_confirm' => 'Passw0rd!123',
                '_csrf' => $csrf,
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $register->getStatusCode());
        $cookie = $this->sessionCookie($register) ?? $cookie;

        $home = $this->get('/', 'text/html', $cookie);
        $csrf = $this->csrfToken((string) $home->getBody());
        self::assertNotNull($csrf);

        $store = $this->postJson('/api/v1/posts', [
            'content' => '幂等测试动态',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
        ], $cookie, $csrf);
        self::assertSame(201, $store->getStatusCode(), (string) $store->getBody());
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        $comment = $this->postJson('/api/v1/comments', [
            'post_id' => $postId,
            'content' => '一条评论',
        ], $cookie, $csrf);
        self::assertSame(201, $comment->getStatusCode(), (string) $comment->getBody());
        $commentId = (int) (json_decode((string) $comment->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $commentId);

        $query = new Query($this->pdo());
        self::assertSame(1, (int) $query->value('SELECT comment_count FROM posts WHERE id = :id', ['id' => $postId]));

        // 重复删除：计数只应递减一次
        $first = $this->send('DELETE', '/api/v1/comments/' . $commentId, ['json' => [], 'cookie' => $cookie, 'csrf' => $csrf]);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame(0, (int) $query->value('SELECT comment_count FROM posts WHERE id = :id', ['id' => $postId]));

        $second = $this->send('DELETE', '/api/v1/comments/' . $commentId, ['json' => [], 'cookie' => $cookie, 'csrf' => $csrf]);
        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        self::assertSame(0, (int) $query->value('SELECT comment_count FROM posts WHERE id = :id', ['id' => $postId]), '重复删除不应再次递减');

        // 点赞计数应与 reactions 实际行数一致（toggle 多次）
        foreach ([1, 2, 3] as $i) {
            $toggle = $this->postJson('/api/v1/reactions/toggle', [
                'target_type' => 'post',
                'target_id' => $postId,
                'kind' => 'like',
            ], $cookie, $csrf);
            self::assertSame(200, $toggle->getStatusCode());

            $rows = (int) $query->value(
                "SELECT COUNT(*) FROM reactions WHERE target_type = 'post' AND target_id = :id AND kind = 'like'",
                ['id' => $postId],
            );
            $count = (int) $query->value('SELECT like_count FROM posts WHERE id = :id', ['id' => $postId]);
            self::assertSame($rows, $count, 'like_count 应与 reactions 行数一致（第 ' . $i . ' 次切换）');
        }
    }

    /**
     * 私密帖（仅自己可见）只能被作者本人看到：其他登录用户、甚至管理员浏览前台时也不可见。
     */
    public function testPrivatePostStaysInvisibleEvenForAdmin(): void
    {
        $author = 'priv_author_' . bin2hex(random_bytes(4));
        self::$createdUsernames[] = $author;
        [$authorCookie, $authorCsrf] = $this->registerAndLogin($author);

        $store = $this->postJson('/api/v1/posts', [
            'content' => '私密帖内容仅作者可见',
            'visibility' => 'private',
        ], $authorCookie, $authorCsrf);
        self::assertSame(201, $store->getStatusCode(), (string) $store->getBody());
        $postId = (int) (json_decode((string) $store->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        // 作者本人：详情与主页都能看到
        self::assertSame(200, $this->get('/posts/' . $postId, 'text/html', $authorCookie)->getStatusCode());
        self::assertStringContainsString(
            '私密帖内容仅作者可见',
            (string) $this->get('/u/' . $author, 'text/html', $authorCookie)->getBody(),
        );

        // 另一个登录用户（未提权）：看不到
        $other = 'priv_other_' . bin2hex(random_bytes(4));
        self::$createdUsernames[] = $other;
        [$otherCookie] = $this->registerAndLogin($other);
        self::assertSame(404, $this->get('/posts/' . $postId, 'text/html', $otherCookie)->getStatusCode());
        self::assertStringNotContainsString(
            '私密帖内容仅作者可见',
            (string) $this->get('/u/' . $author, 'text/html', $otherCookie)->getBody(),
        );

        // 提权为管理员后依然看不到（仅自己可见就是仅自己可见）
        $query = new Query($this->pdo());
        $roleId = (int) $query->value("SELECT id FROM roles WHERE slug = 'super_admin'");
        self::assertGreaterThan(0, $roleId);
        $query->insert('user_roles', [
            'user_id' => (int) $query->value('SELECT id FROM users WHERE username = :u', ['u' => $other]),
            'role_id' => $roleId,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);

        self::assertSame(404, $this->get('/posts/' . $postId, 'text/html', $otherCookie)->getStatusCode(), '管理员也不应看到他人私密帖');
        self::assertStringNotContainsString(
            '私密帖内容仅作者可见',
            (string) $this->get('/u/' . $author, 'text/html', $otherCookie)->getBody(),
            '管理员浏览他人主页不应包含私密帖',
        );

        // 已隐藏的公开帖仍保留管理员审核访问
        $public = $this->postJson('/api/v1/posts', [
            'content' => '待隐藏的公开帖',
            'visibility' => 'public',
        ], $authorCookie, $authorCsrf);
        $publicId = (int) (json_decode((string) $public->getBody(), true)['data']['id'] ?? 0);
        $query->update('posts', ['status' => 'hidden'], 'id = :id', ['id' => $publicId]);
        self::assertSame(200, $this->get('/posts/' . $publicId, 'text/html', $otherCookie)->getStatusCode(), '管理员应能查看已隐藏的公开帖');
    }

    /** @return array{0: string, 1: string} [cookie, csrf] */
    private function registerAndLogin(string $username): array
    {
        $page = $this->get('/register');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());
        self::assertNotNull($cookie);
        self::assertNotNull($csrf);

        $register = $this->send('POST', '/register', [
            'form' => [
                'username' => $username,
                'email' => $username . '@example.com',
                'nickname' => '私密测试',
                'password' => 'Passw0rd!123',
                'password_confirm' => 'Passw0rd!123',
                '_csrf' => $csrf,
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $register->getStatusCode(), (string) $register->getBody());
        $newCookie = $this->sessionCookie($register);
        self::assertNotNull($newCookie);

        $home = $this->get('/', 'text/html', $newCookie);
        $newCsrf = $this->csrfToken((string) $home->getBody());
        self::assertNotNull($newCsrf);

        return [$newCookie, $newCsrf];
    }

    private function pdo(): \PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            $pdo = Connection::connect(\Mofu\Bootstrap\Config::fromEnvironment(dirname(__DIR__, 2)));
        }

        return $pdo;
    }

    // ------------------------------------------------------------------ helpers

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

        // window.__MOFU__ = { csrf: "...", ... }
        if (preg_match('/csrf:\s*"([a-f0-9]{64})"/', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
