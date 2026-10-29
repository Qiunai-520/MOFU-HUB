<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;
use Mofu\Tests\Support\MemberFixture;

/**
 * 风纪委员会：申请门槛（Lv.4）、审批入册、众裁投票、月度津贴。
 */
final class JuryFlowTest extends HttpTestCase
{
    public function testApplyApproveVoteAndStipend(): void
    {
        [$memberCookie, $memberCsrf, $memberName] = $this->registerUser('jury_app');
        $memberId = $this->userId($memberName);

        // Lv.0 不能申请
        $page = $this->get('/jury', 'text/html', $memberCookie);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString(sprintf('Lv.%d', 4), (string) $page->getBody());

        $denied = $this->send('POST', '/jury/apply', [
            'form' => ['reason' => '想参与众裁', '_csrf' => $memberCsrf],
            'cookie' => $memberCookie,
        ]);
        self::assertSame(403, $denied->getStatusCode());

        // 提到 Lv.4 后可申请
        MemberFixture::promoteByUsername($this->query(), $memberName, 4);
        $applied = $this->send('POST', '/jury/apply', [
            'form' => ['reason' => '想参与众裁', '_csrf' => $memberCsrf],
            'cookie' => $memberCookie,
        ]);
        self::assertSame(302, $applied->getStatusCode());

        $applicationId = (int) $this->query()->value(
            "SELECT id FROM jury_applications WHERE user_id = :id AND status = 'pending'",
            ['id' => $memberId],
        );
        self::assertGreaterThan(0, $applicationId);

        // 管理员审批通过
        [$adminCookie, $adminCsrf, $adminName] = $this->registerUser('jury_admin');
        $this->promoteToAdmin($this->userId($adminName));

        $approved = $this->send('POST', "/admin/jury/applications/{$applicationId}/decide", [
            'form' => ['action' => 'approve', 'note' => '', '_csrf' => $adminCsrf],
            'cookie' => $adminCookie,
        ]);
        self::assertSame(302, $approved->getStatusCode(), (string) $approved->getBody());

        self::assertSame(1, (int) $this->query()->value('SELECT COUNT(*) FROM jury_members WHERE user_id = :id', ['id' => $memberId]), '通过后应入册');
        self::assertSame(1, (int) $this->query()->value(
            "SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.slug = 'jury'",
            ['id' => $memberId],
        ), '通过后应获得 jury 角色');

        // 后台名册页要能打开（jury_members 主键是 user_id，没有 id 列）
        $roster = $this->get('/admin/jury', 'text/html', $adminCookie);
        self::assertSame(200, $roster->getStatusCode());
        self::assertStringContainsString('委员名册', (string) $roster->getBody());

        // 管理员开一个案件，委员投票
        $opened = $this->send('POST', '/admin/jury/cases', [
            'form' => ['target_type' => 'post', 'target_id' => '123', 'reason' => '疑似违规', 'snapshot' => '', '_csrf' => $adminCsrf],
            'cookie' => $adminCookie,
        ]);
        self::assertSame(302, $opened->getStatusCode());
        $caseId = (int) $this->query()->value('SELECT id FROM jury_cases ORDER BY id DESC LIMIT 1');
        self::assertGreaterThan(0, $caseId);

        $voted = $this->send('POST', "/jury/cases/{$caseId}/vote", [
            'form' => ['vote' => 'violation', '_csrf' => $memberCsrf],
            'cookie' => $memberCookie,
        ]);
        self::assertSame(302, $voted->getStatusCode());
        self::assertSame(1, (int) $this->query()->value('SELECT COUNT(*) FROM jury_votes WHERE case_id = :id AND user_id = :uid', ['id' => $caseId, 'uid' => $memberId]));

        // 评级 A 后发放本月津贴 30 M币
        $rated = $this->send('POST', "/admin/jury/members/{$memberId}/rate", [
            'form' => ['rating' => 'a', '_csrf' => $adminCsrf],
            'cookie' => $adminCookie,
        ]);
        self::assertSame(302, $rated->getStatusCode());

        $stipend = $this->send('POST', '/admin/jury/stipend', [
            'form' => ['_csrf' => $adminCsrf],
            'cookie' => $adminCookie,
        ]);
        self::assertSame(302, $stipend->getStatusCode());

        $balance = (float) ($this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $memberId]) ?? 0);
        self::assertSame(30.0, $balance, 'A 级委员本月津贴应为 30 M币');

        // 同月重复发放不会重复入账
        $this->send('POST', '/admin/jury/stipend', ['form' => ['_csrf' => $adminCsrf], 'cookie' => $adminCookie]);
        $again = (float) ($this->query()->value('SELECT balance FROM user_wallets WHERE user_id = :id', ['id' => $memberId]) ?? 0);
        self::assertSame(30.0, $again, '同月津贴幂等');
    }
}
