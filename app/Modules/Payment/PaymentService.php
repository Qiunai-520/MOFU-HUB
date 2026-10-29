<?php

declare(strict_types=1);

namespace Mofu\Modules\Payment;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Growth\ShopService;
use Mofu\Modules\Membership\MembershipService;
use PDOException;

/**
 * 支付订单：创建 → 跳转易支付 → 异步通知验签 → 幂等发货。
 *
 * 订单号 out_trade_no 唯一；发货只做一次（status 由 pending 改 paid 的那次）。
 */
final class PaymentService
{
    public const KIND_MEMBERSHIP = 'membership';
    public const KIND_SHOP = 'shop';

    public function __construct(
        private readonly Query $query,
        private readonly EzfpClient $ezfp,
        private readonly MembershipService $memberships,
        private readonly ShopService $shop,
    ) {
    }

    public function configured(): bool
    {
        return $this->ezfp->configured();
    }

    public function client(): EzfpClient
    {
        return $this->ezfp;
    }

    /**
     * 新建待支付订单。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> 订单行
     */
    public function create(int $userId, string $kind, int $refId, string $subject, float $amountCny, array $payload = []): array
    {
        if ($amountCny <= 0) {
            throw HttpException::badRequest('订单金额无效');
        }

        if (!$this->ezfp->configured()) {
            throw HttpException::badRequest('支付未配置，请联系管理员');
        }

        $orderNo = 'MF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));

        $this->query->insert('payment_orders', [
            'order_no' => $orderNo,
            'user_id' => $userId,
            'kind' => $kind,
            'ref_id' => $refId,
            'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            'subject' => mb_substr($subject, 0, 120),
            'amount_cny' => $amountCny,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->findByOrderNo($orderNo) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function findByOrderNo(string $orderNo): ?array
    {
        return $this->query->one('SELECT * FROM payment_orders WHERE order_no = :no', ['no' => $orderNo]);
    }

    /**
     * 支付成功处理（notify 与 return 都会调；幂等）。
     *
     * @return array{ok: bool, delivered: bool, order: array<string, mixed>|null}
     */
    public function markPaid(string $orderNo, string $tradeNo, string $payType): array
    {
        $order = $this->findByOrderNo($orderNo);

        if ($order === null) {
            return ['ok' => false, 'delivered' => false, 'order' => null];
        }

        if ((string) $order['status'] === 'paid') {
            return ['ok' => true, 'delivered' => false, 'order' => $order];
        }

        $now = date('Y-m-d H:i:s');

        $updated = $this->query->run(
            "UPDATE payment_orders SET status = 'paid', ezfp_trade_no = :trade, pay_type = :type,
                    notified_at = :now, paid_at = :now
             WHERE order_no = :no AND status = 'pending'",
            ['trade' => mb_substr($tradeNo, 0, 64), 'type' => mb_substr($payType, 0, 16), 'now' => $now, 'no' => $orderNo],
        )->rowCount() > 0;

        if (!$updated) {
            // 并发下另一次通知已处理
            return ['ok' => true, 'delivered' => false, 'order' => $this->findByOrderNo($orderNo)];
        }

        $this->deliver($order);

        return ['ok' => true, 'delivered' => true, 'order' => $order];
    }

    /** @param array<string, mixed> $order */
    private function deliver(array $order): void
    {
        $payload = json_decode((string) ($order['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $userId = (int) $order['user_id'];
        $orderNo = (string) $order['order_no'];
        $amount = (float) $order['amount_cny'];

        try {
            if ((string) $order['kind'] === self::KIND_MEMBERSHIP) {
                $plan = (string) ($payload['plan'] ?? '');

                if ($plan !== '') {
                    $this->memberships->activate($userId, $plan, $amount, 'payment', $orderNo);
                }

                return;
            }

            if ((string) $order['kind'] === self::KIND_SHOP) {
                $this->shop->completeCnyOrder($orderNo);
            }
        } catch (PDOException) {
            // 发货失败不回滚支付状态：订单已 paid，由后台补发处理
        }
    }
}
