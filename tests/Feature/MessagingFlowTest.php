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
 * 阶段二 A 包集成测试：私信、拉黑、隐私设置。
 * 使用随机用户，结束后清理。
 */
final class MessagingFlowTest extends TestCase
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
    }

    public function testDirectMessageFlow(): void
    {
        [$cookieA, $csrfA, $usernameA] = $this->registerUser('dm_a');
        [$cookieB, $csrfB, $usernameB] = $this->registerUser('dm_b');
        [$cookieC] = $this->registerUser('dm_c');

        // A 打开与 B 的会话（自动创建并跳转）
        $open = $this->get('/messages/with/' . $usernameB, 'text/html', $cookieA);
        self::assertSame(302, $open->getStatusCode());
        $location = $open->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^/messages/[0-9]+$#', $location);
        $conversationId = (int) substr($location, strlen('/messages/'));

        // A 发送第一条消息
        $first = $this->postJson('/api/v1/conversations/' . $conversationId . '/messages', [
            'content' => '你好，B！',
        ], $cookieA, $csrfA);
        self::assertSame(201, $first->getStatusCode(), (string) $first->getBody());
        $firstData = json_decode((string) $first->getBody(), true)['data'];
        self::assertTrue($firstData['mine']);
        $firstId = (int) $firstData['id'];

        // B 未读数 = 1；打开后归零
        $unread = $this->get('/api/v1/messages/unread-count', 'application/json', $cookieB);
        self::assertSame(1, json_decode((string) $unread->getBody(), true)['data']['count']);

        $list = $this->get('/messages', 'text/html', $cookieB);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('你好，B！', (string) $list->getBody());

        $chat = $this->get('/messages/' . $conversationId, 'text/html', $cookieB);
        self::assertSame(200, $chat->getStatusCode());
        self::assertStringContainsString('你好，B！', (string) $chat->getBody());
        self::assertStringContainsString('msg', (string) $chat->getBody());

        $unreadAfter = $this->get('/api/v1/messages/unread-count', 'application/json', $cookieB);
        self::assertSame(0, json_decode((string) $unreadAfter->getBody(), true)['data']['count']);

        // B 回复；A 用 after_id 轮询拉取（模拟前端轮询）
        $reply = $this->postJson('/api/v1/conversations/' . $conversationId . '/messages', [
            'content' => '你好，A！',
        ], $cookieB, $csrfB);
        self::assertSame(201, $reply->getStatusCode());

        $poll = $this->get(
            '/api/v1/conversations/' . $conversationId . '/messages?after_id=' . $firstId,
            'application/json',
            $cookieA,
        );
        $pollItems = json_decode((string) $poll->getBody(), true)['data'];
        self::assertCount(1, $pollItems);
        self::assertSame('你好，A！', $pollItems[0]['content']);
        self::assertFalse($pollItems[0]['mine']);

        // 非参与者不可访问会话与消息
        self::assertSame(404, $this->get('/messages/' . $conversationId, 'text/html', $cookieC)->getStatusCode());
        self::assertSame(404, $this->get('/api/v1/conversations/' . $conversationId . '/messages', 'application/json', $cookieC)->getStatusCode());
    }

    public function testDmPolicyIsEnforced(): void
    {
        [$cookieA, $csrfA, $usernameA] = $this->registerUser('pol_a');
        [$cookieB, $csrfB, $usernameB] = $this->registerUser('pol_b');

        // 先建立会话（策略不影响历史会话的创建与查看）
        $open = $this->get('/messages/with/' . $usernameB, 'text/html', $cookieA);
        $conversationId = (int) substr($open->getHeaderLine('Location'), strlen('/messages/'));

        // B 关闭私信
        $save = $this->send('POST', '/settings/privacy', [
            'form' => ['_csrf' => $csrfB, 'dm_policy' => 'none', 'connections_visibility' => 'public'],
            'cookie' => $cookieB,
        ]);
        self::assertSame(302, $save->getStatusCode());

        $blocked = $this->postJson('/api/v1/conversations/' . $conversationId . '/messages', [
            'content' => '在吗',
        ], $cookieA, $csrfA);
        self::assertSame(403, $blocked->getStatusCode());
        self::assertStringContainsString('不接收私信', (string) $blocked->getBody());

        // 切换为互关才可发送
        $this->send('POST', '/settings/privacy', [
            'form' => ['_csrf' => $csrfB, 'dm_policy' => 'mutual', 'connections_visibility' => 'public'],
            'cookie' => $cookieB,
        ]);

        // A 单向关注 B：仍不可发送
        $followA = $this->postJson('/api/v1/follows/toggle', ['user_id' => $this->userId($usernameB)], $cookieA, $csrfA);
        self::assertSame(200, $followA->getStatusCode());
        $stillBlocked = $this->postJson('/api/v1/conversations/' . $conversationId . '/messages', [
            'content' => '在吗',
        ], $cookieA, $csrfA);
        self::assertSame(403, $stillBlocked->getStatusCode());
        self::assertStringContainsString('互相关注', (string) $stillBlocked->getBody());

        // B 回关后即可发送
        $followB = $this->postJson('/api/v1/follows/toggle', ['user_id' => $this->userId($usernameA)], $cookieB, $csrfB);
        self::assertSame(200, $followB->getStatusCode());
        $ok = $this->postJson('/api/v1/conversations/' . $conversationId . '/messages', [
            'content' => '现在可以了',
        ], $cookieA, $csrfA);
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
    }

    public function testBlockRemovesFollowsAndPreventsMessages(): void
    {
        [$cookieA, $csrfA, $usernameA] = $this->registerUser('blk_a');
        [$cookieB, $csrfB, $usernameB] = $this->registerUser('blk_b');

        $idA = $this->userId($usernameA);
        $idB = $this->userId($usernameB);

        // 双向关注 + 建立会话
        self::assertSame(200, $this->postJson('/api/v1/follows/toggle', ['user_id' => $idB], $cookieA, $csrfA)->getStatusCode());
        self::assertSame(200, $this->postJson('/api/v1/follows/toggle', ['user_id' => $idA], $cookieB, $csrfB)->getStatusCode());
        $open = $this->get('/messages/with/' . $usernameB, 'text/html', $cookieA);
        $conversationId = (int) substr($open->getHeaderLine('Location'), strlen('/messages/'));

        // A 拉黑 B
        $block = $this->postJson('/api/v1/blocks/toggle', ['user_id' => $idB], $cookieA, $csrfA);
        self::assertSame(200, $block->getStatusCode());
        self::assertTrue(json_decode((string) $block->getBody(), true)['data']['blocked']);

        // 已有关注关系被解除
        $follows = (int) $this->pdo()->query(
            sprintf('SELECT COUNT(*) FROM follows WHERE (follower_id = %d AND followee_id = %d) OR (follower_id = %d AND followee_id = %d)', $idA, $idB, $idB, $idA),
        )->fetchColumn();
        self::assertSame(0, $follows, '拉黑后应解除双向关注');

        // B 无法关注 A；双方无法私信
        $followDenied = $this->postJson('/api/v1/follows/toggle', ['user_id' => $idA], $cookieB, $csrfB);
        self::assertSame(422, $followDenied->getStatusCode());

        $msgDenied = $this->postJson('/api/v1/conversations/' . $conversationId . '/messages', [
            'content' => '喂',
        ], $cookieB, $csrfB);
        self::assertSame(403, $msgDenied->getStatusCode());
        self::assertStringContainsString('拉黑', (string) $msgDenied->getBody());

        $msgDenied2 = $this->postJson('/api/v1/conversations/' . $conversationId . '/messages', [
            'content' => '喂',
        ], $cookieA, $csrfA);
        self::assertSame(403, $msgDenied2->getStatusCode());

        // 解除拉黑后可再次关注
        $unblock = $this->postJson('/api/v1/blocks/toggle', ['user_id' => $idB], $cookieA, $csrfA);
        self::assertFalse(json_decode((string) $unblock->getBody(), true)['data']['blocked']);
        self::assertSame(200, $this->postJson('/api/v1/follows/toggle', ['user_id' => $idA], $cookieB, $csrfB)->getStatusCode());
    }

    public function testRecallAndMarkConversationRead(): void
    {
        [$cookieA, $csrfA, $nameA] = $this->registerUser('dma');
        [$cookieB, $csrfB, $nameB] = $this->registerUser('dmb');
        $query = new \Mofu\Core\Database\Query(\Mofu\Core\Database\Connection::connect(\Mofu\Bootstrap\Config::fromEnvironment(dirname(__DIR__, 2))));

        // 打开与 B 的私信会自动建会话
        $this->get('/messages/with/' . $nameB, 'text/html', $cookieA);
        $conversationId = (int) $query->value('SELECT id FROM conversations ORDER BY id DESC LIMIT 1');
        self::assertGreaterThan(0, $conversationId);

        $send = $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['content' => '待撤回的消息'], $cookieA, $csrfA);
        self::assertSame(201, $send->getStatusCode(), (string) $send->getBody());

        $messageId = (int) (json_decode((string) $send->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $messageId);

        // B 把会话标为已读（会话列表长按菜单用）
        $markRead = $this->postJson("/api/v1/conversations/{$conversationId}/read", [], $cookieB, $csrfB);
        self::assertSame(200, $markRead->getStatusCode(), (string) $markRead->getBody());
        self::assertSame(0, (int) $query->value(
            'SELECT COUNT(*) FROM messages WHERE conversation_id = :id AND sender_id != :me AND read_at IS NULL',
            ['id' => $conversationId, 'me' => (int) $query->value('SELECT id FROM users WHERE username = :u', ['u' => $nameB])],
        ), '标为已读后不应再有未读');

        // A 撤回自己的消息：气泡变占位，原文不再下发
        $recall = $this->postJson("/api/v1/messages/{$messageId}/recall", [], $cookieA, $csrfA);
        self::assertSame(200, $recall->getStatusCode(), (string) $recall->getBody());

        $view = $this->get('/messages/' . $conversationId, 'text/html', $cookieB);
        self::assertSame(200, $view->getStatusCode());
        self::assertStringContainsString('消息已撤回', (string) $view->getBody());
        self::assertStringNotContainsString('待撤回的消息', (string) $view->getBody());

        // 重复撤回被拒
        self::assertSame(422, $this->postJson("/api/v1/messages/{$messageId}/recall", [], $cookieA, $csrfA)->getStatusCode());
    }

    public function testConnectionsVisibilityPrivacy(): void
    {
        [$cookieA, $csrfA, $usernameA] = $this->registerUser('vis_a');

        // A 隐藏关注/粉丝列表
        self::assertSame(302, $this->send('POST', '/settings/privacy', [
            'form' => ['_csrf' => $csrfA, 'dm_policy' => 'all', 'connections_visibility' => 'self'],
            'cookie' => $cookieA,
        ])->getStatusCode());

        // A 自己的列表仍可见（这里验证页面 200 且不含隐私提示）
        $own = $this->get('/u/' . $usernameA . '?tab=followers', 'text/html', $cookieA);
        self::assertSame(200, $own->getStatusCode());
        self::assertStringNotContainsString('对方设置了隐私保护', (string) $own->getBody());

        // 其他用户与游客仅看到提示
        [$cookieC] = $this->registerUser('vis_c');
        $other = $this->get('/u/' . $usernameA . '?tab=following', 'text/html', $cookieC);
        self::assertStringContainsString('对方设置了隐私保护', (string) $other->getBody());
        $guest = $this->get('/u/' . $usernameA . '?tab=followers');
        self::assertStringContainsString('关注与粉丝列表仅本人可见', (string) $guest->getBody());

        // 隐私设置页可用并显示拉黑名单区块
        $privacy = $this->get('/settings/privacy', 'text/html', $cookieA);
        self::assertSame(200, $privacy->getStatusCode());
        self::assertStringContainsString('拉黑名单', (string) $privacy->getBody());
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

    private function userId(string $username): int
    {
        return (int) (new Query($this->pdo()))->value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
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

        // 私信 / 评论有等级门槛：测试用户默认提升为正式会员（Lv.2）
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
