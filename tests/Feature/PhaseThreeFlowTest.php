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
 * 阶段三 A 包集成测试：兴趣圈子（公开/审批、隔离）与统一板块框架（问答/二手/后台）。
 * 使用随机用户，结束后级联清理。
 */
final class PhaseThreeFlowTest extends TestCase
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

    public function testCommunityCreateJoinPublishAndIsolation(): void
    {
        [$cookieOwner, $csrfOwner, $ownerName] = $this->registerUser('c_owner');
        [$cookieMember, $csrfMember, $memberName] = $this->registerUser('c_member');
        [$cookieGuest] = $this->registerUser('c_guest');

        $circleName = '摄影同好会' . bin2hex(random_bytes(2));
        $create = $this->send('POST', '/circles', [
            'form' => [
                '_csrf' => $csrfOwner,
                'name' => $circleName,
                'description' => '一起拍照、扫街',
                'visibility' => 'public',
            ],
            'cookie' => $cookieOwner,
        ]);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());

        $slugPath = basename($this->location($create));
        $slug = rawurldecode($slugPath);
        self::assertNotSame('', $slug);

        // 圈主自动成为成员，可见发布入口
        $ownerView = $this->get('/circles/' . $slugPath, 'text/html', $cookieOwner);
        self::assertSame(200, $ownerView->getStatusCode());
        self::assertStringContainsString($circleName, (string) $ownerView->getBody());
        self::assertStringContainsString('发布到「', (string) $ownerView->getBody());

        // 非成员看不到圈子动态
        $guestView = $this->get('/circles/' . $slugPath, 'text/html', $cookieGuest);
        self::assertStringContainsString('圈子动态仅成员可见', (string) $guestView->getBody());

        // 加入圈子（公开直接生效）
        $join = $this->send('POST', '/circles/' . $slugPath . '/join', [
            'form' => ['_csrf' => $csrfMember],
            'cookie' => $cookieMember,
        ]);
        self::assertSame(302, $join->getStatusCode(), (string) $join->getBody());

        $memberView = $this->get('/circles/' . $slugPath, 'text/html', $cookieMember);
        self::assertStringContainsString('发布到「', (string) $memberView->getBody());

        // 成员通过 API 在圈内发帖
        $circleId = $this->circleId($slug);
        $token = 'circle-post-' . bin2hex(random_bytes(4));
        $post = $this->postJson('/api/v1/posts', [
            'content' => '圈子里的第一条动态 ' . $token,
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
            'community_id' => $circleId,
        ], $cookieMember, $csrfMember);
        self::assertSame(201, $post->getStatusCode(), (string) $post->getBody());
        $postId = (int) (json_decode((string) $post->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        // 成员可见帖子详情，且圈子页显示该动态
        $memberPost = $this->get('/posts/' . $postId, 'text/html', $cookieMember);
        self::assertSame(200, $memberPost->getStatusCode());
        self::assertStringContainsString($token, (string) $memberPost->getBody());
        self::assertStringContainsString('来自圈子', (string) $memberPost->getBody());

        $circleFeed = $this->get('/circles/' . $slugPath, 'text/html', $cookieMember);
        self::assertStringContainsString($token, (string) $circleFeed->getBody());

        // 非成员在详情页 / 首页流 / 搜索 / 他人主页均不可见（内容隔离）
        self::assertSame(404, $this->get('/posts/' . $postId, 'text/html', $cookieGuest)->getStatusCode());
        self::assertStringNotContainsString($token, (string) $this->get('/', 'text/html', $cookieGuest)->getBody());
        self::assertStringNotContainsString($token, (string) $this->get('/search?q=' . urlencode('圈子里的第一条动态'), 'text/html', $cookieGuest)->getBody());
        self::assertStringNotContainsString($token, (string) $this->get('/u/' . $memberName, 'text/html', $cookieGuest)->getBody());
        self::assertStringContainsString($token, (string) $this->get('/u/' . $memberName, 'text/html', $cookieMember)->getBody(), '本人主页应包含自己的圈子动态');
        self::assertStringNotContainsString($token, (string) $this->get('/?tab=latest', 'text/html', $cookieMember)->getBody());

        // 非成员不能加入后直接发帖？——非成员发帖应被拒（403）
        $forbidden = $this->postJson('/api/v1/posts', [
            'content' => '越权发帖',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
            'community_id' => $circleId,
        ], $cookieGuest, $this->csrfToken((string) $this->get('/', 'text/html', $cookieGuest)->getBody()));
        self::assertSame(403, $forbidden->getStatusCode());
    }

    public function testApprovalCommunityFlow(): void
    {
        [$cookieOwner, $csrfOwner, $ownerName] = $this->registerUser('a_owner');
        [$cookieJoiner, $csrfJoiner, $joinerName] = $this->registerUser('a_join');

        $circleName = '审核制圈子' . bin2hex(random_bytes(2));
        $create = $this->send('POST', '/circles', [
            'form' => [
                '_csrf' => $csrfOwner,
                'name' => $circleName,
                'description' => '需要审批',
                'visibility' => 'approval',
            ],
            'cookie' => $cookieOwner,
        ]);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $slug = basename($this->location($create));

        // 申请加入 → 进入待审批，仍不可见动态
        $join = $this->send('POST', '/circles/' . $slug . '/join', [
            'form' => ['_csrf' => $csrfJoiner],
            'cookie' => $cookieJoiner,
        ]);
        self::assertSame(302, $join->getStatusCode(), (string) $join->getBody());

        $pendingView = $this->get('/circles/' . $slug, 'text/html', $cookieJoiner);
        self::assertStringContainsString('申请审批中', (string) $pendingView->getBody());
        self::assertStringContainsString('圈子动态仅成员可见', (string) $pendingView->getBody());

        // 圈主在成员/审批页看到申请并批准
        $membersView = $this->get('/circles/' . $slug . '?tab=members', 'text/html', $cookieOwner);
        self::assertSame(200, $membersView->getStatusCode());
        self::assertStringContainsString('待审批', (string) $membersView->getBody());
        self::assertStringContainsString('测试a_join', (string) $membersView->getBody());

        $joinerId = $this->userId($joinerName);
        $approve = $this->send('POST', '/circles/' . $slug . '/members/' . $joinerId . '/approve', [
            'form' => ['_csrf' => $csrfOwner],
            'cookie' => $cookieOwner,
        ]);
        self::assertSame(302, $approve->getStatusCode(), (string) $approve->getBody());

        // 批准后可查看并发布
        $joinedView = $this->get('/circles/' . $slug, 'text/html', $cookieJoiner);
        self::assertStringContainsString('发布到「', (string) $joinedView->getBody());
        self::assertStringNotContainsString('申请审批中', (string) $joinedView->getBody());

        // 圈主不能退出自己的圈子
        $leave = $this->send('POST', '/circles/' . $slug . '/leave', [
            'form' => ['_csrf' => $csrfOwner],
            'cookie' => $cookieOwner,
        ]);
        self::assertSame(422, $leave->getStatusCode());

        // 成员可以退出
        self::assertSame(302, $this->send('POST', '/circles/' . $slug . '/leave', [
            'form' => ['_csrf' => $csrfJoiner],
            'cookie' => $cookieJoiner,
        ])->getStatusCode());
    }

    public function testBoardQaFlowWithCommentLikeAndState(): void
    {
        [$cookieAuthor, $csrfAuthor] = $this->registerUser('b_author');
        [$cookieOther, $csrfOther] = $this->registerUser('b_other');

        $title = '求助：微积分复习资料 ' . bin2hex(random_bytes(2));
        $create = $this->send('POST', '/boards/qa', [
            'form' => [
                '_csrf' => $csrfAuthor,
                'title' => $title,
                'content' => '期末将近，求推荐的复习资料与习题集。',
            ],
            'cookie' => $cookieAuthor,
        ]);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $entryId = (int) basename($this->location($create));
        self::assertGreaterThan(0, $entryId);

        $list = $this->get('/boards/qa', 'text/html', $cookieOther);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString($title, (string) $list->getBody());

        $detail = $this->get('/boards/qa/' . $entryId, 'text/html', $cookieOther);
        self::assertSame(200, $detail->getStatusCode());
        self::assertStringContainsString('待解决', (string) $detail->getBody());

        // 他人回复 → 作者收到 entry_commented 通知
        $commentText = '推荐《高等数学辅导讲义》';
        $comment = $this->send('POST', '/boards/qa/' . $entryId . '/comments', [
            'form' => ['_csrf' => $csrfOther, 'content' => $commentText],
            'cookie' => $cookieOther,
        ]);
        self::assertSame(302, $comment->getStatusCode(), (string) $comment->getBody());

        // 板块回复已改由 Twikoo 承载，服务端页面不再内联回复正文；由下方通知断言确认回复成功

        $authorId = $this->userId($this->usernameOf('b_author'));
        $query = new Query($this->pdo());
        self::assertGreaterThanOrEqual(1, (int) $query->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'entry_commented'",
            ['id' => $authorId],
        ));

        // 点赞 → 作者收到 entry_liked 通知，计数 +1
        $like = $this->postJson('/api/v1/reactions/toggle', [
            'target_type' => 'board_entry',
            'target_id' => $entryId,
            'kind' => 'like',
        ], $cookieOther, $csrfOther);
        self::assertSame(200, $like->getStatusCode(), (string) $like->getBody());

        self::assertGreaterThanOrEqual(1, (int) $query->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'entry_liked'",
            ['id' => $authorId],
        ));
        self::assertStringContainsString('data-like-count>1', (string) $this->get('/boards/qa/' . $entryId, 'text/html', $cookieOther)->getBody());

        // 非作者不能切换状态
        self::assertSame(403, $this->send('POST', '/boards/qa/' . $entryId . '/state', [
            'form' => ['_csrf' => $csrfOther],
            'cookie' => $cookieOther,
        ])->getStatusCode());

        // 作者标记已解决
        $solve = $this->send('POST', '/boards/qa/' . $entryId . '/state', [
            'form' => ['_csrf' => $csrfAuthor],
            'cookie' => $cookieAuthor,
        ]);
        self::assertSame(302, $solve->getStatusCode(), (string) $solve->getBody());
        self::assertStringContainsString('已解决', (string) $this->get('/boards/qa/' . $entryId, 'text/html', $cookieAuthor)->getBody());

        // 筛选：已解决可见、待解决不可见
        self::assertStringContainsString($title, (string) $this->get('/boards/qa?filter=solved', 'text/html', $cookieAuthor)->getBody());
        self::assertStringNotContainsString($title, (string) $this->get('/boards/qa?filter=pending', 'text/html', $cookieAuthor)->getBody());

        // 非作者不能删除；作者删除后列表与详情均不可见
        self::assertSame(403, $this->send('POST', '/boards/qa/' . $entryId . '/delete', [
            'form' => ['_csrf' => $csrfOther],
            'cookie' => $cookieOther,
        ])->getStatusCode());
        self::assertSame(302, $this->send('POST', '/boards/qa/' . $entryId . '/delete', [
            'form' => ['_csrf' => $csrfAuthor],
            'cookie' => $cookieAuthor,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/boards/qa/' . $entryId, 'text/html', $cookieAuthor)->getStatusCode());
        self::assertStringNotContainsString($title, (string) $this->get('/boards/qa', 'text/html', $cookieAuthor)->getBody());
    }

    public function testMarketValidationAndFilters(): void
    {
        [$cookieSeller, $csrfSeller] = $this->registerUser('m_seller');

        // 缺价格 → 422
        $missingPrice = $this->send('POST', '/boards/market', [
            'form' => ['_csrf' => $csrfSeller, 'title' => '出二手自行车', 'content' => '九成新', 'condition' => 'like_new'],
            'cookie' => $cookieSeller,
        ]);
        self::assertSame(422, $missingPrice->getStatusCode());
        self::assertStringContainsString('价格', (string) $missingPrice->getBody());

        // 缺成色 → 422
        $missingCondition = $this->send('POST', '/boards/market', [
            'form' => ['_csrf' => $csrfSeller, 'title' => '出二手自行车', 'content' => '九成新', 'price' => '99.5'],
            'cookie' => $cookieSeller,
        ]);
        self::assertSame(422, $missingCondition->getStatusCode());
        self::assertStringContainsString('成色', (string) $missingCondition->getBody());

        // 正常发布
        $title = '出二手自行车 ' . bin2hex(random_bytes(2));
        $create = $this->send('POST', '/boards/market', [
            'form' => [
                '_csrf' => $csrfSeller,
                'title' => $title,
                'content' => '九成新，骑行半年，可小刀。',
                'price' => '99.5',
                'condition' => 'like_new',
            ],
            'cookie' => $cookieSeller,
        ]);
        self::assertSame(302, $create->getStatusCode(), (string) $create->getBody());
        $entryId = (int) basename($this->location($create));

        $detail = $this->get('/boards/market/' . $entryId, 'text/html', $cookieSeller);
        self::assertStringContainsString('¥99.5', (string) $detail->getBody());
        self::assertStringContainsString('在售', (string) $detail->getBody());
        self::assertStringContainsString('几乎全新', (string) $detail->getBody());

        // 筛选在售/已售
        self::assertStringContainsString($title, (string) $this->get('/boards/market?filter=selling', 'text/html', $cookieSeller)->getBody());
        self::assertStringNotContainsString($title, (string) $this->get('/boards/market?filter=sold', 'text/html', $cookieSeller)->getBody());

        // 标记已售
        self::assertSame(302, $this->send('POST', '/boards/market/' . $entryId . '/state', [
            'form' => ['_csrf' => $csrfSeller],
            'cookie' => $cookieSeller,
        ])->getStatusCode());
        self::assertStringContainsString($title, (string) $this->get('/boards/market?filter=sold', 'text/html', $cookieSeller)->getBody());
        $detailSold = $this->get('/boards/market/' . $entryId, 'text/html', $cookieSeller);
        self::assertStringContainsString('已售', (string) $detailSold->getBody());
    }

    public function testAdminCircleAndBoardManagement(): void
    {
        [$cookieAdmin, $csrfAdmin, $adminName] = $this->registerUser('adm_three');
        [$cookieUser, $csrfUser, $userName] = $this->registerUser('adm_u');
        [$cookieVisitor] = $this->registerUser('adm_v');
        $this->promoteToAdmin($this->userId($adminName));

        // 普通用户无权访问
        self::assertSame(403, $this->get('/admin/circles', 'text/html', $cookieUser)->getStatusCode());
        self::assertSame(403, $this->get('/admin/boards', 'text/html', $cookieUser)->getStatusCode());

        // 建圈、发帖
        $circleName = '后台管理测试圈' . bin2hex(random_bytes(2));
        $createCircle = $this->send('POST', '/circles', [
            'form' => ['_csrf' => $csrfUser, 'name' => $circleName, 'description' => '', 'visibility' => 'public'],
            'cookie' => $cookieUser,
        ]);
        self::assertSame(302, $createCircle->getStatusCode(), (string) $createCircle->getBody());
        $slugPath = basename($this->location($createCircle));
        $slug = rawurldecode($slugPath);
        $circleId = $this->circleId($slug);

        $entryTitle = '后台管理测试帖 ' . bin2hex(random_bytes(2));
        $createEntry = $this->send('POST', '/boards/forum', [
            'form' => ['_csrf' => $csrfUser, 'title' => $entryTitle, 'content' => '论坛测试内容'],
            'cookie' => $cookieUser,
        ]);
        self::assertSame(302, $createEntry->getStatusCode(), (string) $createEntry->getBody());
        $entryId = (int) basename($this->location($createEntry));

        // 后台可见并停用圈子
        $adminCircles = $this->get('/admin/circles', 'text/html', $cookieAdmin);
        self::assertSame(200, $adminCircles->getStatusCode());
        self::assertStringContainsString($circleName, (string) $adminCircles->getBody());

        self::assertSame(302, $this->send('POST', '/admin/circles/' . $circleId . '/active', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'deactivate'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/circles/' . $slugPath, 'text/html', $cookieAdmin)->getStatusCode());

        // 后台隐藏帖子 → 访客 404；恢复 → 200
        $adminBoards = $this->get('/admin/boards?board=forum', 'text/html', $cookieAdmin);
        self::assertSame(200, $adminBoards->getStatusCode());
        self::assertStringContainsString($entryTitle, (string) $adminBoards->getBody());

        self::assertSame(302, $this->send('POST', '/admin/boards/' . $entryId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'hide'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(404, $this->get('/boards/forum/' . $entryId, 'text/html', $cookieVisitor)->getStatusCode());

        self::assertSame(302, $this->send('POST', '/admin/boards/' . $entryId . '/status', [
            'form' => ['_csrf' => $csrfAdmin, 'action' => 'restore'],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(200, $this->get('/boards/forum/' . $entryId, 'text/html', $cookieVisitor)->getStatusCode());

        // 删除圈子（级联删除其成员关系）
        self::assertSame(302, $this->send('POST', '/admin/circles/' . $circleId . '/delete', [
            'form' => ['_csrf' => $csrfAdmin],
            'cookie' => $cookieAdmin,
        ])->getStatusCode());
        self::assertSame(0, (int) (new Query($this->pdo()))->value(
            'SELECT COUNT(*) FROM communities WHERE id = :id',
            ['id' => $circleId],
        ));
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

    private function circleId(string $slug): int
    {
        return (int) (new Query($this->pdo()))->value('SELECT id FROM communities WHERE slug = :slug', ['slug' => $slug]);
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

    private function get(string $path, string $accept = 'text/html', ?string $cookie = null): ResponseInterface
    {
        return $this->send('GET', $path, ['accept' => $accept, 'cookie' => $cookie]);
    }

    /** @param array<string, mixed> $json */
    private function postJson(string $path, array $json, ?string $cookie = null, ?string $csrf = null): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('POST', $path, ['REMOTE_ADDR' => TestIp::next()] + ($_SERVER ?? []));
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
