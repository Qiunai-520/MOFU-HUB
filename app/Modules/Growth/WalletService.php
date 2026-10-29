<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\AuditLogger;

/**
 * 站内货币（Mofu）钱包与交易流水。
 *
 * 所有余额变化都必须经过 credit/debit/adjust：在同一个数据库事务里锁定钱包行、
 * 写入流水并更新余额，避免并发下的数据不同步或负余额。
 * 带 idempotencyKey 的操作重复提交只会入账一次。
 */
final class WalletService
{
    public const SOURCE_LABELS = [
        'checkin' => '每日签到',
        'achievement' => '成就奖励',
        'level' => '等级奖励',
        'shop' => '小商店消费',
        'coin' => '投币支持',
        'task' => '每日任务',
        'task_once' => '一次性任务',
        'task_premium' => '大会员加成',
        'admin' => '管理员调整',
        'event' => '活动奖励',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string, mixed> 确保钱包存在并返回 */
    public function wallet(int $userId): array
    {
        $wallet = $this->query->one('SELECT * FROM user_wallets WHERE user_id = :user_id', ['user_id' => $userId]);

        if ($wallet !== null) {
            return $wallet;
        }

        $this->query->run(
            'INSERT INTO user_wallets (user_id, balance, total_earned, total_spent, updated_at)
             VALUES (:user_id, 0, 0, 0, :now)
             ON DUPLICATE KEY UPDATE user_id = user_id',
            ['user_id' => $userId, 'now' => date('Y-m-d H:i:s')],
        );

        return [
            'user_id' => $userId,
            'balance' => 0,
            'total_earned' => 0,
            'total_spent' => 0,
        ];
    }

    public function balance(int $userId): float
    {
        return (float) $this->wallet($userId)['balance'];
    }

    /** 入账（幂等）。返回入账后的余额。 */
    public function credit(
        int $userId,
        float $amount,
        string $source,
        string $description = '',
        ?string $referenceId = null,
        ?string $idempotencyKey = null,
    ): float {
        if ($amount <= 0) {
            throw HttpException::badRequest('入账金额必须为正数');
        }

        return $this->apply($userId, $amount, 'earn', $source, $description, $referenceId, $idempotencyKey);
    }

    /** 出账（幂等）。余额不足抛错。返回扣款后的余额。 */
    public function debit(
        int $userId,
        float $amount,
        string $source,
        string $description = '',
        ?string $referenceId = null,
        ?string $idempotencyKey = null,
    ): float {
        if ($amount <= 0) {
            throw HttpException::badRequest('扣款金额必须为正数');
        }

        return $this->apply($userId, $amount, 'spend', $source, $description, $referenceId, $idempotencyKey, spending: true);
    }

    /**
     * 管理员调整：$amount 可正可负，必须记录审计。
     */
    public function adjust(int $userId, float $amount, string $description, int $actorId): float
    {
        if ($amount === 0) {
            throw HttpException::badRequest('调整金额不能为 0');
        }

        $balance = $amount > 0
            ? $this->apply($userId, $amount, 'adjust', 'admin', $description, null, null, allowOverdraft: true)
            : $this->apply($userId, -$amount, 'adjust', 'admin', $description, null, null, allowOverdraft: true, spending: true);

        $this->audit->log($actorId, 'economy.adjust', 'user', $userId, ['amount' => $amount, 'description' => $description]);

        return $balance;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function history(int $userId, int $page = 1, int $perPage = 20): array
    {
        $result = $this->query->paginate(
            'SELECT * FROM wallet_transactions WHERE user_id = :user_id ORDER BY id DESC',
            'SELECT COUNT(*) FROM wallet_transactions WHERE user_id = :user_id',
            ['user_id' => $userId],
            $page,
            $perPage,
        );

        $result['items'] = array_map($this->presentTransaction(...), $result['items']);

        return $result;
    }

    /** 经济系统总览（管理员） */
    public function overview(): array
    {
        return [
            'issued' => (float) $this->query->value("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE type IN ('earn','adjust') AND amount > 0"),
            'spent' => (float) $this->query->value("SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE type = 'spend'"),
            'circulating' => (float) $this->query->value('SELECT COALESCE(SUM(balance), 0) FROM user_wallets'),
            'wallets' => (int) $this->query->value('SELECT COUNT(*) FROM user_wallets'),
            'today_issued' => (float) $this->query->value(
                "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE type IN ('earn','adjust') AND amount > 0 AND created_at >= :today",
                ['today' => date('Y-m-d 00:00:00')],
            ),
            'today_spent' => (float) $this->query->value(
                "SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE type = 'spend' AND created_at >= :today",
                ['today' => date('Y-m-d 00:00:00')],
            ),
            'top' => $this->query->all(
                'SELECT w.user_id, w.balance, u.username, COALESCE(NULLIF(p.nickname, \'\'), u.username) AS nickname
                 FROM user_wallets w
                 JOIN users u ON u.id = w.user_id
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 ORDER BY w.balance DESC
                 LIMIT 10',
            ),
        ];
    }

    private function apply(
        int $userId,
        float $amount,
        string $type,
        string $source,
        string $description,
        ?string $referenceId,
        ?string $idempotencyKey,
        bool $allowOverdraft = false,
        bool $spending = false,
    ): float {
        return (float) $this->query->transaction(function (Query $q) use (
            $userId,
            $amount,
            $type,
            $source,
            $description,
            $referenceId,
            $idempotencyKey,
            $allowOverdraft,
            $spending,
        ): float {
            if ($idempotencyKey !== null) {
                $existing = $q->value(
                    'SELECT balance_after FROM wallet_transactions WHERE idempotency_key = :key',
                    ['key' => $idempotencyKey],
                );

                if ($existing !== null) {
                    return (int) $existing;
                }
            }

            // 并发首次入账时两个请求都看不到行，用 INSERT ... ON DUPLICATE 兜底后再取行锁
            $q->run(
                'INSERT INTO user_wallets (user_id, balance, total_earned, total_spent, updated_at)
                 VALUES (:user_id, 0, 0, 0, :now)
                 ON DUPLICATE KEY UPDATE user_id = user_id',
                ['user_id' => $userId, 'now' => date('Y-m-d H:i:s')],
            );

            $wallet = $q->one('SELECT * FROM user_wallets WHERE user_id = :user_id FOR UPDATE', ['user_id' => $userId]);

            $before = (float) ($wallet['balance'] ?? 0);
            $earned = (float) ($wallet['total_earned'] ?? 0);
            $spent = (float) ($wallet['total_spent'] ?? 0);

            $after = $spending ? $before - $amount : $before + $amount;

            if ($after < 0 && !$allowOverdraft) {
                throw HttpException::badRequest('余额不足');
            }

            if ($after < 0) {
                $after = 0;
            }

            if ($spending) {
                $spent += $amount;
            } elseif ($type !== 'adjust') {
                $earned += $amount;
            } elseif ($amount > 0) {
                $earned += $amount;
            } else {
                $spent += $amount;
            }

            $q->update('user_wallets', [
                'balance' => $after,
                'total_earned' => $earned,
                'total_spent' => $spent,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :user_id', ['user_id' => $userId]);

            $q->insert('wallet_transactions', [
                'user_id' => $userId,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'source' => $source,
                'reference_id' => $referenceId,
                'description' => mb_substr($description, 0, 200),
                'idempotency_key' => $idempotencyKey,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $after;
        });
    }

    /** @param array<string, mixed> $row */
    private function presentTransaction(array $row): array
    {
        $type = (string) $row['type'];
        $amount = (float) $row['amount'];

        return [
            'id' => (int) $row['id'],
            'type' => $type,
            'amount' => $amount,
            'signed' => $type === 'spend' ? -$amount : $amount,
            'balance_after' => (float) $row['balance_after'],
            'source' => (string) $row['source'],
            'source_label' => self::SOURCE_LABELS[(string) $row['source']] ?? '其他变动',
            'description' => (string) $row['description'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
