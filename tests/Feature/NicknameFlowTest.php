<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 昵称修改：首次免费，之后每次 6 M币，间隔不少于 3 天。
 */
final class NicknameFlowTest extends HttpTestCase
{
    public function testFirstFreeThenChargedWithInterval(): void
    {
        [$cookie, $csrf, $name] = $this->registerUser('nick_u');
        $userId = $this->userId($name);

        $this->query()->run(
            'INSERT INTO user_wallets (user_id, balance, total_earned, total_spent, updated_at)
             VALUES (:id, 50, 50, 0, :now) ON DUPLICATE KEY UPDATE balance = 50',
            ['id' => $userId, 'now' => date('Y-m-d H:i:s')],
        );

        // 首次修改：免费
        $first = $this->send('POST', '/settings/profile', [
            'form' => ['nickname' => '新昵称甲', 'bio' => '', 'location' => '', 'website' => '', '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $first->getStatusCode(), (string) $first->getBody());
        self::assertSame('新昵称甲', (string) $this->query()->value('SELECT nickname FROM user_profiles WHERE user_id = :id', ['id' => $userId]));
        self::assertSame(50.0, (float) $this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $userId]), '首次免费');

        // 间隔不足 3 天：拒绝
        $tooSoon = $this->send('POST', '/settings/profile', [
            'form' => ['nickname' => '新昵称乙', 'bio' => '', 'location' => '', 'website' => '', '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(422, $tooSoon->getStatusCode());
        self::assertStringContainsString('3 天', (string) $tooSoon->getBody());

        // 把上次修改时间回拨 4 天：这次收费 6 M币
        $this->query()->run(
            'UPDATE user_profiles SET nickname_changed_at = :at WHERE user_id = :id',
            ['at' => date('Y-m-d H:i:s', time() - 4 * 86400), 'id' => $userId],
        );

        $charged = $this->send('POST', '/settings/profile', [
            'form' => ['nickname' => '新昵称乙', 'bio' => '', 'location' => '', 'website' => '', '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $charged->getStatusCode(), (string) $charged->getBody());
        self::assertSame(44.0, (float) $this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $userId]), '第二次收费 6 M币');
        self::assertSame(2, (int) $this->query()->value('SELECT nickname_change_count FROM user_profiles WHERE user_id = :id', ['id' => $userId]));
        self::assertSame(2, (int) $this->query()->value('SELECT COUNT(*) FROM nickname_change_log WHERE user_id = :id', ['id' => $userId]));

        // 余额不足：拒绝
        $this->query()->run('UPDATE user_wallets SET balance = 0 WHERE user_id = :id', ['id' => $userId]);
        $this->query()->run(
            'UPDATE user_profiles SET nickname_changed_at = :at WHERE user_id = :id',
            ['at' => date('Y-m-d H:i:s', time() - 4 * 86400), 'id' => $userId],
        );

        $poor = $this->send('POST', '/settings/profile', [
            'form' => ['nickname' => '第三个昵称', 'bio' => '', 'location' => '', 'website' => '', '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(422, $poor->getStatusCode());
        self::assertStringContainsString('M币不足', (string) $poor->getBody());
    }
}
