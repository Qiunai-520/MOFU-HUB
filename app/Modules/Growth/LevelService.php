<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 会员等级（Lv0 - Lv6）。
 *
 * Lv0 = 已注册但未通过答题转正；通过 100 题测试（≥60 分）后成为 Lv1 正式会员。
 * Lv2 - Lv6 按累计经验划分，门槛由 level_configs 配置，服务端只做判定与发放。
 */
final class LevelService
{
    public const MIN_LEVEL = 0;
    public const MAX_LEVEL = 6;

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return array<string, mixed> */
    public function stats(int $userId): array
    {
        $this->ensure($userId);

        $row = $this->query->one('SELECT xp, level FROM user_levels WHERE user_id = :user_id', ['user_id' => $userId]) ?? ['xp' => 0, 'level' => 0];
        $xp = (int) $row['xp'];

        $formal = $this->isFormal($userId);
        $level = $this->levelForXp($xp, $formal);

        if ((int) $row['level'] !== $level) {
            $this->query->update('user_levels', ['level' => $level, 'updated_at' => date('Y-m-d H:i:s')], 'user_id = :user_id', ['user_id' => $userId]);
        }

        $current = $this->configFor($level);
        $next = $level < self::MAX_LEVEL ? $this->configFor($level + 1) : null;

        $floorXp = (int) ($current['xp_required'] ?? 0);
        $nextXp = $next !== null ? (int) $next['xp_required'] : null;
        $progress = $nextXp === null ? 100 : (int) max(0, min(100, round(($xp - $floorXp) / max(1, $nextXp - $floorXp) * 100)));

        return [
            'level' => $level,
            'level_name' => (string) ($current['name'] ?? ('Lv.' . $level)),
            'xp' => $xp,
            'is_formal' => $formal,
            'membership' => $this->membership($userId),
            'is_premium' => $this->isPremium($userId),
            'current_floor_xp' => $floorXp,
            'next_level' => $next !== null ? $level + 1 : null,
            'next_level_xp' => $nextXp,
            'remaining_xp' => $nextXp !== null ? max(0, $nextXp - $xp) : 0,
            'progress' => $progress,
            'max_level' => self::MAX_LEVEL,
            'max_xp' => $this->maxXp(),
            'is_flash' => $this->isFlash($userId),
            'rank' => $this->rank($userId),
        ];
    }

    /**
     * 增加经验；跨过门槛时发放等级奖励并通知。
     *
     * @return array{old_level: int, new_level: int, level_up: bool, xp: int}
     */
    public function addXp(int $userId, int $amount, string $source, string $reason = ''): array
    {
        if ($amount <= 0) {
            $current = $this->stats($userId);

            return ['old_level' => (int) $current['level'], 'new_level' => (int) $current['level'], 'level_up' => false, 'xp' => (int) $current['xp']];
        }

        $this->ensure($userId);

        $result = $this->query->transaction(function (Query $q) use ($userId, $amount): array {
            $row = $q->one('SELECT xp, level FROM user_levels WHERE user_id = :user_id FOR UPDATE', ['user_id' => $userId]);
            $xp = (int) ($row['xp'] ?? 0);
            $oldLevel = (int) ($row['level'] ?? 0);

            // 满级封顶：超过 Lv.MAX 门槛的经验不再累加（否则满级后 XP 会一直往上涨）
            $cap = $this->maxXp();
            $xp = $cap > 0 ? min($xp + $amount, $cap) : $xp + $amount;
            $newLevel = $this->levelForXp($xp, $this->isFormal($userId));

            $q->update('user_levels', [
                'xp' => $xp,
                'level' => $newLevel,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :user_id', ['user_id' => $userId]);

            return ['old' => $oldLevel, 'new' => $newLevel, 'xp' => $xp];
        });

        $levelUp = $result['new'] > $result['old'];

        if ($levelUp) {
            for ($level = $result['old'] + 1; $level <= $result['new']; $level++) {
                $config = $this->configFor($level);
                $reward = (int) ($config['reward_mofu'] ?? 0);

                if ($reward > 0) {
                    $this->wallet->credit($userId, $reward, 'level', '升级奖励 ' . (string) ($config['name'] ?? ('Lv.' . $level)), null, 'level:' . $userId . ':' . $level);
                }
            }

            $this->notifications->push($userId, null, 'system', '', null, [
                'message' => sprintf('升级啦！你现在是 %s%s', (string) ($this->configFor($result['new'])['name'] ?? ''), $source !== '' ? '（来自' . $reason . '）' : ''),
                'url' => '/growth',
            ]);
        }

        return ['old_level' => $result['old'], 'new_level' => $result['new'], 'level_up' => $levelUp, 'xp' => $result['xp']];
    }

    /** 满级（Lv.MAX）所需经验，作为经验上限；未配置时返回 0（不做封顶） */
    public function maxXp(): int
    {
        $xp = $this->query->value(
            'SELECT xp_required FROM level_configs WHERE level = :level',
            ['level' => self::MAX_LEVEL],
        );

        return max(0, (int) ($xp ?? 0));
    }

    /** 是否已通过进阶答题（闪电 Lv.6） */
    public function isFlash(int $userId): bool
    {
        return (int) ($this->query->value('SELECT is_flash FROM users WHERE id = :id', ['id' => $userId]) ?? 0) === 1;
    }

    /** 进阶答题通过：在 Lv.MAX 之上点亮「闪电」标记 */
    public function markFlash(int $userId): void
    {
        $this->query->update(
            'users',
            ['is_flash' => 1, 'updated_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $userId],
        );

        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => '你已通过进阶答题，成为「闪电 Lv.6」！',
            'url' => '/growth',
        ]);
    }

    /** 答题通过：转为正式会员（等级随之至少升到 Lv1） */
    public function markFormal(int $userId): array
    {
        $this->query->run(
            'UPDATE users SET is_formal = 1, email_verified_at = COALESCE(email_verified_at, :now_verified), updated_at = :now_updated WHERE id = :id',
            ['now_verified' => date('Y-m-d H:i:s'), 'now_updated' => date('Y-m-d H:i:s'), 'id' => $userId],
        );

        $this->ensure($userId);

        $xp = (int) $this->query->value('SELECT xp FROM user_levels WHERE user_id = :user_id', ['user_id' => $userId]);
        $level = $this->levelForXp($xp, true);

        $this->query->update('user_levels', ['level' => $level, 'updated_at' => date('Y-m-d H:i:s')], 'user_id = :user_id', ['user_id' => $userId]);

        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => '恭喜通过答题，你已成为正式会员（Lv.1）',
            'url' => '/growth',
        ]);

        return ['level' => $level, 'xp' => $xp];
    }

    public function setMembership(int $userId, string $membership): void
    {
        $membership = $membership === 'premium' ? 'premium' : 'normal';
        $this->query->update('users', ['membership' => $membership, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);

        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => $membership === 'premium' ? '你已开通大会员，每日额外 +10 经验' : '大会员状态已取消',
            'url' => '/growth',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function levels(): array
    {
        return array_map(
            static fn (array $row): array => [
                'level' => (int) $row['level'],
                'xp_required' => (int) $row['xp_required'],
                'name' => (string) $row['name'],
                'reward_mofu' => (int) $row['reward_mofu'],
            ],
            $this->query->all('SELECT * FROM level_configs ORDER BY level ASC'),
        );
    }

    /** @param list<array<string, mixed>> $rows */
    public function configure(array $rows): void
    {
        $this->query->transaction(function (Query $q) use ($rows): void {
            foreach ($rows as $row) {
                $level = (int) ($row['level'] ?? -1);

                if ($level < 0 || $level > self::MAX_LEVEL) {
                    continue;
                }

                $q->run(
                    'INSERT INTO level_configs (level, xp_required, name, reward_mofu)
                     VALUES (:level, :xp, :name, :reward)
                     ON DUPLICATE KEY UPDATE xp_required = VALUES(xp_required), name = VALUES(name), reward_mofu = VALUES(reward_mofu)',
                    [
                        'level' => $level,
                        'xp' => max(0, (int) ($row['xp_required'] ?? 0)),
                        'name' => mb_substr((string) ($row['name'] ?? ('Lv.' . $level)), 0, 32),
                        'reward' => max(0, (int) ($row['reward_mofu'] ?? 0)),
                    ],
                );
            }
        });
    }

    public function rank(int $userId): ?int
    {
        $xp = (int) $this->query->value('SELECT xp FROM user_levels WHERE user_id = :user_id', ['user_id' => $userId]);

        if ($xp <= 0) {
            return null;
        }

        $ahead = (int) $this->query->value('SELECT COUNT(*) FROM user_levels WHERE xp > :xp', ['xp' => $xp]);

        return $ahead + 1;
    }

    public function isFormal(int $userId): bool
    {
        return (int) ($this->query->value('SELECT is_formal FROM users WHERE id = :id', ['id' => $userId]) ?? 0) === 1;
    }

    public function membership(int $userId): string
    {
        $row = $this->query->one(
            'SELECT membership, membership_expires_at FROM users WHERE id = :id',
            ['id' => $userId],
        );

        if ($row === null || (string) $row['membership'] !== 'premium') {
            return 'normal';
        }

        $expires = $row['membership_expires_at'] ?? null;

        // 到期惰性降级（管理员直接发放的会员没有到期时间，视为长期有效）
        if ($expires !== null && strtotime((string) $expires) < time()) {
            $this->query->run(
                "UPDATE users SET membership = 'normal', membership_plan = 'none' WHERE id = :id",
                ['id' => $userId],
            );

            return 'normal';
        }

        return 'premium';
    }

    public function isPremium(int $userId): bool
    {
        return $this->membership($userId) === 'premium';
    }

    private function levelForXp(int $xp, bool $formal): int
    {
        if (!$formal) {
            return self::MIN_LEVEL;
        }

        $level = (int) $this->query->value(
            'SELECT level FROM level_configs WHERE xp_required <= :xp AND level >= 1 ORDER BY level DESC LIMIT 1',
            ['xp' => $xp],
        );

        return max(1, $level);
    }

    /** @return array<string, mixed>|null */
    private function configFor(int $level): ?array
    {
        return $this->query->one('SELECT * FROM level_configs WHERE level = :level', ['level' => $level]);
    }

    private function ensure(int $userId): void
    {
        $exists = $this->query->value('SELECT user_id FROM user_levels WHERE user_id = :user_id', ['user_id' => $userId]);

        if ($exists === null) {
            $this->query->insert('user_levels', [
                'user_id' => $userId,
                'xp' => 0,
                'level' => $this->isFormal($userId) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
