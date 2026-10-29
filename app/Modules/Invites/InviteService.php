<?php

declare(strict_types=1);

namespace Mofu\Modules\Invites;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Growth\WalletService;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 邀请码：Lv.5 每月可花 50 M币买 1 个、Lv.6 每月 2 个；
 * 被邀请人使用后**免答题直接成为正式会员（Lv.1）**。
 */
final class InviteService
{
    public const PRICE_MOFU = 50.0;
    public const QUOTA_LV5 = 1;
    public const QUOTA_LV6 = 2;
    public const VALID_DAYS = 30;

    /** 去掉易混字符的字母表（0/O、1/I/l 等） */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
        private readonly LevelService $levels,
        private readonly NotificationService $notifications,
    ) {
    }

    /** 本月可购买的邀请码数量（按等级） */
    public function monthlyQuota(int $userId): int
    {
        $level = (int) ($this->levels->stats($userId)['level'] ?? 0);

        if ($level >= 6) {
            return self::QUOTA_LV6;
        }

        if ($level >= 5) {
            return self::QUOTA_LV5;
        }

        return 0;
    }

    public function purchasedThisMonth(int $userId): int
    {
        return (int) $this->query->value(
            'SELECT COUNT(*) FROM invite_codes WHERE owner_id = :id AND created_at >= :start',
            ['id' => $userId, 'start' => date('Y-m-01 00:00:00')],
        );
    }

    public function remaining(int $userId): int
    {
        return max(0, $this->monthlyQuota($userId) - $this->purchasedThisMonth($userId));
    }

    /** @return list<array<string, mixed>> */
    public function mine(int $userId): array
    {
        $rows = $this->query->all(
            'SELECT * FROM invite_codes WHERE owner_id = :id ORDER BY id DESC LIMIT 50',
            ['id' => $userId],
        );

        return array_map($this->present(...), $rows);
    }

    /** 购买一个邀请码（扣 50 M币） */
    public function purchase(int $userId): array
    {
        if ($this->remaining($userId) <= 0) {
            throw HttpException::forbidden('本月邀请码额度已用完（Lv.5 每月 1 个、Lv.6 每月 2 个）');
        }

        if ($this->wallet->balance($userId) < self::PRICE_MOFU) {
            throw HttpException::badRequest('M币不足，购买邀请码需要 ' . self::PRICE_MOFU . ' M币');
        }

        $code = $this->generateCode();
        $expires = date('Y-m-d H:i:s', time() + self::VALID_DAYS * 86400);

        $this->wallet->debit($userId, self::PRICE_MOFU, 'shop', '购买邀请码', $code, 'invite-buy:' . $userId . ':' . $code);

        $this->query->insert('invite_codes', [
            'code' => $code,
            'owner_id' => $userId,
            'expires_at' => $expires,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return ['code' => $code, 'expires_at' => $expires];
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        return $this->query->one('SELECT * FROM invite_codes WHERE code = :code', ['code' => $code]);
    }

    /** @return array{ok: bool, error: string} */
    public function validate(string $code): array
    {
        $row = $this->find($code);

        if ($row === null) {
            return ['ok' => false, 'error' => '邀请码不存在'];
        }

        if ($row['used_by'] !== null) {
            return ['ok' => false, 'error' => '邀请码已被使用'];
        }

        if ((string) $row['expires_at'] < date('Y-m-d H:i:s')) {
            return ['ok' => false, 'error' => '邀请码已过期'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * 使用邀请码：标记已用并把被邀请人转为正式会员。
     *
     * @return array{ok: bool, error: string}
     */
    public function redeem(string $code, int $usedBy): array
    {
        $check = $this->validate($code);

        if (!$check['ok']) {
            return $check;
        }

        $row = $this->find($code);

        if ($row === null) {
            return ['ok' => false, 'error' => '邀请码不存在'];
        }

        $updated = $this->query->run(
            'UPDATE invite_codes SET used_by = :used_by, used_at = :now WHERE id = :id AND used_by IS NULL',
            ['used_by' => $usedBy, 'now' => date('Y-m-d H:i:s'), 'id' => (int) $row['id']],
        )->rowCount() > 0;

        if (!$updated) {
            return ['ok' => false, 'error' => '邀请码已被使用'];
        }

        $this->levels->markFormal($usedBy);

        $this->notifications->push((int) $row['owner_id'], $usedBy, 'system', 'invite', (int) $row['id'], [
            'message' => '你的邀请码已被使用，对方已成为正式会员',
            'url' => '/invites',
        ]);

        return ['ok' => true, 'error' => ''];
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        $used = $row['used_by'] !== null;

        return [
            'code' => (string) $row['code'],
            'used' => $used,
            'used_at' => $used && $row['used_at'] !== null ? (string) $row['used_at'] : null,
            'expired' => !$used && (string) $row['expires_at'] < date('Y-m-d H:i:s'),
            'expires_at' => (string) $row['expires_at'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private function generateCode(): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = 'MF';
            $raw = random_bytes(8);

            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[ord($raw[$i]) % ($max + 1)];
            }

            if ($this->find($code) === null) {
                return $code;
            }
        }

        throw HttpException::badRequest('邀请码生成失败，请稍后再试');
    }
}
