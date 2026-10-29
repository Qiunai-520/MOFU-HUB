<?php

declare(strict_types=1);

namespace Mofu\Modules\Membership;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Settings\SettingsService;

/**
 * 大会员订阅：月度 / 季度 / 年度。
 *
 * 订阅状态写在 users.membership（派生缓存）+ membership_plan + membership_expires_at，
 * 历史订单写 membership_orders。到期采用「访问时惰性降级」，不依赖定时任务。
 */
final class MembershipService
{
    public const SETTING_PRICES = 'membership.prices';

    /** @var array<string, array{label: string, months: int, days: int, default_price: float}> */
    public const PLANS = [
        'month' => ['label' => '月度大会员', 'months' => 1, 'days' => 30, 'default_price' => 9.90],
        'quarter' => ['label' => '季度大会员', 'months' => 3, 'days' => 90, 'default_price' => 25.90],
        'year' => ['label' => '年度大会员', 'months' => 12, 'days' => 365, 'default_price' => 88.00],
    ];

    public function __construct(
        private readonly Query $query,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * 可选套餐（含当前价格）。
     *
     * @return list<array<string, mixed>>
     */
    public function plans(): array
    {
        $prices = $this->prices();
        $out = [];

        foreach (self::PLANS as $key => $plan) {
            $out[] = [
                'key' => $key,
                'label' => $plan['label'],
                'months' => $plan['months'],
                'days' => $plan['days'],
                'price' => $prices[$key],
            ];
        }

        return $out;
    }

    /** @return array<string, float> */
    public function prices(): array
    {
        $stored = json_decode((string) ($this->settings->get(self::SETTING_PRICES, '') ?? ''), true);
        $stored = is_array($stored) ? $stored : [];

        $out = [];

        foreach (self::PLANS as $key => $plan) {
            $price = (float) ($stored[$key] ?? $plan['default_price']);
            $out[$key] = max(0.0, round($price, 2));
        }

        return $out;
    }

    /** @param array<string, mixed> $input */
    public function savePrices(array $input): void
    {
        $prices = [];

        foreach (self::PLANS as $key => $plan) {
            $prices[$key] = max(0.0, round((float) ($input[$key] ?? $plan['default_price']), 2));
        }

        $this->settings->set(self::SETTING_PRICES, (string) json_encode($prices, JSON_UNESCAPED_UNICODE));
    }

    /** 订阅状态（含到期惰性降级） */
    public function status(int $userId): array
    {
        $row = $this->query->one(
            'SELECT membership, membership_plan, membership_expires_at FROM users WHERE id = :id',
            ['id' => $userId],
        );

        $plan = (string) ($row['membership_plan'] ?? 'none');
        $expires = $row['membership_expires_at'] ?? null;
        $premium = (string) ($row['membership'] ?? 'normal') === 'premium';

        if ($premium && $expires !== null && strtotime((string) $expires) < time()) {
            $this->query->run(
                "UPDATE users SET membership = 'normal', membership_plan = 'none' WHERE id = :id",
                ['id' => $userId],
            );
            $premium = false;
            $plan = 'none';
            $expires = null;
        }

        return [
            'is_premium' => $premium,
            'plan' => $premium ? $plan : 'none',
            'plan_label' => $premium && isset(self::PLANS[$plan]) ? self::PLANS[$plan]['label'] : '',
            'expires_at' => $premium ? ($expires !== null ? (string) $expires : null) : null,
            'auto_renew' => false,
        ];
    }

    /**
     * 开通 / 续期（支付成功或管理员发放后调用）。
     * 续期从「当前到期时间」与「现在」的较晚者起算，避免提前续费吃亏。
     */
    public function activate(int $userId, string $plan, float $amountCny = 0.0, string $source = 'payment', string $orderNo = ''): array
    {
        if (!isset(self::PLANS[$plan])) {
            throw HttpException::badRequest('会员套餐无效');
        }

        $current = $this->query->one(
            'SELECT membership_plan, membership_expires_at FROM users WHERE id = :id',
            ['id' => $userId],
        );

        $base = time();

        if ($current !== null && ($current['membership_expires_at'] ?? null) !== null) {
            $existing = (int) strtotime((string) $current['membership_expires_at']);
            $base = max($base, $existing);
        }

        $expires = date('Y-m-d H:i:s', $base + self::PLANS[$plan]['days'] * 86400);
        $now = date('Y-m-d H:i:s');

        $this->query->run(
            "UPDATE users SET membership = 'premium', membership_plan = :plan, membership_expires_at = :expires WHERE id = :id",
            ['plan' => $plan, 'expires' => $expires, 'id' => $userId],
        );

        $this->query->insert('membership_orders', [
            'order_no' => $orderNo !== '' ? $orderNo : ('MB' . date('YmdHis') . bin2hex(random_bytes(3))),
            'user_id' => $userId,
            'plan' => $plan,
            'months' => self::PLANS[$plan]['months'],
            'amount_cny' => $amountCny,
            'status' => 'paid',
            'starts_at' => $now,
            'expires_at' => $expires,
            'created_at' => $now,
            'paid_at' => $now,
        ]);

        $this->notifications->push($userId, null, 'system', 'membership', $userId, [
            'message' => '你已成为' . self::PLANS[$plan]['label'] . '，有效期至 ' . substr($expires, 0, 10),
            'url' => '/membership',
        ]);

        return ['plan' => $plan, 'expires_at' => $expires];
    }
}
