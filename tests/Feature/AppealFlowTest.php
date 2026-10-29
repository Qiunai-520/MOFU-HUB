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
 * 阶段二 B 包集成测试：封禁申诉（提交、后台处理、解封通知）与会话即时失效。
 * 使用随机用户，结束后清理。
 */
final class AppealFlowTest extends TestCase
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

    public function testAppealAcceptFlow(): void
    {
        [$cookieU, , $usernameU] = $this->registerUser('app_u');
        [$cookieAdmin, $csrfAdmin, $usernameAdmin] = $this->registerUser('app_admin');
        $this->promoteToAdmin($this->userId($usernameAdmin));

        $idU = $this->userId($usernameU);

        // 管理员封禁 U
        $ban = $this->send('POST', '/admin/users/' . $idU . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'status' => 'banned'],
            'cookie' => $cookieAdmin,
        ]);
        self::assertSame(302, $ban->getStatusCode(), (string) $ban->getBody());

        // 封禁后受保护页面不再可用，统一引导到封禁提示页
        $banned = $this->get('/notifications', 'text/html', $cookieU);
        self::assertSame(302, $banned->getStatusCode());
        self::assertStringContainsString('/banned', $banned->getHeaderLine('Location'));

        // 仍然允许登录（以便查看处罚信息并申诉），封禁提示页提供申诉入口
        $login = $this->loginAttempt($usernameU, 'Passw0rd!123');
        self::assertSame(302, $login->getStatusCode());
        $banPage = $this->get('/banned', 'text/html', $cookieU);
        self::assertSame(200, $banPage->getStatusCode());
        self::assertStringContainsString('提交申诉', (string) $banPage->getBody());

        // U 提交申诉（以游客身份，用用户名+密码验证；携带原会话以取得匹配的 CSRF）
        $appealPage = $this->get('/appeal', 'text/html', $cookieU);
        self::assertSame(200, $appealPage->getStatusCode());
        $csrfU = $this->csrfToken((string) $appealPage->getBody());
        self::assertNotNull($csrfU);

        $submit = $this->send('POST', '/appeal', [
            'form' => [
                '_csrf' => $csrfU,
                'username' => $usernameU,
                'password' => 'Passw0rd!123',
                'reason' => '我没有违规，请复核',
            ],
            'cookie' => $cookieU,
        ]);
        self::assertSame(302, $submit->getStatusCode(), (string) $submit->getBody());

        $appealToken = $this->cookieValue($submit, 'mofu_appeal');
        self::assertNotNull($appealToken, '提交申诉后应下发查看令牌 Cookie');

        // 重复提交被拒
        $again = $this->send('POST', '/appeal', [
            'form' => [
                '_csrf' => $csrfU,
                'username' => $usernameU,
                'password' => 'Passw0rd!123',
                'reason' => '再次申诉',
            ],
            'cookie' => $cookieU,
        ]);
        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('已有一条申诉', (string) $again->getBody());

        // 后台可见
        $adminList = $this->get('/admin/appeals', 'text/html', $cookieAdmin);
        self::assertSame(200, $adminList->getStatusCode());
        self::assertStringContainsString('我没有违规，请复核', (string) $adminList->getBody());

        // 管理员通过（附带说明）
        $appealId = (int) (new Query($this->pdo()))->value(
            'SELECT id FROM user_appeals WHERE user_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $idU],
        );
        self::assertGreaterThan(0, $appealId);

        $resolve = $this->send('POST', '/admin/appeals/' . $appealId . '/resolve', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'accept', 'reply' => '经复核予以解封'],
            'cookie' => $cookieAdmin,
        ]);
        self::assertSame(302, $resolve->getStatusCode(), (string) $resolve->getBody());

        // 解封后可登录，且收到系统通知
        self::assertSame(302, $this->loginAttempt($usernameU, 'Passw0rd!123')->getStatusCode());

        $notified = (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'system'",
            ['id' => $idU],
        );
        self::assertGreaterThanOrEqual(1, $notified, '解封后应收到系统通知');

        // 申诉状态凭查看令牌可见；匿名按用户名查询不再泄露
        $page = $this->get('/appeal', 'text/html', null, ['mofu_appeal' => $appealToken]);
        self::assertStringContainsString('已通过', (string) $page->getBody());
        self::assertStringContainsString('经复核予以解封', (string) $page->getBody());

        $anonymous = $this->get('/appeal?username=' . urlencode($usernameU));
        self::assertStringNotContainsString('经复核予以解封', (string) $anonymous->getBody());
    }

    public function testAppealRejectFlowAndPermission(): void
    {
        [$cookieW, , $usernameW] = $this->registerUser('app_w');
        [$cookieAdmin, $csrfAdmin, $usernameAdmin2] = $this->registerUser('app_admin2');
        $this->promoteToAdmin($this->userId($usernameAdmin2));

        $idW = $this->userId($usernameW);

        // 普通用户不能访问申诉管理
        self::assertSame(403, $this->get('/admin/appeals', 'text/html', $cookieW)->getStatusCode());
        self::assertSame(403, $this->send('POST', '/admin/appeals/1/resolve', [
            'form' => ['_csrf' => 'x'],
            'cookie' => $cookieW,
        ])->getStatusCode());

        // 封禁 W 并提交申诉
        self::assertSame(302, $this->send('POST', '/admin/users/' . $idW . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'status' => 'suspended'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());

        $csrfW = $this->csrfToken((string) $this->get('/appeal', 'text/html', $cookieW)->getBody());
        self::assertNotNull($csrfW);
        $submitW = $this->send('POST', '/appeal', [
            'form' => ['_csrf' => $csrfW, 'username' => $usernameW, 'password' => 'Passw0rd!123', 'reason' => '申诉一下'],
            'cookie' => $cookieW,
        ]);
        self::assertSame(302, $submitW->getStatusCode());
        $appealTokenW = $this->cookieValue($submitW, 'mofu_appeal');
        self::assertNotNull($appealTokenW);

        $appealId = (int) (new Query($this->pdo()))->value(
            'SELECT id FROM user_appeals WHERE user_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $idW],
        );

        // 管理员驳回
        self::assertSame(302, $this->send('POST', '/admin/appeals/' . $appealId . '/resolve', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'reject', 'reply' => '维持封禁'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());

        // 仍处于封禁状态：登录后受保护页仍被引导到封禁提示页；凭查看令牌可见未通过与说明，匿名按用户名不可见
        self::assertSame(302, $this->loginAttempt($usernameW, 'Passw0rd!123')->getStatusCode());
        $stillBanned = $this->get('/notifications', 'text/html', $cookieW);
        self::assertSame(302, $stillBanned->getStatusCode());
        self::assertStringContainsString('/banned', $stillBanned->getHeaderLine('Location'));
        $page = $this->get('/appeal', 'text/html', null, ['mofu_appeal' => $appealTokenW]);
        self::assertStringContainsString('未通过', (string) $page->getBody());
        self::assertStringContainsString('维持封禁', (string) $page->getBody());

        self::assertStringNotContainsString(
            '维持封禁',
            (string) $this->get('/appeal?username=' . urlencode($usernameW))->getBody(),
        );

        // 处理过的申诉不可重复处理
        self::assertSame(422, $this->send('POST', '/admin/appeals/' . $appealId . '/resolve', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'accept'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
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

    private function promoteToAdmin(int $userId): void
    {
        $query = new Query($this->pdo());
        $roleId = (int) $query->value("SELECT id FROM roles WHERE slug = 'super_admin'");
        self::assertGreaterThan(0, $roleId);

        $query->run('INSERT IGNORE INTO user_roles (user_id, role_id, assigned_at) VALUES (:user_id, :role_id, :assigned_at)', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);
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

    private function loginAttempt(string $login, string $password): ResponseInterface
    {
        $page = $this->get('/login');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());

        return $this->send('POST', '/login', [
            'form' => ['login' => $login, 'password' => $password, '_csrf' => $csrf ?? ''],
            'cookie' => $cookie,
        ]);
    }

    private function send(string $method, string $path, array $options = []): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $path, ['REMOTE_ADDR' => TestIp::next()] + ($_SERVER ?? []));

        $request = $request->withHeader('Accept', $options['accept'] ?? 'text/html');

        if (isset($options['cookies']) && $options['cookies'] !== []) {
            $request = $request->withCookieParams($options['cookies']);
        } elseif (isset($options['cookie'])) {
            $request = $request->withCookieParams(['mofu_session' => $options['cookie']]);
        }

        if (isset($options['form'])) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
            $request = $request->withParsedBody($options['form']);
        }

        self::assertNotNull(self::$app);

        return self::$app->handle($request);
    }

    private function get(string $path, string $accept = 'text/html', ?string $cookie = null, array $cookies = []): ResponseInterface
    {
        return $this->send('GET', $path, ['accept' => $accept, 'cookie' => $cookie, 'cookies' => $cookies]);
    }

    private function cookieValue(ResponseInterface $response, string $name): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^' . preg_quote($name, '/') . '=([^;]+)/', $header, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
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
