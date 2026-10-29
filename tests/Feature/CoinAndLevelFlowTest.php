<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 成长体系集成测试：M币投币、经验满级封顶（28800）、闪电 Lv.6 进阶答题门槛与站内头像端点。
 *
 * 重点守护 CoinService 的「同一命名占位符不可复用」约束（PDO 原生预处理下会报 HY093），
 * 该问题此前已出现过一次，故以集成测试固定下来。
 */
final class CoinAndLevelFlowTest extends HttpTestCase
{
    public function testCoinFlowGivesAndEnforcesPerPostLimit(): void
    {
        [$cookieA, $csrfA, $nameA] = $this->registerUser('coin_a');
        [$cookieB, $csrfB, $nameB] = $this->registerUser('coin_b');

        $idA = $this->userId($nameA);
        $idB = $this->userId($nameB);
        $now = date('Y-m-d H:i:s');

        $postId = $this->query()->insert('posts', [
            'user_id' => $idA,
            'type' => 'text',
            'title' => '',
            'content' => '投币流程测试动态',
            'visibility' => 'public',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 投币人先有余额，否则会先被「余额不足」拦下，测不到打赏本身
        $this->query()->insert('user_wallets', [
            'user_id' => $idB,
            'balance' => 10,
            'total_earned' => 10,
            'total_spent' => 0,
            'updated_at' => $now,
        ]);

        $summary = $this->get("/api/v1/posts/{$postId}/coins", 'application/json', $cookieB);
        self::assertSame(200, $summary->getStatusCode(), (string) $summary->getBody());

        $first = $this->postJson("/api/v1/posts/{$postId}/coins", ['amount' => 1], $cookieB, $csrfB);
        self::assertSame(200, $first->getStatusCode(), (string) $first->getBody());
        $data = json_decode((string) $first->getBody(), true);
        self::assertSame(1, $data['data']['my_coins']);
        self::assertSame(1, $data['data']['coin_count']);

        // 一次投 2 币会超过「每人每篇最多 2 币」的上限
        $over = $this->postJson("/api/v1/posts/{$postId}/coins", ['amount' => 2], $cookieB, $csrfB);
        self::assertSame(422, $over->getStatusCode(), (string) $over->getBody());

        $second = $this->postJson("/api/v1/posts/{$postId}/coins", ['amount' => 1], $cookieB, $csrfB);
        self::assertSame(200, $second->getStatusCode(), (string) $second->getBody());
        $data2 = json_decode((string) $second->getBody(), true);
        self::assertSame(2, $data2['data']['my_coins']);
        self::assertSame(2, $data2['data']['coin_count']);

        // 作者不能给自己投币
        $self = $this->postJson("/api/v1/posts/{$postId}/coins", ['amount' => 1], $cookieA, $csrfA);
        self::assertSame(422, $self->getStatusCode(), (string) $self->getBody());

        // 扣款与到账一致：投出 2 币
        $balance = (int) $this->query()->value(
            'SELECT balance FROM user_wallets WHERE user_id = :id',
            ['id' => $idB],
        );
        self::assertSame(8, $balance);
    }

    public function testXpIsCappedAtMaxLevel(): void
    {
        [$cookie, $csrf, $name] = $this->registerUser('cap_xp');
        $userId = $this->userId($name);
        $now = date('Y-m-d H:i:s');

        $this->query()->run('UPDATE users SET is_formal = 1 WHERE id = :id', ['id' => $userId]);
        $this->query()->run(
            'INSERT INTO user_levels (user_id, xp, level, updated_at) VALUES (:id, 28799, 6, :now)
             ON DUPLICATE KEY UPDATE xp = 28799, level = 6, updated_at = VALUES(updated_at)',
            ['id' => $userId, 'now' => $now],
        );

        // 分享任务 +5：应封顶在 28800，而不是涨到 28804
        $share = $this->postJson('/api/v1/membership/tasks/share', ['units' => 1], $cookie, $csrf);
        self::assertSame(200, $share->getStatusCode(), (string) $share->getBody());

        $xp = (int) $this->query()->value('SELECT xp FROM user_levels WHERE user_id = :id', ['id' => $userId]);
        self::assertSame(28800, $xp, '满级后经验必须封顶在 Lv.6 门槛');
    }

    public function testFlashQuizRequiresMaxLevel(): void
    {
        [$cookie, $csrf, $name] = $this->registerUser('flash_gate');
        $userId = $this->userId($name);

        $this->query()->run(
            'INSERT INTO user_levels (user_id, xp, level, updated_at) VALUES (:id, 5000, 5, :now)
             ON DUPLICATE KEY UPDATE xp = 5000, level = 5, updated_at = VALUES(updated_at)',
            ['id' => $userId, 'now' => date('Y-m-d H:i:s')],
        );

        $denied = $this->postJson('/api/v1/membership/quiz/submit', ['tier' => 2, 'answers' => ['1' => 0]], $cookie, $csrf);
        self::assertSame(422, $denied->getStatusCode(), (string) $denied->getBody());
        self::assertStringContainsString('满级', (string) $denied->getBody());

        $this->query()->run(
            'UPDATE user_levels SET xp = 28800, level = 6 WHERE user_id = :id',
            ['id' => $userId],
        );

        $paper = $this->get('/api/v1/membership/quiz?tier=2', 'application/json', $cookie);
        self::assertSame(200, $paper->getStatusCode(), (string) $paper->getBody());
        $data = json_decode((string) $paper->getBody(), true);
        self::assertNotEmpty($data['data']['questions'], '进阶题库应已随迁移导入');
        self::assertSame(80, $data['data']['pass_score']);
    }

    public function testQuizBankIsBiyangThemedAndDrawMixesCategories(): void
    {
        [$cookie, , $name] = $this->registerUser('quiz_bank');
        $this->query()->run('UPDATE users SET is_formal = 1 WHERE id = :id', ['id' => $this->userId($name)]);

        // 触发题库导入
        $paper = $this->get('/api/v1/membership/quiz?tier=1', 'application/json', $cookie);
        self::assertSame(200, $paper->getStatusCode(), (string) $paper->getBody());
        $questions = json_decode((string) $paper->getBody(), true)['data']['questions'];
        self::assertCount(100, $questions, '转正卷应抽满 100 题');

        $bank = $this->query()->all('SELECT tier, COUNT(*) AS total, COUNT(DISTINCT category) AS cats FROM quiz_questions GROUP BY tier');
        $byTier = [];

        foreach ($bank as $row) {
            $byTier[(int) $row['tier']] = ['total' => (int) $row['total'], 'cats' => (int) $row['cats']];
        }

        self::assertGreaterThanOrEqual(100, $byTier[1]['total'] ?? 0, '转正题库至少 100 题');
        self::assertGreaterThanOrEqual(100, $byTier[2]['total'] ?? 0, '进阶题库至少 100 题');
        self::assertGreaterThanOrEqual(6, $byTier[1]['cats'] ?? 0, '转正题库应有多个分类');

        // 抽出的试卷应错开同类题：相邻题目不重复分类，同类不连出
        $ids = array_map(static fn (array $q): int => (int) $q['id'], $questions);
        $rows = $this->query()->all(
            'SELECT id, category FROM quiz_questions WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')',
        );
        $categoryOf = [];

        foreach ($rows as $row) {
            $categoryOf[(int) $row['id']] = (string) $row['category'];
        }

        $sequence = array_values(array_filter(array_map(static fn (int $id): string => $categoryOf[$id] ?? '', $ids)));
        self::assertGreaterThanOrEqual(6, count(array_unique($sequence)), '一张卷子应覆盖多个分类');

        $run = 1;
        $maxRun = 1;

        for ($i = 1; $i < count($sequence); $i++) {
            $run = $sequence[$i] === $sequence[$i - 1] ? $run + 1 : 1;
            $maxRun = max($maxRun, $run);
        }

        self::assertLessThanOrEqual(2, $maxRun, '同一分类的题目不应连续扎堆');
    }

    public function testGrowthPageRendersFlashSectionAndMeOffersGrowthEntry(): void
    {
        [$cookie, , $name] = $this->registerUser('page_p');
        $userId = $this->userId($name);
        $now = date('Y-m-d H:i:s');

        $this->query()->run('UPDATE users SET is_formal = 1 WHERE id = :id', ['id' => $userId]);
        $this->query()->run(
            'INSERT INTO user_levels (user_id, xp, level, updated_at) VALUES (:id, 28800, 6, :now)
             ON DUPLICATE KEY UPDATE xp = 28800, level = 6, updated_at = VALUES(updated_at)',
            ['id' => $userId, 'now' => $now],
        );

        $growth = $this->get('/growth', 'text/html', $cookie);
        self::assertSame(200, $growth->getStatusCode(), (string) $growth->getBody());
        $body = (string) $growth->getBody();

        // 满级未点亮闪电：展示进阶答题入口与共享答题区
        self::assertStringContainsString('进阶答题', $body);
        self::assertStringContainsString('开始进阶答题', $body);
        self::assertStringContainsString('data-quiz-stage', $body);
        // 经验已到顶：不再提示继续涨
        self::assertStringContainsString('28800 XP', $body);

        // 称号：持有但从未主动佩戴时，也要在帖子与主页显示（否则绝大多数人一个称号都不显示）
        $titleId = (int) $this->query()->value("SELECT id FROM titles WHERE slug = 'newcomer'");
        self::assertGreaterThan(0, $titleId, '应先有内置称号种子');
        $this->query()->run(
            'INSERT IGNORE INTO user_titles (user_id, title_id, source, acquired_at) VALUES (:id, :title, :source, :now)',
            ['id' => $userId, 'title' => $titleId, 'source' => 'test', 'now' => $now],
        );
        $this->query()->run('UPDATE users SET equipped_title_id = NULL WHERE id = :id', ['id' => $userId]);

        // 大会员标识
        $this->query()->run("UPDATE users SET membership = 'premium' WHERE id = :id", ['id' => $userId]);

        $postId = $this->query()->insert('posts', [
            'user_id' => $userId,
            'type' => 'text',
            'title' => '',
            'content' => '称号与大会员展示测试',
            'visibility' => 'public',
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $home = $this->get('/', 'text/html', $cookie);
        $homeBody = (string) $home->getBody();
        self::assertStringContainsString('大会员', $homeBody);

        $detail = $this->get('/posts/' . $postId, 'text/html', $cookie);
        self::assertStringContainsString('大会员', (string) $detail->getBody());

        $profile = $this->get('/u/' . $name, 'text/html', $cookie);
        $profileBody = (string) $profile->getBody();
        self::assertStringContainsString('大会员', $profileBody);
        self::assertStringContainsString('level-chip', $profileBody);

        $me = $this->get('/me', 'text/html', $cookie);
        self::assertSame(200, $me->getStatusCode(), (string) $me->getBody());
        self::assertStringContainsString('成长中心', (string) $me->getBody());

        // 个人主页：MID 与统计行渲染，且不再出现已删除的用户菜单
        $profile = $this->get('/u/' . $name, 'text/html', $cookie);
        self::assertSame(200, $profile->getStatusCode(), (string) $profile->getBody());
        $profileBody = (string) $profile->getBody();
        self::assertStringContainsString('profile-hero__mid', $profileBody);
        self::assertStringContainsString('profile-hero__stats', $profileBody);
        self::assertStringNotContainsString('data-user-menu', $profileBody);
    }

    public function testDailyLoginRewardAndCoinAuthorShare(): void
    {
        // 每日登录：需 Lv.1 且已绑定邮箱，每天 1 M币（同一用户同一天只发一次）
        [, , $name] = $this->registerUser('rule_u');
        $userId = $this->userId($name);

        \Mofu\Tests\Support\MemberFixture::promoteByUsername($this->query(), $name, 1);

        $this->loginAs($name, 'Passw0rd!123');
        $this->loginAs($name, 'Passw0rd!123');

        $balance = (float) $this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $userId]);
        self::assertSame(1.0, $balance, '每日登录只发一次 1 M币');

        // Lv.0：未转正不发登录币
        [, , $freshName] = $this->registerUser('rule_l0');
        $freshId = $this->userId($freshName);
        $this->loginAs($freshName, 'Passw0rd!123');
        $freshBalance = (float) ($this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $freshId]) ?? 0);
        self::assertSame(0.0, $freshBalance, 'Lv.0 不发登录币');

        // 大会员：登录币同为 1 枚（额外福利在经验加速包）
        [, , $premiumName] = $this->registerUser('rule_p');
        $premiumId = $this->userId($premiumName);
        \Mofu\Tests\Support\MemberFixture::promoteByUsername($this->query(), $premiumName, 1);
        $this->query()->run("UPDATE users SET membership = 'premium' WHERE id = :id", ['id' => $premiumId]);
        $this->loginAs($premiumName, 'Passw0rd!123');

        $premiumBalance = (float) $this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $premiumId]);
        self::assertSame(1.0, $premiumBalance, '大会员登录币同为 1 枚');

        // 收到投币：每 1 币给作者 0.1 M币
        $now = date('Y-m-d H:i:s');
        $postId = $this->query()->insert('posts', [
            'user_id' => $userId, 'type' => 'text', 'title' => '', 'content' => '投币回报测试',
            'visibility' => 'public', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // 投币人先备好余额（钱包行在登录奖励时已经建好了，直接改余额）
        $this->query()->run(
            'UPDATE user_wallets SET balance = 10, total_earned = 10 WHERE user_id = :id',
            ['id' => $premiumId],
        );

        $premiumCookie = $this->loginAs($premiumName, 'Passw0rd!123');
        $csrf = $this->csrfToken((string) $this->get('/', 'text/html', $premiumCookie)->getBody()) ?? '';

        for ($i = 1; $i <= 2; $i++) {
            $give = $this->postJson("/api/v1/posts/{$postId}/coins", ['amount' => 1], $premiumCookie, $csrf);
            self::assertSame(200, $give->getStatusCode(), (string) $give->getBody());
        }

        $authorBalance = (float) $this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $userId]);
        // 1（登录奖励）+ 2 × 0.1（两枚投币的回报）
        self::assertEqualsWithDelta(1.2, $authorBalance, 0.001, '每投 1 币作者得 0.1 M币');
    }

    /** 走一次真实登录（每日登录奖励在登录时发放） */
    private function loginAs(string $login, string $password): string
    {
        $page = $this->get('/login');
        $cookie = $this->sessionCookie($page) ?? '';
        $csrf = $this->csrfToken((string) $page->getBody()) ?? '';

        $response = $this->send('POST', '/login', [
            'form' => ['login' => $login, 'password' => $password, '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());

        return $this->sessionCookie($response) ?? $cookie;
    }

    public function testPaidPostLockedUntilPurchased(): void
    {
        [, , $authorName] = $this->registerUser('paid_a');
        [$buyerCookie, $buyerCsrf, $buyerName] = $this->registerUser('paid_b');
        $authorId = $this->userId($authorName);
        $buyerId = $this->userId($buyerName);
        $now = date('Y-m-d H:i:s');

        $postId = $this->query()->insert('posts', [
            'user_id' => $authorId, 'type' => 'text', 'title' => '',
            // 正文要超过摘要上限（140 字），末尾标记才不会出现在摘要里
            'content' => str_repeat('这是一段很长的付费正文。', 20) . '【完整正文标记】',
            'visibility' => 'public', 'is_paid' => 1, 'price' => 5,
            'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->query()->run(
            'INSERT INTO user_wallets (user_id, balance, total_earned, total_spent, updated_at)
             VALUES (:id, 20, 20, 0, :now) ON DUPLICATE KEY UPDATE balance = 20',
            ['id' => $buyerId, 'now' => $now],
        );

        // 未购买：详情页只给摘要与解锁入口，正文不外流
        $locked = $this->get('/posts/' . $postId, 'text/html', $buyerCookie);
        self::assertSame(200, $locked->getStatusCode());
        self::assertStringNotContainsString('【完整正文标记】', (string) $locked->getBody());
        self::assertStringContainsString('解锁', (string) $locked->getBody());

        // 购买
        $buy = $this->postJson("/api/v1/posts/{$postId}/purchase", [], $buyerCookie, $buyerCsrf);
        self::assertSame(200, $buy->getStatusCode(), (string) $buy->getBody());

        self::assertSame(1, (int) $this->query()->value(
            'SELECT COUNT(*) FROM post_purchases WHERE post_id = :post_id AND user_id = :user_id',
            ['post_id' => $postId, 'user_id' => $buyerId],
        ), '购买记录应已写入');

        $unlocked = $this->get('/posts/' . $postId, 'text/html', $buyerCookie);
        self::assertSame(200, $unlocked->getStatusCode(), '解锁后仍被重定向（会话失效？）');
        self::assertStringContainsString('【完整正文标记】', (string) $unlocked->getBody());

        // 买家 20-5，作者全额 +5
        self::assertEqualsWithDelta(15.0, (float) $this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $buyerId]), 0.001);
        self::assertEqualsWithDelta(5.0, (float) $this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $authorId]), 0.001);

        // 只付一次：重复购买被拒
        $again = $this->postJson("/api/v1/posts/{$postId}/purchase", [], $buyerCookie, $buyerCsrf);
        self::assertSame(422, $again->getStatusCode(), (string) $again->getBody());
    }

    public function testAvatarEndpointServesSiteAvatarOrLetterFallback(): void
    {
        // 刚注册的账号没有上传头像：回退为首字头像 SVG
        [, , $name] = $this->registerUser('avatar_u');
        $email = $name . '@example.com';

        // Twikoo 2.x 用 sha256（64 位），历史数据里可能有 md5（32 位），两种都要能查
        foreach ([hash('sha256', $email), md5($email)] as $hash) {
            $response = $this->send('GET', '/avatar/' . $hash);

            self::assertSame(200, $response->getStatusCode(), $hash);
            self::assertStringContainsString('image/svg+xml', $response->getHeaderLine('Content-Type'));
        }

        // 非哈希形式不匹配路由
        self::assertSame(404, $this->send('GET', '/avatar/not-a-hash')->getStatusCode());
    }
}
