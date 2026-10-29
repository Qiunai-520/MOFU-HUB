<?php

declare(strict_types=1);

namespace Mofu\Modules\Membership;

use Mofu\Core\Database\Query;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Growth\WalletService;

/**
 * 经验任务（每日 / 一次性）。
 *
 * 每日任务：登录 +5、观看 +5、分享 +5、投币每币 +10（每日上限 50），基础合计 65；
 * 大会员另有「加速包 +10」，需在页面手动领取（不再随任务自动发放）。
 * 一次性任务：设置头像 +100、设置主页背景图 +50、设置密码 +30、绑定邮箱 +20（合计 200）。
 * 日期一律以服务端为准，并用 (user_id, counter_date, source) 唯一键保证幂等。
 */
final class TaskService
{
    public const PREMIUM_BONUS = 10;

    /** 每日登录奖励：需 Lv.1 且已绑定邮箱，每天 1 M币 */
    public const LOGIN_MOFU = 1.0;

    /** @var array<string, array{label: string, xp: int, unit: bool, cap: int}> */
    public const DAILY = [
        'login' => ['label' => '每日登录', 'xp' => 5, 'unit' => false, 'cap' => 5],
        'watch' => ['label' => '每日观看视频', 'xp' => 5, 'unit' => false, 'cap' => 5],
        'share' => ['label' => '每日分享视频', 'xp' => 5, 'unit' => false, 'cap' => 5],
        'coin' => ['label' => '投币支持', 'xp' => 10, 'unit' => true, 'cap' => 50],
    ];

    /** @var array<string, array{label: string, xp: int}> */
    public const ONCE = [
        'set_avatar' => ['label' => '设置头像', 'xp' => 100],
        'set_background' => ['label' => '设置主页背景图', 'xp' => 50],
        'set_password' => ['label' => '设置密码', 'xp' => 30],
        'bind_email' => ['label' => '绑定邮箱', 'xp' => 20],
    ];

    public function __construct(
        private readonly Query $query,
        private readonly LevelService $levels,
        private readonly WalletService $wallet,
    ) {
    }

    /** 每日基础经验上限 */
    public static function dailyCap(): int
    {
        $total = 0;

        foreach (self::DAILY as $task) {
            $total += $task['cap'];
        }

        return $total;
    }

    /**
     * 完成一次每日任务。
     *
     * @return array{ok: bool, already: bool, xp: int, reason: string}
     */
    public function award(int $userId, string $source, int $units = 1): array
    {
        if (!isset(self::DAILY[$source])) {
            return ['ok' => false, 'already' => false, 'xp' => 0, 'reason' => 'unknown_task'];
        }

        $task = self::DAILY[$source];
        $units = max(1, min($units, 10));
        $today = date('Y-m-d');
        $key = 'xp:' . $source;

        $row = $this->query->one(
            'SELECT earned, count FROM growth_daily_counters WHERE user_id = :user_id AND counter_date = :date AND source = :source',
            ['user_id' => $userId, 'date' => $today, 'source' => $key],
        );

        $earned = (int) ($row['earned'] ?? 0);
        $count = (int) ($row['count'] ?? 0);

        if (!$task['unit'] && $count >= 1) {
            return ['ok' => true, 'already' => true, 'xp' => 0, 'reason' => 'already_done'];
        }

        $want = $task['xp'] * ($task['unit'] ? $units : 1);
        $allowed = max(0, min($want, $task['cap'] - $earned));

        if ($allowed <= 0) {
            return ['ok' => true, 'already' => true, 'xp' => 0, 'reason' => 'cap_reached'];
        }

        $this->levels->addXp($userId, $allowed, 'task', $task['label']);

        $this->query->run(
            'INSERT INTO growth_daily_counters (user_id, counter_date, source, earned, count)
             VALUES (:user_id, :date, :source, :earned, 1)
             ON DUPLICATE KEY UPDATE earned = earned + VALUES(earned), count = count + 1',
            ['user_id' => $userId, 'date' => $today, 'source' => $key, 'earned' => $allowed],
        );

        // 每日登录奖励发 M币：需 Lv.1 且已绑定邮箱（幂等：同一用户同一天只入账一次）
        if ($source === 'login' && $this->loginRewardEligible($userId)) {
            $this->wallet->credit($userId, self::LOGIN_MOFU, 'task', '每日登录奖励', null, 'login:mofu:' . $userId . ':' . $today);
        }

        return ['ok' => true, 'already' => false, 'xp' => $allowed, 'reason' => 'awarded'];
    }

    /** 每日登录 M币门槛：正式会员（Lv.1+）且已完成邮箱验证 */
    public function loginRewardEligible(int $userId): bool
    {
        if ((int) ($this->levels->stats($userId)['level'] ?? 0) < 1) {
            return false;
        }

        return $this->query->value(
            'SELECT email_verified_at FROM users WHERE id = :id',
            ['id' => $userId],
        ) !== null;
    }

    /**
     * 大会员加速包：每日一次，需在页面手动领取。
     *
     * @return array{ok: bool, already: bool, xp: int}
     */
    public function claimPremiumBonus(int $userId): array
    {
        if (!$this->levels->isPremium($userId)) {
            return ['ok' => false, 'already' => false, 'xp' => 0];
        }

        $today = date('Y-m-d');
        $inserted = $this->query->run(
            'INSERT IGNORE INTO growth_daily_counters (user_id, counter_date, source, earned, count)
             VALUES (:user_id, :date, :source, :earned, 1)',
            ['user_id' => $userId, 'date' => $today, 'source' => 'xp:premium', 'earned' => self::PREMIUM_BONUS],
        )->rowCount() > 0;

        if (!$inserted) {
            return ['ok' => true, 'already' => true, 'xp' => 0];
        }

        $this->levels->addXp($userId, self::PREMIUM_BONUS, 'task_premium', '大会员每日加速包');

        return ['ok' => true, 'already' => false, 'xp' => self::PREMIUM_BONUS];
    }

    /**
     * 一次性任务：绑定邮箱 / 设置密码。
     *
     * @return array{ok: bool, xp: int}
     */
    public function awardOnce(int $userId, string $task): array
    {
        if (!isset(self::ONCE[$task])) {
            return ['ok' => false, 'xp' => 0];
        }

        $xp = self::ONCE[$task]['xp'];

        $inserted = $this->query->run(
            'INSERT IGNORE INTO user_tasks (user_id, task, reward_xp, completed_at) VALUES (:user_id, :task, :xp, :now)',
            ['user_id' => $userId, 'task' => $task, 'xp' => $xp, 'now' => date('Y-m-d H:i:s')],
        )->rowCount() > 0;

        if (!$inserted) {
            return ['ok' => true, 'xp' => 0];
        }

        $this->levels->addXp($userId, $xp, 'task_once', self::ONCE[$task]['label']);

        return ['ok' => true, 'xp' => $xp];
    }

    /**
     * 每日任务进度（供前端展示）。
     *
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $today = date('Y-m-d');

        $rows = $this->query->all(
            'SELECT source, earned, count FROM growth_daily_counters WHERE user_id = :user_id AND counter_date = :date',
            ['user_id' => $userId, 'date' => $today],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['source']] = ['earned' => (int) $row['earned'], 'count' => (int) $row['count']];
        }

        $tasks = [];
        $earnedTotal = 0;

        foreach (self::DAILY as $source => $task) {
            $state = $map['xp:' . $source] ?? ['earned' => 0, 'count' => 0];
            $earnedTotal += $state['earned'];

            $tasks[] = [
                'source' => $source,
                'label' => $task['label'],
                'xp' => $task['xp'],
                'unit' => $task['unit'],
                'cap' => $task['cap'],
                'earned' => $state['earned'],
                'count' => $state['count'],
                'done' => $task['unit'] ? $state['earned'] >= $task['cap'] : $state['count'] >= 1,
            ];
        }

        $onceDone = [];

        foreach ($this->query->all('SELECT task FROM user_tasks WHERE user_id = :user_id', ['user_id' => $userId]) as $row) {
            $onceDone[(string) $row['task']] = true;
        }

        $once = [];

        foreach (self::ONCE as $task => $meta) {
            $once[] = ['task' => $task, 'label' => $meta['label'], 'xp' => $meta['xp'], 'done' => isset($onceDone[$task])];
        }

        $premiumDone = ($map['xp:premium']['count'] ?? 0) >= 1;

        return [
            'tasks' => $tasks,
            'once' => $once,
            'premium_bonus' => self::PREMIUM_BONUS,
            'premium_bonus_done' => $premiumDone,
            'premium_bonus_available' => !$premiumDone && $this->levels->isPremium($userId),
            'daily_cap' => self::dailyCap(),
            'earned_today' => $earnedTotal,
            'login_mofu' => self::LOGIN_MOFU,
            'login_mofu_eligible' => $this->loginRewardEligible($userId),
        ];
    }
}
