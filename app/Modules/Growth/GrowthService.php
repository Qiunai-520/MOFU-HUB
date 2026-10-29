<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Modules\Membership\QuizService;
use Mofu\Modules\Membership\TaskService;

/**
 * 成长中心的聚合读取入口：钱包 / 等级 / 签到 / 称号 / 成就 / 收藏 / 会员任务。
 */
final class GrowthService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly LevelService $levels,
        private readonly CheckInService $checkin,
        private readonly TitleService $titles,
        private readonly AchievementService $achievements,
        private readonly ShopService $shop,
        private readonly TaskService $tasks,
        private readonly QuizService $quiz,
    ) {
    }

    /** @return array<string, mixed> */
    public function profile(int $userId): array
    {
        $wallet = $this->wallet->wallet($userId);

        return [
            'wallet' => [
                'balance' => (int) $wallet['balance'],
                'total_earned' => (int) $wallet['total_earned'],
                'total_spent' => (int) $wallet['total_spent'],
            ],
            'level' => $this->levels->stats($userId),
            'tasks' => $this->tasks->status($userId),
            'quiz' => $this->quiz->status($userId),
            'checkin' => $this->checkin->status($userId),
            'titles' => $this->titles->catalogueFor($userId),
            'achievements' => $this->achievements->forUser($userId),
            'inventory' => $this->shop->inventory($userId),
            'transactions' => $this->wallet->history($userId, 1, 10)['items'],
        ];
    }

    /** 成就 / 称号的条件重算（页面访问时触发，保证进度不过期） */
    public function refresh(int $userId): void
    {
        $this->titles->evaluate($userId);
        $this->achievements->evaluate($userId);
    }
}
