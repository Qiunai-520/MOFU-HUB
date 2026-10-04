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
use Slim\Psr7\UploadedFile;

/**
 * 阶段三收官集成测试：校园资讯（草稿/发布、广播通知幂等、权限、置顶、隐藏恢复、封面与编辑）。
 * 使用随机用户，结束后级联清理。
 */
final class NewsFlowTest extends TestCase
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

    public function testDraftPublishBrowsingAndCategoryFilter(): void
    {
        [$cookieAdmin, $csrfAdmin, $adminName] = $this->registerUser('nw_adm');
        $this->promoteToAdmin($this->userId($adminName));

        $title = '2026 秋季学期选课通知 ' . bin2hex(random_bytes(2));
        $create = $this->send('POST', '/admin/news', [
            'form' => [
                '_csrf' => $csrfAdmin,
                'title' => $title,
                'summary' => '选课系统开放时间与注意事项',
                'content' => '第一段内容。
第二段内容。',
                'category' => 'notice',
                'is_pinned' => '',
            ],
            'cookie' => $cookieAdmin,
        ]);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $newsId = (int) (new Query($this->pdo()))->value(
            "SELECT id FROM news WHERE title = :t ORDER BY id DESC LIMIT 1",
            ['t' => $title],
        );
        self::assertGreaterThan(0, $newsId);

        // 草稿：前台不可见，详情 404；管理员可预览
        self::assertStringNotContainsString($title, (string) $this->get('/news')->getBody());
        self::assertSame(404, $this->get('/news/' . $newsId)->getStatusCode());
        $preview = $this->get('/news/' . $newsId, 'text/html', $cookieAdmin);
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('仅资讯管理员可见', (string) $preview->getBody());
        self::assertStringContainsString('草稿', (string) $preview->getBody());

        // 发布 → 前台可见、详情 200
        self::assertSame(302, $this->send('POST', '/admin/news/' . $newsId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'publish'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());

        $list = (string) $this->get('/news')->getBody();
        self::assertStringContainsString($title, $list);
        self::assertStringContainsString('选课系统开放时间与注意事项', $list);

        $detail = $this->get('/news/' . $newsId);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString($title, (string) $detail->getBody());

        // 浏览量随公开访问自增
        self::assertGreaterThanOrEqual(1, $this->viewCount($newsId));
        $views = $this->viewCount($newsId);
        $this->get('/news/' . $newsId);
        self::assertSame($views + 1, $this->viewCount($newsId));

        // 分类筛选
        self::assertStringContainsString($title, (string) $this->get('/news?category=notice')->getBody());
        self::assertStringNotContainsString($title, (string) $this->get('/news?category=resource')->getBody());
    }

    public function testBroadcastNotificationOnlyOnce(): void
    {
        [$cookieAdmin, $csrfAdmin, $adminName] = $this->registerUser('nw_adm2');
        [$cookieUser, , $userName] = $this->registerUser('nw_u');
        $this->promoteToAdmin($this->userId($adminName));

        $title = '校园网络维护公告 ' . bin2hex(random_bytes(2));
        $create = $this->send('POST', '/admin/news', [
            'form' => [
                '_csrf' => $csrfAdmin,
                'title' => $title,
                'summary' => '',
                'content' => '维护时间：周六 02:00-04:00。',
                'category' => 'notice',
                'action' => 'publish',
            ],
            'cookie' => $cookieAdmin,
        ]);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $newsId = (int) (new Query($this->pdo()))->value(
            "SELECT id FROM news WHERE title = :t ORDER BY id DESC LIMIT 1",
            ['t' => $title],
        );
        $userId = $this->userId($userName);

        // 普通用户收到一条广播
        self::assertSame(1, (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :u AND type = 'news_published' AND target_id = :n",
            ['u' => $userId, 'n' => $newsId],
        ));

        // 再次发布 / 隐藏后恢复 → 不重复广播（notified_at 幂等）
        self::assertSame(302, $this->send('POST', '/admin/news/' . $newsId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'hide'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(302, $this->send('POST', '/admin/news/' . $newsId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'publish'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());

        self::assertSame(1, (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :u AND type = 'news_published' AND target_id = :n",
            ['u' => $userId, 'n' => $newsId],
        ));

        // 通知文案包含标题
        $data = (string) (new Query($this->pdo()))->value(
            "SELECT data FROM notifications WHERE user_id = :u AND type = 'news_published' AND target_id = :n",
            ['u' => $userId, 'n' => $newsId],
        );
        self::assertStringContainsString($title, $data);

        // 发布者本人不收到自己资讯的广播
        self::assertSame(0, (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :u AND type = 'news_published' AND target_id = :n",
            ['u' => $this->userId($adminName), 'n' => $newsId],
        ));
    }

    public function testPermissionsDenyRegularUsers(): void
    {
        [$cookieUser, $csrfUser] = $this->registerUser('nw_plain');

        self::assertSame(403, $this->get('/admin/news', 'text/html', $cookieUser)->getStatusCode());
        self::assertSame(403, $this->get('/admin/news/new', 'text/html', $cookieUser)->getStatusCode());
        self::assertSame(403, $this->send('POST', '/admin/news', [
            'form' => ['_csrf' => $csrfUser, 'title' => '越权发布', 'content' => 'x', 'category' => 'campus'],
            'cookie' => $cookieUser,
        ])->getStatusCode());
        self::assertSame(403, $this->send('POST', '/admin/news/1/status', [
            'form' => ['_csrf' => $csrfUser, 'action' => 'publish'],
            'cookie' => $cookieUser,
        ])->getStatusCode());
    }

    public function testPinnedOrdering(): void
    {
        [$cookieAdmin, $csrfAdmin, $adminName] = $this->registerUser('nw_adm3');
        $this->promoteToAdmin($this->userId($adminName));

        $titleA = '学界讲座预告 ' . bin2hex(random_bytes(2));
        $titleB = '社团招新资讯 ' . bin2hex(random_bytes(2));

        $idA = $this->createPublishedNews($cookieAdmin, $csrfAdmin, $titleA);
        $idB = $this->createPublishedNews($cookieAdmin, $csrfAdmin, $titleB);

        // 未置顶时：后发布的 B 在前
        $body = (string) $this->get('/news')->getBody();
        self::assertLessThan(strpos($body, $titleA) ?: 0, strpos($body, $titleB) ?: 0);

        // 置顶 A → A 在前
        self::assertSame(302, $this->send('POST', '/admin/news/' . $idA . '/pin', [
            'form' => ['_csrf' => $csrfAdmin],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());

        $body = (string) $this->get('/news')->getBody();
        $posA = strpos($body, $titleA);
        $posB = strpos($body, $titleB);
        self::assertNotFalse($posA);
        self::assertNotFalse($posB);
        self::assertLessThan($posB, $posA, '置顶资讯应排在前面');
    }

    public function testHideRestoreDeleteAndCoverEdit(): void
    {
        [$cookieAdmin, $csrfAdmin, $adminName] = $this->registerUser('nw_adm4');
        $this->promoteToAdmin($this->userId($adminName));

        // multipart 建带封面的资讯并发布
        $title = '校园风光摄影展 ' . bin2hex(random_bytes(2));
        $create = $this->sendMultipart('/admin/news', [
            '_csrf' => $csrfAdmin,
            'title' => $title,
            'summary' => '',
            'content' => '欢迎参观。',
            'category' => 'campus',
            'action' => 'publish',
        ], ['cover' => $this->pngFile()], $cookieAdmin);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());

        $newsId = (int) (new Query($this->pdo()))->value(
            "SELECT id FROM news WHERE title = :t ORDER BY id DESC LIMIT 1",
            ['t' => $title],
        );
        self::assertNotNull((new Query($this->pdo()))->value(
            'SELECT cover_media_id FROM news WHERE id = :id',
            ['id' => $newsId],
        ));

        $detail = (string) $this->get('/news/' . $newsId)->getBody();
        self::assertStringContainsString('news-detail__cover', $detail);

        // 隐藏 → 访客 404，管理员预览带标记；恢复 → 可见
        self::assertSame(302, $this->send('POST', '/admin/news/' . $newsId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'hide'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/news/' . $newsId)->getStatusCode());
        self::assertStringContainsString('已隐藏', (string) $this->get('/news/' . $newsId, 'text/html', $cookieAdmin)->getBody());

        self::assertSame(302, $this->send('POST', '/admin/news/' . $newsId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'publish'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(200, $this->get('/news/' . $newsId)->getStatusCode());

        // 编辑标题生效
        $newTitle = '校园风光摄影展（改期） ' . bin2hex(random_bytes(2));
        self::assertSame(302, $this->send('POST', '/admin/news/' . $newsId, [
            'form' => [
                '_csrf' => $csrfAdmin,
                'title' => $newTitle,
                'summary' => '改期通知',
                'content' => '因场地调整改期。',
                'category' => 'campus',
            ],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertStringContainsString($newTitle, (string) $this->get('/news/' . $newsId)->getBody());

        // 校验：缺分类 / 空正文 → 422
        $badCategory = $this->send('POST', '/admin/news', [
            'form' => ['_csrf' => $csrfAdmin, 'title' => '缺分类', 'content' => '正文', 'category' => 'nope'],
            'cookie' => $cookieAdmin,
        ]);
        self::assertSame(422, $badCategory->getStatusCode());
        self::assertStringContainsString('分类', (string) $badCategory->getBody());

        $emptyContent = $this->send('POST', '/admin/news', [
            'form' => ['_csrf' => $csrfAdmin, 'title' => '空正文', 'content' => '', 'category' => 'campus'],
            'cookie' => $cookieAdmin,
        ]);
        self::assertSame(422, $emptyContent->getStatusCode());

        // 删除 → 访客 404，后台列表保留「已删除」标记
        self::assertSame(302, $this->send('POST', '/admin/news/' . $newsId . '/delete', [
            'form' => ['_csrf' => $csrfAdmin],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/news/' . $newsId)->getStatusCode());

        $adminList = (string) $this->get('/admin/news', 'text/html', $cookieAdmin)->getBody();
        self::assertStringContainsString($newTitle, $adminList);
        self::assertStringContainsString('已删除', $adminList);
    }

    public function testStatusSetAndListFilters(): void
    {
        [$cookieAdmin, $csrfAdmin, $adminName] = $this->registerUser('nw_adm5');
        $this->promoteToAdmin($this->userId($adminName));

        $title = '图书馆开放时间调整 ' . bin2hex(random_bytes(2));
        $newsId = $this->createPublishedNews($cookieAdmin, $csrfAdmin, $title);

        // 已发布筛选可见
        self::assertStringContainsString($title, (string) $this->get('/admin/news?status=published', 'text/html', $cookieAdmin)->getBody());
        // 草稿筛选不可见
        self::assertStringNotContainsString($title, (string) $this->get('/admin/news?status=draft', 'text/html', $cookieAdmin)->getBody());

        // 分类筛选（admin）
        self::assertStringContainsString($title, (string) $this->get('/admin/news?category=campus', 'text/html', $cookieAdmin)->getBody());
        self::assertStringNotContainsString($title, (string) $this->get('/admin/news?category=resource', 'text/html', $cookieAdmin)->getBody());
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

    private function viewCount(int $newsId): int
    {
        return (int) (new Query($this->pdo()))->value('SELECT view_count FROM news WHERE id = :id', ['id' => $newsId]);
    }

    private function promoteToAdmin(int $userId): void
    {
        $query = new Query($this->pdo());
        $roleId = (int) $query->value("SELECT id FROM roles WHERE slug = 'super_admin'");
        self::assertGreaterThan(0, $roleId);

        $query->insert('user_roles', [
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function createPublishedNews(string $cookie, string $csrf, string $title, string $category = 'campus'): int
    {
        $response = $this->send('POST', '/admin/news', [
            'form' => [
                '_csrf' => $csrf,
                'title' => $title,
                'summary' => '',
                'content' => '测试正文内容。',
                'category' => $category,
                'action' => 'publish',
            ],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        return (int) (new Query($this->pdo()))->value(
            'SELECT id FROM news WHERE title = :t ORDER BY id DESC LIMIT 1',
            ['t' => $title],
        );
    }

    private function pngFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mofu_news_cover_') . '.png';
        $image = imagecreatetruecolor(64, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 140, 200));
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'cover.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK);
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
    private function sendMultipart(string $path, array $fields, array $files, ?string $cookie = null): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $path, ['REMOTE_ADDR' => TestIp::next()] + ($_SERVER ?? []));
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

    private function get(string $path, string $accept = 'text/html', ?string $cookie = null): ResponseInterface
    {
        return $this->send('GET', $path, ['accept' => $accept, 'cookie' => $cookie]);
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
