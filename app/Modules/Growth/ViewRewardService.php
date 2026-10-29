<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use PDOException;

/**
 * 「每日正常观看帖子达到 10 篇 → 得 0.3 M币」结算。
 *
 * 观看记录复用 post_views（(user_id, post_id) 主键 + viewed_at），
 * 因此「当日看过的不同帖子数」= viewed_at 落在今天的行数。
 * 同一 IP 当日仅首个达标的账号可领：靠 (reward_date, ip) 唯一键拦重复。
 */
final class ViewRewardService
{
    public const REQUIRED_POSTS = 10;
    public const REWARD_MOFU = 0.3;

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
    ) {
    }

    /** 登录用户查看帖子后调用；满足条件则结算一次（幂等），返回是否本次到账 */
    public function settle(int $userId, string $ip): bool
    {
        $today = date('Y-m-d');
        $start = $today . ' 00:00:00';
        $end = date('Y-m-d 00:00:00', strtotime($today . ' +1 day'));

        $count = (int) $this->query->value(
            'SELECT COUNT(*) FROM post_views WHERE user_id = :u AND viewed_at >= :start AND viewed_at < :end',
            ['u' => $userId, 'start' => $start, 'end' => $end],
        );

        if ($count < self::REQUIRED_POSTS) {
            return false;
        }

        try {
            $this->query->insert('view_reward_settlements', [
                'user_id' => $userId,
                'reward_date' => $today,
                'ip' => mb_substr($ip, 0, 45),
                'post_count' => $count,
                'amount' => self::REWARD_MOFU,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            // 唯一键冲突：本人今天已结算，或同一 IP 今天已有人领过
            return false;
        }

        $this->wallet->credit(
            $userId,
            self::REWARD_MOFU,
            'task',
            '每日观看奖励（' . $count . ' 篇）',
            null,
            'view-reward:' . $userId . ':' . $today,
        );

        return true;
    }
}
