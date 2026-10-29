<?php

declare(strict_types=1);

namespace Mofu\Tests\Support;

use Mofu\Bootstrap\App;
use Mofu\Core\Database\Connection;
use Mofu\Core\Database\Query;
use Mofu\Tests\Support\TestIp;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * HTTP 集成测试基类：内核启动、会话 / CSRF 脚手架与随机用户清理。
 */
abstract class HttpTestCase extends TestCase
{
    protected static ?SlimApp $app = null;

    /** @var list<string> */
    protected static array $createdUsernames = [];

    /**
     * 每个模拟请求使用独立来源 IP，避免测试进程内触发限流（详见 TestIp）。
     */
    protected static function nextIp(): string
    {
        return TestIp::next();
    }

    public static function setUpBeforeClass(): void
    {
        self::$app = App::create(dirname(__DIR__, 2));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$createdUsernames !== []) {
            $query = new Query(Connection::connect(\Mofu\Bootstrap\Config::fromEnvironment(dirname(__DIR__, 2))));

            foreach (self::$createdUsernames as $username) {
                // 测试用户可能创建过圈子；communities.owner_id 现为 ON DELETE RESTRICT，
                // 需先删掉其圈子（会级联清理圈内测试动态）再删用户。
                $userId = $query->value('SELECT id FROM users WHERE username = :username', ['username' => $username]);

                if ($userId !== null) {
                    $query->run('DELETE FROM communities WHERE owner_id = :id', ['id' => (int) $userId]);
                }

                $query->delete('users', 'username = :username', ['username' => $username]);
            }

            // 清理测试创建但已不再被任何动态引用的话题，避免热门话题被测试数据污染。
            $query->run(
                "DELETE FROM topics
                 WHERE (name = '测试话题' OR name LIKE '检索话题%')
                   AND NOT EXISTS (SELECT 1 FROM post_topics pt WHERE pt.topic_id = topics.id)",
            );
        }

        self::$createdUsernames = [];
    }

    protected function pdo(): \PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            $pdo = Connection::connect(\Mofu\Bootstrap\Config::fromEnvironment(dirname(__DIR__, 2)));
        }

        return $pdo;
    }

    protected function query(): Query
    {
        return new Query($this->pdo());
    }

    protected function userId(string $username): int
    {
        return (int) $this->query()->value('SELECT id FROM users WHERE username = :u', ['u' => $username]);
    }

    protected function usernameOf(string $prefix): string
    {
        foreach (self::$createdUsernames as $username) {
            if (str_starts_with($username, $prefix . '_')) {
                return $username;
            }
        }

        self::fail('未找到注册用户：' . $prefix);
    }

    protected function promoteToAdmin(int $userId): void
    {
        $query = $this->query();
        $roleId = (int) $query->value("SELECT id FROM roles WHERE slug = 'super_admin'");
        self::assertGreaterThan(0, $roleId);

        $query->run('INSERT IGNORE INTO user_roles (user_id, role_id, assigned_at) VALUES (:user_id, :role_id, :assigned_at)', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} [cookie, csrf, username] */
    protected function registerUser(string $prefix, string $invite = ''): array
    {
        $username = $prefix . '_' . bin2hex(random_bytes(4));
        self::$createdUsernames[] = $username;
        $email = $username . '@example.com';
        $nickname = '测试' . $prefix;

        $page = $this->get('/register');
        $cookie = $this->sessionCookie($page);
        $csrf = $this->csrfToken((string) $page->getBody());
        self::assertNotNull($cookie);
        self::assertNotNull($csrf);

        // 1) 邮箱注册第一步：发送验证码（默认 log 邮件驱动，验证码写入 storage/logs/mail.log）
        $sent = $this->send('POST', '/register', [
            'form' => ['email' => $email, 'nickname' => $nickname, 'invite' => $invite, '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(200, $sent->getStatusCode(), (string) $sent->getBody());

        $code = $this->mailCode($email);
        self::assertNotNull($code, '未找到注册验证码');

        // 2) 校验验证码并创建账号
        $verify = $this->send('POST', '/register/verify', [
            'form' => [
                'email' => $email,
                'nickname' => $nickname,
                'code' => $code,
                'invite' => $invite,
                '_csrf' => (string) $this->csrfToken((string) $sent->getBody()),
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $verify->getStatusCode(), (string) $verify->getBody());

        $newCookie = $this->sessionCookie($verify);
        self::assertNotNull($newCookie);

        // 3) 邮箱注册不设密码；测试里需要密码登录，等价于用户在设置页设置密码
        $this->query()->run(
            'UPDATE users SET password_hash = :hash, password_set = 1 WHERE username = :username',
            ['hash' => password_hash('Passw0rd!123', PASSWORD_DEFAULT), 'username' => $username],
        );

        $home = $this->get('/', 'text/html', $newCookie);
        $newCsrf = $this->csrfToken((string) $home->getBody());
        self::assertNotNull($newCsrf);

        return [$newCookie, $newCsrf, $username];
    }

    /** 从邮件日志中取该邮箱最近一次验证码 */
    protected function mailCode(string $email): ?string
    {
        $log = dirname(__DIR__, 2) . '/storage/logs/mail.log';

        if (!is_file($log)) {
            return null;
        }

        $content = (string) file_get_contents($log);
        $pattern = '/to=' . preg_quote($email, '/') . ' subject=.*?验证码是：?(\d{6})/s';

        if (preg_match_all($pattern, $content, $matches) === 0 || $matches[1] === []) {
            return null;
        }

        return (string) end($matches[1]);
    }

    protected function send(string $method, string $path, array $options = []): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $path, ['REMOTE_ADDR' => self::nextIp()] + ($_SERVER ?? []));

        // 让超全局与当前请求一致：部分代码（如 Guard 的登录回跳）会读 REQUEST_URI
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = strtoupper($method);

        $request = $request->withHeader('Accept', $options['accept'] ?? 'text/html');

        if (isset($options['cookie'])) {
            $request = $request->withCookieParams(['mofu_session' => $options['cookie']]);
        }

        if (isset($options['csrf'])) {
            $request = $request->withHeader('X-CSRF-Token', (string) $options['csrf']);
        }

        if (isset($options['form'])) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
            $request = $request->withParsedBody($options['form']);
        }

        if (isset($options['json'])) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode($options['json']));
            $request = $request->withParsedBody($options['json']);
        }

        self::assertNotNull(self::$app);

        return self::$app->handle($request);
    }

    protected function get(string $path, string $accept = 'text/html', ?string $cookie = null): ResponseInterface
    {
        return $this->send('GET', $path, ['accept' => $accept, 'cookie' => $cookie]);
    }

    /** @param array<string, mixed> $json */
    protected function postJson(string $path, array $json = [], ?string $cookie = null, ?string $csrf = null): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $path, ['REMOTE_ADDR' => self::nextIp()] + ($_SERVER ?? []));
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = $request->withHeader('Accept', 'application/json');
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withHeader('X-CSRF-Token', $csrf ?? '');

        if ($cookie !== null) {
            $request = $request->withCookieParams(['mofu_session' => $cookie]);
        }

        $request->getBody()->write((string) json_encode($json));
        $request = $request->withParsedBody($json);

        self::assertNotNull(self::$app);

        return self::$app->handle($request);
    }

    protected function deleteJson(string $path, ?string $cookie = null, ?string $csrf = null): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('DELETE', $path, ['REMOTE_ADDR' => self::nextIp()] + ($_SERVER ?? []));
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $request = $request->withHeader('Accept', 'application/json');
        $request = $request->withHeader('X-CSRF-Token', $csrf ?? '');

        if ($cookie !== null) {
            $request = $request->withCookieParams(['mofu_session' => $cookie]);
        }

        self::assertNotNull(self::$app);

        return self::$app->handle($request);
    }

    /**
     * 模拟 multipart 表单（字段 + 文件）。
     *
     * @param array<string, string>       $fields
     * @param array<string, UploadedFile> $files
     */
    protected function sendMultipart(string $path, array $fields, array $files = [], ?string $cookie = null): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $path, ['REMOTE_ADDR' => self::nextIp()] + ($_SERVER ?? []));
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = $request->withHeader('Accept', 'text/html');
        $request = $request->withHeader('Content-Type', 'multipart/form-data; boundary=test');

        if ($cookie !== null) {
            $request = $request->withCookieParams(['mofu_session' => $cookie]);
        }

        $request = $request->withParsedBody($fields);
        $request = $request->withUploadedFiles($files);

        self::assertNotNull(self::$app);

        return self::$app->handle($request);
    }

    protected function location(ResponseInterface $response): string
    {
        return $response->getHeaderLine('Location');
    }

    protected function sessionCookie(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^mofu_session=([a-f0-9]{64})/', $header, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    protected function csrfToken(string $html): ?string
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
