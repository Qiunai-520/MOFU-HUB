<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use Mofu\Core\Security\RateLimiter;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 每日签到。
 *
 * 日期一律以服务端时区（Asia/Shanghai）为准，客户端时间不参与判断；
 * 连续天数在事务里对 user_stats 加行锁计算，(user_id, checkin_date) 唯一键保证不会重复签到。
 */
final class CheckInService
{
    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
        private readonly LevelService $levels,
        private readonly TitleService $titles,
        private readonly AchievementService $achievements,
        private readonly RateLimiter $limiter,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return array<string, mixed> */
    public function status(int $userId): array
    {
        $this->ensureStats($userId);

        $stats = $this->query->one('SELECT * FROM user_stats WHERE user_id = :id', ['id' => $userId]) ?? [];
        $today = date('Y-m-d');
        $checkedToday = (string) ($stats['last_checkin_date'] ?? '') === $today;
        $streak = (int) ($stats['checkin_streak'] ?? 0);

        $nextDay = $checkedToday ? $streak : $streak + 1;
        $next = $this->rewardFor(max(1, $nextDay));

        return [
            'checked_today' => $checkedToday,
            'streak' => $streak,
            'best' => (int) ($stats['checkin_best'] ?? 0),
            'total' => (int) ($stats['checkin_total'] ?? 0),
            'next_day' => max(1, $nextDay),
            'next_reward' => [
                'mofu' => (int) $next['reward_mofu'],
                'xp' => (int) $next['reward_xp'],
                'label' => (string) $next['label'],
            ],
            'rewards' => $this->rewards(),
            'today' => $today,
        ];
    }

    /**
     * 执行签到。幂等：同一天重复调用不会重复发放。
     *
     * @return array<string, mixed>
     */
    public function checkin(int $userId): array
    {
        if (!$this->limiter->attempt('checkin:' . $userId, 10, 60)) {
            throw \Mofu\Core\Http\HttpException::tooManyRequests('操作太频繁，请稍后再试');
        }

        $this->ensureStats($userId);
        $today = date('Y-m-d');

        $outcome = $this->query->transaction(function (Query $q) use ($userId, $today): ?array {
            $stats = $q->one('SELECT * FROM user_stats WHERE user_id = :id FOR UPDATE', ['id' => $userId]) ?? [];

            if ((string) ($stats['last_checkin_date'] ?? '') === $today) {
                return null;
            }

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $streak = (string) ($stats['last_checkin_date'] ?? '') === $yesterday ? (int) $stats['checkin_streak'] + 1 : 1;
            $best = max((int) ($stats['checkin_best'] ?? 0), $streak);
            $total = (int) ($stats['checkin_total'] ?? 0) + 1;
            $reward = $this->rewardFor($streak);

            $q->insert('check_ins', [
                'user_id' => $userId,
                'checkin_date' => $today,
                'streak' => $streak,
                'reward_mofu' => (int) $reward['reward_mofu'],
                'reward_xp' => (int) $reward['reward_xp'],
                'label' => (string) $reward['label'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $q->update('user_stats', [
                'checkin_streak' => $streak,
                'checkin_best' => $best,
                'checkin_total' => $total,
                'last_checkin_date' => $today,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :id', ['id' => $userId]);

            return [
                'streak' => $streak,
                'best' => $best,
                'total' => $total,
                'reward_mofu' => (int) $reward['reward_mofu'],
                'reward_xp' => (int) $reward['reward_xp'],
                'label' => (string) $reward['label'],
                'title_id' => $reward['title_id'] !== null ? (int) $reward['title_id'] : null,
            ];
        });

        if ($outcome === null) {
            return ['already' => true, 'status' => $this->status($userId)];
        }

        $mofu = (int) $outcome['reward_mofu'];
        $xp = (int) $outcome['reward_xp'];

        if ($mofu > 0) {
            $this->wallet->credit($userId, $mofu, 'checkin', '签到奖励', null, 'checkin:' . $userId . ':' . $today);
        }

        if ($xp > 0) {
            $this->levels->addXp($userId, $xp, 'checkin', '每日签到');
        }

        if ($outcome['title_id'] !== null) {
            $this->titles->grant($userId, (int) $outcome['title_id'], 'checkin');
        }

        $this->achievements->evaluate($userId);
        $newTitles = $this->titles->evaluate($userId);

        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => sprintf('签到成功，连续 %d 天，获得 %d M币', (int) $outcome['streak'], $mofu),
            'url' => '/me/growth',
        ]);

        return [
            'already' => false,
            'streak' => (int) $outcome['streak'],
            'best' => (int) $outcome['best'],
            'reward_mofu' => $mofu,
            'reward_xp' => $xp,
            'label' => (string) $outcome['label'],
            'new_titles' => $newTitles,
            'status' => $this->status($userId),
        ];
    }

    /**
     * 某月签到日历。
     *
     * @return array{month: string, days: list<array<string, mixed>>}
     */
    public function calendar(int $userId, ?string $month = null): array
    {
        $month = is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : date('Y-m');

        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start) ?: time());

        $checked = array_map(
            static fn (array $row): string => (string) $row['checkin_date'],
            $this->query->all(
                'SELECT checkin_date FROM check_ins WHERE user_id = :user_id AND checkin_date BETWEEN :start AND :end',
                ['user_id' => $userId, 'start' => $start, 'end' => $end],
            ),
        );

        $special = array_map(
            static fn (array $row): int => (int) $row['day'],
            $this->query->all('SELECT day FROM check_in_rewards WHERE title_id IS NOT NULL OR reward_mofu >= 100'),
        );

        $days = [];
        $cursor = strtotime($start) ?: time();
        $last = strtotime($end) ?: time();

        while ($cursor <= $last) {
            $date = date('Y-m-d', $cursor);
            $days[] = [
                'date' => $date,
                'day' => (int) date('j', $cursor),
                'checked' => in_array($date, $checked, true),
                'today' => $date === date('Y-m-d'),
                'weekday' => (int) date('N', $cursor),
            ];
            $cursor = strtotime('+1 day', $cursor) ?: ($cursor + 86400);
        }

        return ['month' => $month, 'days' => $days, 'special_days' => $special];
    }

    /** @return list<array<string, mixed>> */
    public function rewards(): array
    {
        return array_map(
            static fn (array $row): array => [
                'day' => (int) $row['day'],
                'reward_mofu' => (int) $row['reward_mofu'],
                'reward_xp' => (int) $row['reward_xp'],
                'label' => (string) $row['label'],
                'title_id' => $row['title_id'] !== null ? (int) $row['title_id'] : null,
            ],
            $this->query->all('SELECT * FROM check_in_rewards ORDER BY day ASC'),
        );
    }

    /** @param list<array<string, mixed>> $rows */
    public function saveRewards(array $rows): void
    {
        $this->query->transaction(function (Query $q) use ($rows): void {
            foreach ($rows as $row) {
                $day = (int) ($row['day'] ?? 0);

                if ($day <= 0) {
                    continue;
                }

                $q->run(
                    'INSERT INTO check_in_rewards (day, reward_mofu, reward_xp, title_id, label)
                     VALUES (:day, :mofu, :xp, :title, :label)
                     ON DUPLICATE KEY UPDATE reward_mofu = VALUES(reward_mofu), reward_xp = VALUES(reward_xp),
                        title_id = VALUES(title_id), label = VALUES(label)',
                    [
                        'day' => $day,
                        'mofu' => max(0, (int) ($row['reward_mofu'] ?? 0)),
                        'xp' => max(0, (int) ($row['reward_xp'] ?? 0)),
                        'title' => isset($row['title_id']) && (int) $row['title_id'] > 0 ? (int) $row['title_id'] : null,
                        'label' => mb_substr((string) ($row['label'] ?? ''), 0, 64),
                    ],
                );
            }
        });
    }

    /** @return array<string, mixed> */
    private function rewardFor(int $streak): array
    {
        $row = $this->query->one('SELECT * FROM check_in_rewards WHERE day = :day', ['day' => $streak]);

        if ($row === null) {
            $row = $this->query->one('SELECT * FROM check_in_rewards ORDER BY day ASC LIMIT 1');
        }

        return $row ?? ['reward_mofu' => 10, 'reward_xp' => 10, 'label' => '今日签到', 'title_id' => null];
    }

    private function ensureStats(int $userId): void
    {
        $exists = $this->query->value('SELECT user_id FROM user_stats WHERE user_id = :id', ['id' => $userId]);

        if ($exists === null) {
            $this->query->insert('user_stats', [
                'user_id' => $userId,
                'checkin_streak' => 0,
                'checkin_best' => 0,
                'checkin_total' => 0,
                'last_checkin_date' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}
