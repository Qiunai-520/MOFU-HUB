<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Growth\WalletService;
use Mofu\Modules\Notifications\NotificationService;
use PDOException;

/**
 * 付费动态：发布者设定价格（1 ~ 15 M币），购买一次永久解锁，作者全额到手。
 *
 * 未购买者只看得到摘要；作者本人与管理员始终可见。
 */
final class PaidPostService
{
    public const MIN_PRICE = 1.0;
    public const MAX_PRICE = 15.0;

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {
    }

    /** 把价格规整到允许区间；未开启付费返回 0 */
    public static function normalizePrice(mixed $raw): float
    {
        $price = (float) $raw;

        return max(self::MIN_PRICE, min(self::MAX_PRICE, round($price, 2)));
    }

    /**
     * 批量查「该用户是否已购买这些动态」。
     *
     * @param list<int> $postIds
     * @return array<int, true>
     */
    public function purchasedMap(array $postIds, ?int $userId): array
    {
        if ($userId === null || $postIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
        $rows = $this->query->all(
            sprintf('SELECT post_id FROM post_purchases WHERE user_id = ? AND post_id IN (%s)', $placeholders),
            [$userId, ...$postIds],
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['post_id']] = true;
        }

        return $out;
    }

    public function hasPurchased(int $userId, int $postId): bool
    {
        return $this->query->value(
            'SELECT id FROM post_purchases WHERE user_id = :user_id AND post_id = :post_id',
            ['user_id' => $userId, 'post_id' => $postId],
        ) !== null;
    }

    /**
     * 购买解锁：扣买家、全额给作者、写购买记录（唯一键保证只买一次）。
     *
     * @return array{price: float, balance: float}
     */
    public function purchase(int $userId, int $postId): array
    {
        $post = $this->query->one(
            'SELECT id, user_id, is_paid, price FROM posts WHERE id = :id AND deleted_at IS NULL',
            ['id' => $postId],
        );

        if ($post === null || (int) $post['is_paid'] !== 1) {
            throw HttpException::notFound('这不是付费内容');
        }

        $ownerId = (int) $post['user_id'];

        if ($ownerId === $userId) {
            throw HttpException::badRequest('这是你自己的动态');
        }

        if ($this->hasPurchased($userId, $postId)) {
            throw HttpException::badRequest('你已经购买过这条动态了');
        }

        $price = (float) $post['price'];

        if ($price <= 0) {
            throw HttpException::badRequest('价格有误，请联系管理员');
        }

        if ($this->wallet->balance($userId) < $price) {
            throw HttpException::badRequest('M币余额不足');
        }

        $this->wallet->debit($userId, $price, 'shop', '购买付费动态 #' . $postId, 'post:' . $postId);

        try {
            $this->query->insert('post_purchases', [
                'post_id' => $postId,
                'user_id' => $userId,
                'amount' => $price,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            // 并发下唯一键冲突：对方已经买过，退回本次扣款
            $this->wallet->credit($userId, $price, 'shop', '重复购买退回：动态 #' . $postId, 'post:' . $postId);

            throw HttpException::badRequest('你已经购买过这条动态了');
        }

        $this->wallet->credit($ownerId, $price, 'shop', '付费动态收入 #' . $postId, 'post:' . $postId);

        $this->notifications->push($ownerId, $userId, 'post_purchased', 'post', $postId, [
            'message' => sprintf('有人花 %s M币解锁了你的付费动态', rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.')),
            'url' => '/posts/' . $postId,
        ]);

        return [
            'price' => $price,
            'balance' => $this->wallet->balance($userId),
        ];
    }
}
