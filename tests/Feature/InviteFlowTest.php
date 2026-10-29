<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;
use Mofu\Tests\Support\MemberFixture;

/**
 * 邀请码：Lv.5 每月可买 1 个（50 M币），被邀请人免答题转正。
 */
final class InviteFlowTest extends HttpTestCase
{
    public function testPurchaseRequiresLevelAndCoinsThenRedeemsOnRegister(): void
    {
        [$ownerCookie, $ownerCsrf, $ownerName] = $this->registerUser('inv_owner');
        $ownerId = $this->userId($ownerName);

        // Lv.0：没有额度
        $denied = $this->postJson('/api/v1/invites/purchase', [], $ownerCookie, $ownerCsrf);
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getBody());

        // 提升到 Lv.5，但余额不足
        MemberFixture::promoteByUsername($this->query(), $ownerName, 5);
        $poor = $this->postJson('/api/v1/invites/purchase', [], $ownerCookie, $ownerCsrf);
        self::assertSame(422, $poor->getStatusCode(), (string) $poor->getBody());

        // 补足 50 M币后购买成功
        $this->query()->run(
            'INSERT INTO user_wallets (user_id, balance, total_earned, total_spent, updated_at)
             VALUES (:id, 50, 50, 0, :now) ON DUPLICATE KEY UPDATE balance = 50',
            ['id' => $ownerId, 'now' => date('Y-m-d H:i:s')],
        );

        $bought = $this->postJson('/api/v1/invites/purchase', [], $ownerCookie, $ownerCsrf);
        self::assertSame(201, $bought->getStatusCode(), (string) $bought->getBody());
        $code = (string) (json_decode((string) $bought->getBody(), true)['data']['code'] ?? '');
        self::assertNotSame('', $code);

        // 本月额度 1 个：再买一次被拒
        self::assertSame(403, $this->postJson('/api/v1/invites/purchase', [], $ownerCookie, $ownerCsrf)->getStatusCode());

        // 被邀请人用该码注册：免答题直接成为正式会员
        [, , $invitedName] = $this->registerUser('inv_guest', $code);
        $invitedId = $this->userId($invitedName);

        self::assertSame(1, (int) $this->query()->value('SELECT is_formal FROM users WHERE id = :id', ['id' => $invitedId]), '被邀请人应直接转正');

        $usedBy = $this->query()->value('SELECT used_by FROM invite_codes WHERE code = :code', ['code' => $code]);
        self::assertSame($invitedId, (int) $usedBy, '邀请码应记录使用者');

        // 已被使用的码不能再用于注册
        $page = $this->get('/register');
        $reuse = $this->send('POST', '/register', [
            'form' => [
                'email' => 'reuse_' . bin2hex(random_bytes(3)) . '@example.com',
                'nickname' => '重复',
                'invite' => $code,
                '_csrf' => $this->csrfToken((string) $page->getBody()),
            ],
            'cookie' => $this->sessionCookie($page),
        ]);
        self::assertSame(422, $reuse->getStatusCode());
    }
}
