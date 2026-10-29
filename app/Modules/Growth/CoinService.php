<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Mofu\Modules\Membership\TaskService;
use Mofu\Modules\Notifications\NotificationService;

/**
 * M币投币（打赏）。
 * 投币人扣除 M币并获得经验（每币 +10，每日上限由 TaskService 限制），作者收到等额 M币。
 * 每篇内容每人最多 2 币；不能给自己投币。
 */
final class CoinService
{
    public const MAX_PER_POST = 2;

    /** 收到投币的回报：每 1 币给作者 0.1 M币 */
    public const AUTHOR_SHARE_PER_COIN = 0.1;

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
        private readonly TaskService $tasks,
        private readonly NotificationService $notifications,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function give(int $userId, int $postId, int $amount = 1): array
    {
        $amount = max(1, min(self::MAX_PER_POST, $amount));

        if (!$this->limiter->attempt('coin:' . $userId, 20, 60)) {
            throw HttpException::tooManyRequests('操作太频繁，请稍后再试');
        }

        $post = $this->query->one(
            'SELECT id, user_id, coin_count FROM posts WHERE id = :id AND deleted_at IS NULL',
            ['id' => $postId],
        );

        if ($post === null) {
            throw HttpException::notFound('内容不存在');
        }

        $ownerId = (int) $post['user_id'];

        if ($ownerId === $userId) {
            throw HttpException::badRequest('不能给自己投币');
        }

        $current = (int) $this->query->value(
            'SELECT COALESCE(amount, 0) FROM post_coins WHERE post_id = :post_id AND user_id = :user_id',
            ['post_id' => $postId, 'user_id' => $userId],
        );

        if ($current + $amount > self::MAX_PER_POST) {
            throw HttpException::badRequest('这篇内容你已经投满 ' . self::MAX_PER_POST . ' 个 M币了');
        }

        if ($this->wallet->balance($userId) < $amount) {
            throw HttpException::badRequest('M币余额不足');
        }

        $this->wallet->debit($userId, $amount, 'coin', '投币支持：动态 #' . $postId);

        try {
            // 先确保行存在，再用带条件的原子自增：并发提交时超出上限的那次影响 0 行，
            // 在这里抛错并走到下方退款，避免重复扣款或超出上限。
            $result = $this->query->transaction(function (Query $q) use ($postId, $userId, $amount): array {
                $now = date('Y-m-d H:i:s');

                $q->run(
                    'INSERT INTO post_coins (post_id, user_id, amount, created_at, updated_at)
                     VALUES (:post_id, :user_id, 0, :now_insert, :now_update)
                     ON DUPLICATE KEY UPDATE post_id = post_id',
                    ['post_id' => $postId, 'user_id' => $userId, 'now_insert' => $now, 'now_update' => $now],
                );

                // 注意：PDO 原生预处理下同一命名占位符不能在同一条 SQL 里复用，
                // 所以上限判断写成「当前值 <= 上限 - 本次增量」，只绑一次增量。
                $updated = $q->run(
                    'UPDATE post_coins SET amount = amount + :delta, updated_at = :now
                     WHERE post_id = :post_id AND user_id = :user_id AND amount <= :limit',
                    [
                        'delta' => $amount,
                        'now' => $now,
                        'post_id' => $postId,
                        'user_id' => $userId,
                        'limit' => self::MAX_PER_POST - $amount,
                    ],
                );

                if ($updated->rowCount() === 0) {
                    throw HttpException::badRequest('这篇内容你已经投满 ' . self::MAX_PER_POST . ' 个 M币了');
                }

                $q->run('UPDATE posts SET coin_count = coin_count + :amount WHERE id = :id', ['amount' => $amount, 'id' => $postId]);

                $coinRow = $q->one(
                    'SELECT amount FROM post_coins WHERE post_id = :post_id AND user_id = :user_id',
                    ['post_id' => $postId, 'user_id' => $userId],
                );
                $postRow = $q->one('SELECT coin_count FROM posts WHERE id = :id', ['id' => $postId]);

                return [
                    'my_coins' => (int) ($coinRow['amount'] ?? 0),
                    'coin_count' => (int) ($postRow['coin_count'] ?? 0),
                ];
            });
        } catch (\Throwable $e) {
            $this->wallet->credit($userId, $amount, 'coin', '投币失败退回：动态 #' . $postId);

            throw $e;
        }

        $this->wallet->credit($ownerId, $amount * self::AUTHOR_SHARE_PER_COIN, 'coin', '收到投币：动态 #' . $postId);

        $task = $this->tasks->award($userId, 'coin', $amount);

        $this->notifications->push($ownerId, $userId, 'post_coined', 'post', $postId, [
            'message' => sprintf('收到 %d 个 M币打赏', $amount),
            'url' => '/posts/' . $postId,
        ]);

        return [
            'my_coins' => $result['my_coins'],
            'coin_count' => $result['coin_count'],
            'balance' => $this->wallet->balance($userId),
            'xp' => $task['xp'],
            'xp_reason' => $task['reason'],
        ];
    }

    /** @return array{coin_count: int, my_coins: int, max: int, balance: int|null} */
    public function summary(int $postId, ?int $userId): array
    {
        $count = (int) $this->query->value('SELECT coin_count FROM posts WHERE id = :id', ['id' => $postId]);
        $my = 0;

        if ($userId !== null) {
            $my = (int) $this->query->value(
                'SELECT COALESCE(amount, 0) FROM post_coins WHERE post_id = :post_id AND user_id = :user_id',
                ['post_id' => $postId, 'user_id' => $userId],
            );
        }

        return [
            'coin_count' => $count,
            'my_coins' => $my,
            'max' => self::MAX_PER_POST,
            'balance' => $userId !== null ? $this->wallet->balance($userId) : null,
        ];
    }
}
