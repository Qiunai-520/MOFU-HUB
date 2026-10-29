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
 * 阶段三 B 集成测试：校园活动（创建/封面、报名与容量、审核制、编辑取消、后台与我的活动）。
 * 使用随机用户，结束后级联清理。
 */
final class EventFlowTest extends TestCase
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

    public function testCreateEventWithCoverListAndDetail(): void
    {
        [$cookieOrg, $csrfOrg, $orgName] = $this->registerUser('ev_org');

        $start = date('Y-m-d\TH:i', time() + 86400);
        $title = '摄影外拍活动 ' . bin2hex(random_bytes(2));
        $create = $this->sendMultipart('/events', [
            '_csrf' => $csrfOrg,
            'title' => $title,
            'description' => "集合后统一出发，请自备相机与水。\n第二行说明。",
            'location' => '南门广场',
            'start_at' => $start,
            'end_at' => '',
            'signup_deadline' => '',
            'capacity' => '20',
        ], ['cover' => $this->pngFile()], $cookieOrg);

        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $eventId = (int) basename($this->location($create));
        self::assertGreaterThan(0, $eventId);

        // 列表与详情
        $list = $this->get('/events', 'text/html', $cookieOrg);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString($title, (string) $list->getBody());

        $detail = $this->get('/events/' . $eventId, 'text/html', $cookieOrg);
        self::assertSame(200, $detail->getStatusCode());
        $detailBody = (string) $detail->getBody();
        self::assertStringContainsString($title, $detailBody);
        self::assertStringContainsString('南门广场', $detailBody);
        self::assertStringContainsString('测试ev_org', $detailBody);
        self::assertStringContainsString(date('n月j日', strtotime(str_replace('T', ' ', $start))), $detailBody);
        self::assertStringContainsString('event-detail__cover', $detailBody, '封面应渲染在详情页');

        // 封面已写入 media
        self::assertNotNull((new Query($this->pdo()))->value(
            'SELECT cover_media_id FROM events WHERE id = :id',
            ['id' => $eventId],
        ));

        // 访客可查看，提示登录后报名；组织者看到管理区
        $guest = $this->get('/events/' . $eventId);
        self::assertSame(200, $guest->getStatusCode());
        self::assertStringContainsString('登录后报名', (string) $guest->getBody());
        self::assertStringContainsString('组织者管理', $detailBody);

        // 说明渲染为富文本（换行保留）
        self::assertStringContainsString('第二行说明', $detailBody);
    }

    public function testDirectSignupCapacityCancelAndTimeGuards(): void
    {
        [$cookieOrg, $csrfOrg] = $this->registerUser('ev2_org');
        [$cookieA, $csrfA] = $this->registerUser('ev2_a');
        [$cookieB, $csrfB] = $this->registerUser('ev2_b');

        $eventId = $this->createEvent($cookieOrg, $csrfOrg, 'ev2', ['capacity' => '1']);

        // A 报名（带备注）
        $signup = $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA, 'note' => '联系方式 13800000000'],
            'cookie' => $cookieA,
        ]);
        self::assertSame(302, $signup->getStatusCode(), (string) $signup->getBody());
        self::assertSame(1, $this->signupCount($eventId));
        self::assertSame('approved', $this->signupStatus($eventId, $this->userId($this->usernameOf('ev2_a'))));

        $detailA = $this->get('/events/' . $eventId, 'text/html', $cookieA);
        self::assertStringContainsString('已报名', (string) $detailA->getBody());
        self::assertStringNotContainsString('13800000000', (string) $detailA->getBody(), '报名备注仅组织者可见');
        self::assertStringContainsString('13800000000', (string) $this->get('/events/' . $eventId, 'text/html', $cookieOrg)->getBody());

        // 重复报名被拒
        self::assertSame(422, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA],
            'cookie' => $cookieA,
        ])->getStatusCode());

        // B 报名 → 名额已满
        $full = $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfB],
            'cookie' => $cookieB,
        ]);
        self::assertSame(422, $full->getStatusCode());
        self::assertStringContainsString('名额已满', (string) $full->getBody());

        // 组织者不能报名自己的活动
        $self = $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfOrg],
            'cookie' => $cookieOrg,
        ]);
        self::assertSame(422, $self->getStatusCode());
        self::assertStringContainsString('组织者', (string) $self->getBody());

        // A 取消 → B 可以报名
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signup/cancel', [
            'form' => ['_csrf' => $csrfA],
            'cookie' => $cookieA,
        ])->getStatusCode());
        self::assertSame(0, $this->signupCount($eventId));

        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfB],
            'cookie' => $cookieB,
        ])->getStatusCode());
        self::assertSame(1, $this->signupCount($eventId));

        // 活动已开始 → 不可报名（回溯时间）
        $this->setEventTime($eventId, 'start_at', date('Y-m-d H:i:s', time() - 3600));
        $started = $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA],
            'cookie' => $cookieA,
        ]);
        self::assertSame(422, $started->getStatusCode());
        self::assertStringContainsString('已开始', (string) $started->getBody());

        // 报名截止已过 → 不可报名
        $this->setEventTime($eventId, 'start_at', date('Y-m-d H:i:s', time() + 86400));
        $this->setEventTime($eventId, 'signup_deadline', date('Y-m-d H:i:s', time() - 3600));
        $expired = $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA],
            'cookie' => $cookieA,
        ]);
        self::assertSame(422, $expired->getStatusCode());
        self::assertStringContainsString('报名已截止', (string) $expired->getBody());
    }

    public function testApprovalSignupFlow(): void
    {
        [$cookieOrg, $csrfOrg] = $this->registerUser('ev3_org');
        [$cookieA, $csrfA] = $this->registerUser('ev3_a');
        [$cookieB, $csrfB] = $this->registerUser('ev3_b');
        [$cookieC, $csrfC] = $this->registerUser('ev3_c');

        $eventId = $this->createEvent($cookieOrg, $csrfOrg, 'ev3', ['requires_approval' => '1']);
        $aId = $this->userId($this->usernameOf('ev3_a'));
        $bId = $this->userId($this->usernameOf('ev3_b'));

        // A 报名 → 待审核
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA, 'note' => '我是 A，求通过'],
            'cookie' => $cookieA,
        ])->getStatusCode());
        self::assertSame('pending', $this->signupStatus($eventId, $aId));
        self::assertSame(0, $this->signupCount($eventId));

        self::assertStringContainsString('报名待审核', (string) $this->get('/events/' . $eventId, 'text/html', $cookieA)->getBody());

        // 组织者收到待审核通知，且名单可见备注
        $orgId = $this->userId($this->usernameOf('ev3_org'));
        self::assertGreaterThanOrEqual(1, (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'event_signup_pending'",
            ['id' => $orgId],
        ));
        $orgView = (string) $this->get('/events/' . $eventId, 'text/html', $cookieOrg)->getBody();
        self::assertStringContainsString('待审核', $orgView);
        self::assertStringContainsString('我是 A，求通过', $orgView);

        // 非组织者不能审核
        self::assertSame(403, $this->send('POST', '/events/' . $eventId . '/signups/' . $aId . '/approve', [
            'form' => ['_csrf' => $csrfC],
            'cookie' => $cookieC,
        ])->getStatusCode());

        // 组织者通过 → A 收到结果通知
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signups/' . $aId . '/approve', [
            'form' => ['_csrf' => $csrfOrg],
            'cookie' => $cookieOrg,
        ])->getStatusCode());
        self::assertSame('approved', $this->signupStatus($eventId, $aId));
        self::assertSame(1, $this->signupCount($eventId));
        self::assertGreaterThanOrEqual(1, (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'event_signup_result'",
            ['id' => $aId],
        ));
        self::assertStringContainsString('已报名', (string) $this->get('/events/' . $eventId, 'text/html', $cookieA)->getBody());

        // 已通过的报名不能再通过
        self::assertSame(422, $this->send('POST', '/events/' . $eventId . '/signups/' . $aId . '/approve', [
            'form' => ['_csrf' => $csrfOrg],
            'cookie' => $cookieOrg,
        ])->getStatusCode());

        // B 报名 → 拒绝 → 可重新报名
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfB, 'note' => ''],
            'cookie' => $cookieB,
        ])->getStatusCode());
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signups/' . $bId . '/reject', [
            'form' => ['_csrf' => $csrfOrg],
            'cookie' => $cookieOrg,
        ])->getStatusCode());
        self::assertSame('rejected', $this->signupStatus($eventId, $bId));
        self::assertGreaterThanOrEqual(1, (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'event_signup_result'",
            ['id' => $bId],
        ));

        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfB],
            'cookie' => $cookieB,
        ])->getStatusCode());
        self::assertSame('pending', $this->signupStatus($eventId, $bId));

        // 已通过者可见名单，但看不到他人备注与待审核队列
        $aView = (string) $this->get('/events/' . $eventId, 'text/html', $cookieA)->getBody();
        self::assertStringContainsString('测试ev3_a', $aView);
        self::assertStringNotContainsString('测试ev3_b', $aView);
    }

    public function testEditCancelPermissionsAndNotifications(): void
    {
        [$cookieOrg, $csrfOrg] = $this->registerUser('ev4_org');
        [$cookieA, $csrfA] = $this->registerUser('ev4_a');

        $title = '读书分享会 ' . bin2hex(random_bytes(2));
        $eventId = $this->createEvent($cookieOrg, $csrfOrg, 'ev4', ['title' => $title]);
        $aId = $this->userId($this->usernameOf('ev4_a'));

        // 非组织者不能编辑
        self::assertSame(403, $this->get('/events/' . $eventId . '/edit', 'text/html', $cookieA)->getStatusCode());
        self::assertSame(403, $this->send('POST', '/events/' . $eventId, [
            'form' => ['_csrf' => $csrfA, 'title' => '篡改'],
            'cookie' => $cookieA,
        ])->getStatusCode());

        // 组织者编辑表单可见当前内容
        $editForm = $this->get('/events/' . $eventId . '/edit', 'text/html', $cookieOrg);
        self::assertSame(200, $editForm->getStatusCode());
        self::assertStringContainsString($title, (string) $editForm->getBody());

        // 组织者修改标题与地点
        $newTitle = '读书分享会（改期） ' . bin2hex(random_bytes(2));
        $update = $this->send('POST', '/events/' . $eventId, [
            'form' => [
                '_csrf' => $csrfOrg,
                'title' => $newTitle,
                'description' => '更新后的说明',
                'location' => '图书馆研讨室',
                'start_at' => date('Y-m-d\TH:i', time() + 172800),
                'end_at' => '',
                'signup_deadline' => '',
                'capacity' => '30',
            ],
            'cookie' => $cookieOrg,
        ]);
        self::assertSame(302, $update->getStatusCode(), (string) $update->getBody());
        $detail = (string) $this->get('/events/' . $eventId, 'text/html', $cookieOrg)->getBody();
        self::assertStringContainsString($newTitle, $detail);
        self::assertStringContainsString('图书馆研讨室', $detail);

        // A 报名后活动被取消 → A 收到取消通知
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA],
            'cookie' => $cookieA,
        ])->getStatusCode());
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/cancel', [
            'form' => ['_csrf' => $csrfOrg],
            'cookie' => $cookieOrg,
        ])->getStatusCode());
        self::assertStringContainsString('已取消', (string) $this->get('/events/' . $eventId, 'text/html', $cookieOrg)->getBody());
        self::assertGreaterThanOrEqual(1, (int) (new Query($this->pdo()))->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'event_cancelled'",
            ['id' => $aId],
        ));

        // 已取消：不可再报名、不可重复取消、不可编辑
        self::assertSame(422, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA],
            'cookie' => $cookieA,
        ])->getStatusCode());
        self::assertSame(422, $this->send('POST', '/events/' . $eventId . '/cancel', [
            'form' => ['_csrf' => $csrfOrg],
            'cookie' => $cookieOrg,
        ])->getStatusCode());
        self::assertSame(422, $this->get('/events/' . $eventId . '/edit', 'text/html', $cookieOrg)->getStatusCode());

        // 删除后详情 404
        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/delete', [
            'form' => ['_csrf' => $csrfOrg],
            'cookie' => $cookieOrg,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/events/' . $eventId, 'text/html', $cookieOrg)->getStatusCode());
    }

    public function testMinePageAdminManagementAndVisibility(): void
    {
        [$cookieAdmin, $csrfAdmin, $adminName] = $this->registerUser('ev5_adm');
        [$cookieOrg, $csrfOrg] = $this->registerUser('ev5_org');
        [$cookieA, $csrfA] = $this->registerUser('ev5_a');
        [$cookieC] = $this->registerUser('ev5_c');
        $this->promoteToAdmin($this->userId($adminName));

        $title = '夜跑活动 ' . bin2hex(random_bytes(2));
        $eventId = $this->createEvent($cookieOrg, $csrfOrg, 'ev5', ['title' => $title]);

        self::assertSame(302, $this->send('POST', '/events/' . $eventId . '/signup', [
            'form' => ['_csrf' => $csrfA],
            'cookie' => $cookieA,
        ])->getStatusCode());

        // 我的活动（未登录 → 跳登录，带回跳）
        $guestMine = $this->get('/events/mine', 'text/html', null);
        self::assertSame(302, $guestMine->getStatusCode());
        self::assertStringContainsString('/login?redirect=', $guestMine->getHeaderLine('Location'));
        self::assertStringContainsString($title, (string) $this->get('/events/mine?tab=organized', 'text/html', $cookieOrg)->getBody());
        self::assertStringContainsString($title, (string) $this->get('/events/mine?tab=joined', 'text/html', $cookieA)->getBody());
        self::assertStringNotContainsString($title, (string) $this->get('/events/mine?tab=joined', 'text/html', $cookieC)->getBody());

        // 后台权限
        self::assertSame(403, $this->get('/admin/events', 'text/html', $cookieA)->getStatusCode());

        $adminList = $this->get('/admin/events', 'text/html', $cookieAdmin);
        self::assertSame(200, $adminList->getStatusCode());
        self::assertStringContainsString($title, (string) $adminList->getBody());

        // 隐藏 → 访客 404、组织者可见（带标记）；恢复 → 访客可见
        self::assertSame(302, $this->send('POST', '/admin/events/' . $eventId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'hide'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/events/' . $eventId, 'text/html', $cookieC)->getStatusCode());
        $orgView = (string) $this->get('/events/' . $eventId, 'text/html', $cookieOrg)->getBody();
        self::assertStringContainsString('已被隐藏', $orgView);

        self::assertSame(302, $this->send('POST', '/admin/events/' . $eventId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'restore'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(200, $this->get('/events/' . $eventId, 'text/html', $cookieC)->getStatusCode());

        // 管理员删除 → 详情 404、列表不可见、我的活动不再展示
        self::assertSame(302, $this->send('POST', '/admin/events/' . $eventId . '/delete', [
            'form' => ['_csrf' => $csrfAdmin],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/events/' . $eventId, 'text/html', $cookieAdmin)->getStatusCode());
        self::assertStringNotContainsString($title, (string) $this->get('/events?tab=all', 'text/html', $cookieC)->getBody());
        self::assertStringNotContainsString($title, (string) $this->get('/events/mine?tab=organized', 'text/html', $cookieOrg)->getBody());
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

    private function usernameOf(string $prefix): string
    {
        foreach (self::$createdUsernames as $username) {
            if (str_starts_with($username, $prefix . '_')) {
                return $username;
            }
        }

        self::fail('未找到注册用户：' . $prefix);
    }

    private function signupCount(int $eventId): int
    {
        return (int) (new Query($this->pdo()))->value(
            'SELECT signup_count FROM events WHERE id = :id',
            ['id' => $eventId],
        );
    }

    private function signupStatus(int $eventId, int $userId): string
    {
        return (string) (new Query($this->pdo()))->value(
            'SELECT status FROM event_signups WHERE event_id = :e AND user_id = :u',
            ['e' => $eventId, 'u' => $userId],
        );
    }

    private function setEventTime(int $eventId, string $column, string $value): void
    {
        self::assertContains($column, ['start_at', 'end_at', 'signup_deadline']);

        (new Query($this->pdo()))->update('events', [$column => $value], 'id = :id', ['id' => $eventId]);
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

    /**
     * 建一个两天后开始的活动。
     *
     * @param array<string, string> $extra
     */
    private function createEvent(string $cookie, string $csrf, string $prefix, array $extra = []): int
    {
        $form = array_merge([
            '_csrf' => $csrf,
            'title' => '测试活动 ' . $prefix . ' ' . bin2hex(random_bytes(2)),
            'description' => '测试活动说明',
            'location' => '测试地点',
            'start_at' => date('Y-m-d\TH:i', time() + 172800),
            'end_at' => '',
            'signup_deadline' => '',
            'capacity' => '0',
        ], $extra);

        $response = $this->send('POST', '/events', ['form' => $form, 'cookie' => $cookie]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        return (int) basename($this->location($response));
    }

    /** @return array{stream: UploadedFile} 其实返回构造函数参数数组 */
    private function pngFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mofu_ev_cover_') . '.png';
        $image = imagecreatetruecolor(64, 36);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 90, 200));
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
     * @param array<string, string>          $fields
     * @param array<string, UploadedFile>    $files
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

    private function location(ResponseInterface $response): string
    {
        return $response->getHeaderLine('Location');
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
