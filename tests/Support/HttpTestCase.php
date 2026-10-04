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
                $query->delete('users', 'username = :username', ['username' => $username]);
            }
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

        $query->insert('user_roles', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} [cookie, csrf, username] */
    protected function registerUser(string $prefix): array
    {
        $username = $prefix . '_' . bin2hex(random_bytes(4));
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
                'nickname' => '测试' . $prefix,
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

        return [$newCookie, $newCsrf, $username];
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

        if (isset($options['form'])) {
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
            $request = $request->withParsedBody($options['form']);
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
