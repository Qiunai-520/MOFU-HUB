<?php

declare(strict_types=1);

namespace Mofu\Modules\Jury;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Growth\WalletService;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 风纪委员会：Lv.4 且近 90 天无违规可申请；委员参与内容众裁。
 *
 * 津贴：每月按评级发放（A 30 / B 20 / C 10 M币，最多 30），由后台一键发放（幂等到月）。
 * 年度结算：全年参与审核的案件数 ÷ 20 额外发放。
 */
final class JuryService
{
    public const APPLY_MIN_LEVEL = 4;
    public const CLEAN_DAYS = 90;
    public const STIPEND_MAX = 30;
    /** @var array<string, float> 评级 → 月度津贴 */
    public const RATING_STIPEND = ['a' => 30.0, 'b' => 20.0, 'c' => 10.0, 'none' => 0.0];
    public const RATING_LABELS = ['none' => '未评级', 'a' => 'A 级', 'b' => 'B 级', 'c' => 'C 级'];

    public function __construct(
        private readonly Query $query,
        private readonly LevelService $levels,
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return array{ok: bool, reason: string} */
    public function canApply(int $userId): array
    {
        if ($this->isMember($userId)) {
            return ['ok' => false, 'reason' => '你已经是风纪委员'];
        }

        if ((int) ($this->levels->stats($userId)['level'] ?? 0) < self::APPLY_MIN_LEVEL) {
            return ['ok' => false, 'reason' => '达到 Lv.' . self::APPLY_MIN_LEVEL . ' 才能申请'];
        }

        $pending = $this->query->value(
            "SELECT id FROM jury_applications WHERE user_id = :id AND status = 'pending'",
            ['id' => $userId],
        );

        if ($pending !== null) {
            return ['ok' => false, 'reason' => '申请正在等待处理'];
        }

        if (!$this->hasCleanRecord($userId)) {
            return ['ok' => false, 'reason' => '近 ' . self::CLEAN_DAYS . ' 天内有违规记录，暂不能申请'];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /** 近 90 天是否有违规（封禁 / 警告）记录 */
    public function hasCleanRecord(int $userId): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::CLEAN_DAYS * 86400);

        return $this->query->value(
            'SELECT id FROM user_bans WHERE user_id = :id AND starts_at >= :since LIMIT 1',
            ['id' => $userId, 'since' => $since],
        ) === null;
    }

    public function isMember(int $userId): bool
    {
        return $this->query->value('SELECT user_id FROM jury_members WHERE user_id = :id', ['id' => $userId]) !== null;
    }

    /** @return array<string, mixed>|null */
    public function myApplication(int $userId): ?array
    {
        $row = $this->query->one(
            'SELECT * FROM jury_applications WHERE user_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $userId],
        );

        if ($row === null) {
            return null;
        }

        return [
            'status' => (string) $row['status'],
            'reason' => (string) $row['reason'],
            'note' => (string) $row['handle_note'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function apply(int $userId, string $reason): void
    {
        $check = $this->canApply($userId);

        if (!$check['ok']) {
            throw HttpException::forbidden($check['reason']);
        }

        $this->query->run(
            "INSERT INTO jury_applications (user_id, reason, status, created_at)
             VALUES (:id, :reason, 'pending', :now)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = 'pending', handle_note = '', handled_by = NULL, handled_at = NULL, created_at = VALUES(created_at)",
            ['id' => $userId, 'reason' => mb_substr($reason, 0, 500), 'now' => date('Y-m-d H:i:s')],
        );
    }

    /** @return list<array<string, mixed>> */
    public function applications(?string $status = null): array
    {
        $sql = 'SELECT a.*, u.username, p.nickname FROM jury_applications a
                JOIN users u ON u.id = a.user_id
                LEFT JOIN user_profiles p ON p.user_id = a.user_id';
        $params = [];

        if ($status !== null) {
            $sql .= ' WHERE a.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY a.id DESC LIMIT 100';

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'nickname' => trim((string) ($row['nickname'] ?? '')) !== '' ? (string) $row['nickname'] : (string) $row['username'],
            'reason' => (string) $row['reason'],
            'status' => (string) $row['status'],
            'note' => (string) $row['handle_note'],
            'created_at' => (string) $row['created_at'],
        ], $this->query->all($sql, $params));
    }

    /** 审批申请：通过则加入委员名册并授予 jury 角色 */
    public function decide(int $applicationId, bool $approve, string $note, int $actorId): void
    {
        $app = $this->query->one('SELECT * FROM jury_applications WHERE id = :id', ['id' => $applicationId]);

        if ($app === null) {
            throw HttpException::notFound('申请不存在');
        }

        $now = date('Y-m-d H:i:s');
        $status = $approve ? 'approved' : 'rejected';
        $userId = (int) $app['user_id'];

        $this->query->transaction(function (Query $q) use ($applicationId, $status, $note, $actorId, $now, $approve, $userId): void {
            $q->update('jury_applications', [
                'status' => $status,
                'handled_by' => $actorId,
                'handle_note' => mb_substr($note, 0, 255),
                'handled_at' => $now,
            ], 'id = :id', ['id' => $applicationId]);

            if (!$approve) {
                return;
            }

            $q->run(
                'INSERT INTO jury_members (user_id, term_start, rating, created_at) VALUES (:id, :start, \'none\', :now)
                 ON DUPLICATE KEY UPDATE term_start = VALUES(term_start)',
                ['id' => $userId, 'start' => date('Y-m-d'), 'now' => $now],
            );

            $role = $q->one("SELECT id FROM roles WHERE slug = 'jury'", []);

            if ($role !== null) {
                $q->run('INSERT IGNORE INTO user_roles (user_id, role_id, assigned_at) VALUES (:id, :role, :now)', [
                    'id' => $userId,
                    'role' => (int) $role['id'],
                    'now' => $now,
                ]);
            }
        });

        $this->notifications->push($userId, null, 'system', 'jury', $applicationId, [
            'message' => $approve ? '你已加入风纪委员会' : '风纪委员会申请未通过：' . $note,
            'url' => '/jury',
        ]);
    }

    /** 委员会名册 */
    public function members(): array
    {
        $rows = $this->query->all(
            'SELECT m.*, u.username, p.nickname FROM jury_members m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = m.user_id
             ORDER BY m.created_at DESC, m.user_id DESC LIMIT 200',
        );

        return array_map(static fn (array $row): array => [
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'nickname' => trim((string) ($row['nickname'] ?? '')) !== '' ? (string) $row['nickname'] : (string) $row['username'],
            'rating' => (string) $row['rating'],
            'rating_label' => self::RATING_LABELS[(string) $row['rating']] ?? (string) $row['rating'],
            'term_start' => (string) $row['term_start'],
            'term_end' => $row['term_end'] !== null ? (string) $row['term_end'] : null,
        ], $rows);
    }

    public function setRating(int $userId, string $rating): void
    {
        if (!array_key_exists($rating, self::RATING_STIPEND)) {
            throw HttpException::badRequest('评级无效');
        }

        $this->query->run('UPDATE jury_members SET rating = :rating WHERE user_id = :id', ['rating' => $rating, 'id' => $userId]);
    }

    public function removeMember(int $userId, int $actorId): void
    {
        $this->query->transaction(function (Query $q) use ($userId): void {
            $q->delete('jury_members', 'user_id = :id', ['id' => $userId]);
            $role = $q->one("SELECT id FROM roles WHERE slug = 'jury'", []);

            if ($role !== null) {
                $q->delete('user_roles', 'user_id = :id AND role_id = :role', ['id' => $userId, 'role' => (int) $role['id']]);
            }
        });

        $this->notifications->push($userId, null, 'system', 'jury', $userId, [
            'message' => '你已被移出风纪委员会',
            'url' => '/jury',
        ]);
    }

    /** @return array<int, string> case_id => vote（当前委员已投过的案件） */
    public function myVotes(int $userId): array
    {
        $out = [];

        foreach ($this->query->all('SELECT case_id, vote FROM jury_votes WHERE user_id = :id', ['id' => $userId]) as $row) {
            $out[(int) $row['case_id']] = (string) $row['vote'];
        }

        return $out;
    }

    /**
     * 众裁案件列表（可投票）。
     *
     * @return list<array<string, mixed>>
     */
    public function cases(?string $status = null, int $limit = 50): array
    {
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM jury_votes v WHERE v.case_id = c.id) AS votes
                FROM jury_cases c';
        $params = [];

        if ($status !== null) {
            $sql .= ' WHERE c.status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY c.id DESC LIMIT ' . max(1, min(200, $limit));

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'reason' => (string) $row['reason'],
            'snapshot' => (string) ($row['snapshot'] ?? ''),
            'status' => (string) $row['status'],
            'verdict' => (string) $row['verdict'],
            'votes' => (int) $row['votes'],
            'opened_at' => (string) $row['opened_at'],
            'closed_at' => $row['closed_at'] !== null ? (string) $row['closed_at'] : null,
        ], $this->query->all($sql, $params));
    }

    public function openCase(string $targetType, int $targetId, int $reporterId, string $reason, string $snapshot = ''): int
    {
        $allowed = ['post', 'comment', 'confession', 'user', 'report'];

        if (!in_array($targetType, $allowed, true)) {
            throw HttpException::badRequest('案件类型无效');
        }

        if ($targetId <= 0) {
            throw HttpException::badRequest('请填写目标 ID');
        }

        return $this->query->insert('jury_cases', [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reporter_id' => $reporterId > 0 ? $reporterId : null,
            'reason' => mb_substr($reason, 0, 255),
            'snapshot' => mb_substr($snapshot, 0, 2000),
            'status' => 'voting',
            'verdict' => 'none',
            'opened_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 投票（仅委员；一票一案件）。
     *
     * @return array<string, mixed>
     */
    public function vote(int $caseId, int $userId, string $vote): array
    {
        if (!$this->isMember($userId)) {
            throw HttpException::forbidden('只有风纪委员可以参与众裁');
        }

        if (!in_array($vote, ['violation', 'clean'], true)) {
            throw HttpException::badRequest('投票无效');
        }

        $case = $this->query->one('SELECT * FROM jury_cases WHERE id = :id', ['id' => $caseId]);

        if ($case === null) {
            throw HttpException::notFound('案件不存在');
        }

        if ((string) $case['status'] === 'closed') {
            throw HttpException::badRequest('案件已结案');
        }

        $this->query->transaction(function (Query $q) use ($caseId, $userId, $vote): void {
            $q->run(
                'INSERT INTO jury_votes (case_id, user_id, vote, created_at) VALUES (:case, :user, :vote, :now)
                 ON DUPLICATE KEY UPDATE vote = VALUES(vote), created_at = VALUES(created_at)',
                ['case' => $caseId, 'user' => $userId, 'vote' => $vote, 'now' => date('Y-m-d H:i:s')],
            );
        });

        return $this->tally($caseId);
    }

    /** 统计并给出当前裁决倾向 */
    public function tally(int $caseId): array
    {
        $violation = (int) $this->query->value(
            "SELECT COUNT(*) FROM jury_votes WHERE case_id = :id AND vote = 'violation'",
            ['id' => $caseId],
        );
        $clean = (int) $this->query->value(
            "SELECT COUNT(*) FROM jury_votes WHERE case_id = :id AND vote = 'clean'",
            ['id' => $caseId],
        );

        return ['violation' => $violation, 'clean' => $clean, 'total' => $violation + $clean];
    }

    public function closeCase(int $caseId, string $verdict, int $actorId): void
    {
        if (!in_array($verdict, ['violation', 'clean'], true)) {
            throw HttpException::badRequest('结论无效');
        }

        $this->query->run(
            "UPDATE jury_cases SET status = 'closed', verdict = :verdict, closed_at = :now WHERE id = :id AND status <> 'closed'",
            ['verdict' => $verdict, 'now' => date('Y-m-d H:i:s'), 'id' => $caseId],
        );
    }

    /**
     * 发放本月津贴（按评级，最多 30 M币；幂等到月）。
     *
     * @return array{month: string, paid: int, total: float}
     */
    public function payMonthlyStipend(): array
    {
        $month = date('Y-m');
        $paid = 0;
        $total = 0.0;

        foreach ($this->query->all('SELECT * FROM jury_members') as $member) {
            $userId = (int) $member['user_id'];
            $amount = self::RATING_STIPEND[(string) $member['rating']] ?? 0.0;

            if ($amount <= 0) {
                continue;
            }

            $ok = $this->query->run(
                'INSERT IGNORE INTO jury_stipends (user_id, month, cases, amount, created_at) VALUES (:id, :month, 0, :amount, :now)',
                ['id' => $userId, 'month' => $month, 'amount' => $amount, 'now' => date('Y-m-d H:i:s')],
            )->rowCount() > 0;

            if (!$ok) {
                continue;
            }

            $this->wallet->credit($userId, $amount, 'task', '风纪委员月度津贴（' . $month . '）', null, 'jury-stipend:' . $userId . ':' . $month);
            $this->query->run('UPDATE jury_members SET last_stipend_month = :month WHERE user_id = :id', ['month' => $month, 'id' => $userId]);

            $paid++;
            $total += $amount;
        }

        return ['month' => $month, 'paid' => $paid, 'total' => round($total, 2)];
    }

    /**
     * 年度结算：全年参与审核的案件数 ÷ 20 发放。
     *
     * @return array{year: int, paid: int, total: float}
     */
    public function settleYear(int $year): array
    {
        $start = $year . '-01-01 00:00:00';
        $end = ($year + 1) . '-01-01 00:00:00';
        $paid = 0;
        $total = 0.0;

        $rows = $this->query->all(
            'SELECT v.user_id, COUNT(DISTINCT v.case_id) AS cases FROM jury_votes v
             JOIN jury_cases c ON c.id = v.case_id
             WHERE c.status = \'closed\' AND c.closed_at >= :start AND c.closed_at < :end
             GROUP BY v.user_id',
            ['start' => $start, 'end' => $end],
        );

        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            $amount = round(((int) $row['cases']) / 20, 2);

            if ($amount <= 0) {
                continue;
            }

            $this->wallet->credit(
                $userId,
                $amount,
                'task',
                '风纪委员会年度结算（' . $year . '，' . (int) $row['cases'] . ' 件）',
                null,
                'jury-settle:' . $userId . ':' . $year,
            );

            $this->query->run(
                'UPDATE jury_stipends SET cases = :cases WHERE user_id = :id AND month = :month',
                ['cases' => (int) $row['cases'], 'id' => $userId, 'month' => $year . '-12'],
            );

            $paid++;
            $total += $amount;
        }

        return ['year' => $year, 'paid' => $paid, 'total' => round($total, 2)];
    }
}
