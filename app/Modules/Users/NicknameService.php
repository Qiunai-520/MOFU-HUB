<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Growth\WalletService;

/**
 * 昵称修改：首次免费，之后每次 6 M币，且两次修改间隔不少于 3 天。
 */
final class NicknameService
{
    public const PRICE_MOFU = 6.0;
    public const INTERVAL_DAYS = 3;

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
    ) {
    }

    /**
     * @return array{can_change: bool, cost: float, free: bool, next_at: string|null, changed_count: int, reason: string}
     */
    public function status(int $userId): array
    {
        $row = $this->query->one(
            'SELECT nickname_changed_at, nickname_change_count FROM user_profiles WHERE user_id = :id',
            ['id' => $userId],
        );

        $count = (int) ($row['nickname_change_count'] ?? 0);
        $last = $row['nickname_changed_at'] ?? null;
        $free = $count === 0;
        $cost = $free ? 0.0 : self::PRICE_MOFU;

        $nextAt = null;
        $reason = '';

        if ($last !== null) {
            $next = (int) strtotime((string) $last) + self::INTERVAL_DAYS * 86400;

            if ($next > time()) {
                $nextAt = date('Y-m-d H:i:s', $next);
                $reason = '距上次修改不足 ' . self::INTERVAL_DAYS . ' 天，' . date('m-d H:i', $next) . ' 后可再次修改';
            }
        }

        if ($reason === '' && !$free && $this->wallet->balance($userId) < $cost) {
            $reason = 'M币不足，修改昵称需要 ' . $cost . ' M币';
        }

        return [
            'can_change' => $reason === '',
            'cost' => $cost,
            'free' => $free,
            'next_at' => $nextAt,
            'changed_count' => $count,
            'reason' => $reason,
        ];
    }

    /**
     * 修改昵称（内部会做间隔与余额校验）。昵称未变化时不产生任何副作用。
     *
     * @return array{cost: float, changed: bool}
     */
    public function change(int $userId, string $nickname): array
    {
        $nickname = trim($nickname);

        $old = (string) ($this->query->value(
            'SELECT nickname FROM user_profiles WHERE user_id = :id',
            ['id' => $userId],
        ) ?? '');

        if ($old === $nickname) {
            return ['cost' => 0.0, 'changed' => false];
        }

        $status = $this->status($userId);

        if (!$status['can_change']) {
            throw HttpException::badRequest($status['reason']);
        }

        $cost = $status['cost'];

        if ($cost > 0) {
            $this->wallet->debit($userId, $cost, 'shop', '修改昵称');
        }

        $now = date('Y-m-d H:i:s');

        $this->query->run(
            'UPDATE user_profiles
             SET nickname = :nickname, nickname_changed_at = :now, nickname_change_count = nickname_change_count + 1, updated_at = :now2
             WHERE user_id = :id',
            ['nickname' => mb_substr($nickname, 0, 24), 'now' => $now, 'now2' => $now, 'id' => $userId],
        );

        $this->query->insert('nickname_change_log', [
            'user_id' => $userId,
            'old_nickname' => mb_substr($old, 0, 48),
            'new_nickname' => mb_substr($nickname, 0, 48),
            'cost' => $cost,
            'created_at' => $now,
        ]);

        return ['cost' => $cost, 'changed' => true];
    }
}
