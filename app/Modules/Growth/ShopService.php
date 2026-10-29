<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\AuditLogger;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Users\ProfileDecorations;

/**
 * 站内积分小店（虚拟物品，不涉及真实货币）。
 *
 * 购买流程：校验商品 → 扣款（独立事务，带订单号幂等）→ 事务内扣库存/建订单/发货。
 * 发货失败会原路退回，避免出现「扣了钱没拿到东西」。
 */
final class ShopService
{
    public const TYPE_LABELS = [
        'title' => '称号',
        'avatar_frame' => '头像框',
        'badge' => '徽章',
        'theme' => '主题',
        'background' => '背景',
        'checkin_boost' => '签到加成',
        'decoration' => '主页装饰',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly WalletService $wallet,
        private readonly TitleService $titles,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(bool $activeOnly = true, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM shop_products';

        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }

        $sql .= ' ORDER BY sort ASC, id ASC';

        $rows = $this->query->all($sql);
        $owned = [];

        if ($userId !== null) {
            foreach ($this->query->all('SELECT product_id, quantity FROM user_inventory WHERE user_id = :user_id', ['user_id' => $userId]) as $row) {
                $owned[(int) $row['product_id']] = (int) $row['quantity'];
            }
        }

        return array_map(function (array $row) use ($owned, $userId): array {
            $id = (int) $row['id'];
            $sold = (int) $this->query->value('SELECT COUNT(*) FROM shop_orders WHERE product_id = :id AND status = \'paid\'', ['id' => $id]);

            return $this->presentProduct($row) + [
                'my_owned' => $owned[$id] ?? 0,
                'sold' => $sold,
                'affordable' => $userId !== null && $this->wallet->balance($userId) >= (int) $row['price'],
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function purchase(int $userId, int $productId): array
    {
        $product = $this->query->one('SELECT * FROM shop_products WHERE id = :id', ['id' => $productId]);

        if ($product === null) {
            throw HttpException::notFound('商品不存在');
        }

        if ((string) ($product['pay_mode'] ?? 'mofu') === 'cny') {
            throw HttpException::badRequest('该商品需通过在线支付购买');
        }

        $this->assertPurchasable($userId, $product);

        $price = (int) $product['price'];
        $orderNo = 'MF' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));

        if ($price > 0) {
            $this->wallet->debit($userId, $price, 'shop', '购买：' . (string) $product['name'], $orderNo, 'shop-order:' . $orderNo);
        }

        try {
            $this->query->transaction(function (Query $q) use ($userId, $product, $price, $orderNo): void {
                if ($product['stock'] !== null) {
                    $affected = $q->run(
                        'UPDATE shop_products SET stock = stock - 1 WHERE id = :id AND stock > 0',
                        ['id' => (int) $product['id']],
                    )->rowCount();

                    if ($affected === 0) {
                        throw HttpException::badRequest('商品已售罄');
                    }
                }

                $q->insert('shop_orders', [
                    'order_no' => $orderNo,
                    'user_id' => $userId,
                    'product_id' => (int) $product['id'],
                    'price' => $price,
                    'quantity' => 1,
                    'status' => 'paid',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                $q->run(
                    'INSERT INTO user_inventory (user_id, product_id, quantity, acquired_at, updated_at)
                     VALUES (:user_id, :product_id, 1, :now_insert, :now_update)
                     ON DUPLICATE KEY UPDATE quantity = quantity + 1, updated_at = VALUES(updated_at)',
                    [
                        'user_id' => $userId,
                        'product_id' => (int) $product['id'],
                        'now_insert' => date('Y-m-d H:i:s'),
                        'now_update' => date('Y-m-d H:i:s'),
                    ],
                );
            });

            // 发货失败也要退款：原先 deliver 在 catch 之外，失败会扣款、建单却不退
            $this->deliver($userId, $product);
        } catch (\Throwable $e) {
            if ($price > 0) {
                $this->wallet->credit($userId, $price, 'shop', '购买失败退款：' . (string) $product['name'], $orderNo, 'shop-refund:' . $orderNo);
            }

            throw $e;
        }

        $this->notifications->push($userId, null, 'system', 'product', (int) $product['id'], [
            'message' => sprintf('购买成功：%s', (string) $product['name']),
            'url' => '/me/growth',
        ]);

        return ['order_no' => $orderNo, 'product' => $this->presentProduct($product), 'balance' => $this->wallet->balance($userId)];
    }

    /** @return list<array<string, mixed>> */
    public function inventory(int $userId): array
    {
        $rows = $this->query->all(
            'SELECT i.quantity, i.acquired_at, p.* FROM user_inventory i
             JOIN shop_products p ON p.id = i.product_id
             WHERE i.user_id = :user_id ORDER BY i.id DESC',
            ['user_id' => $userId],
        );

        return array_map(
            static fn (array $row): array => [
                'product_id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'icon' => (string) $row['icon'],
                'type' => (string) $row['type'],
                'type_label' => self::TYPE_LABELS[(string) $row['type']] ?? (string) $row['type'],
                'quantity' => (int) $row['quantity'],
                'acquired_at' => (string) $row['acquired_at'],
            ],
            $rows,
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function orders(int $page = 1, int $perPage = 30, ?int $userId = null): array
    {
        $where = '1 = 1';
        $params = [];

        if ($userId !== null) {
            $where .= ' AND o.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql = 'SELECT o.*, p.name AS product_name, p.icon, p.type, u.username
                FROM shop_orders o
                JOIN shop_products p ON p.id = o.product_id
                JOIN users u ON u.id = o.user_id
                WHERE ' . $where . ' ORDER BY o.id DESC';
        $countSql = 'SELECT COUNT(*) FROM shop_orders o WHERE ' . $where;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);

        $result['items'] = array_map(
            static fn (array $row): array => [
                'order_no' => (string) $row['order_no'],
                'product_name' => (string) $row['product_name'],
                'icon' => (string) $row['icon'],
                'type' => (string) $row['type'],
                'price' => (int) $row['price'],
                'status' => (string) $row['status'],
                'username' => (string) $row['username'],
                'created_at' => (string) $row['created_at'],
            ],
            $result['items'],
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function salesSummary(): array
    {
        return [
            'orders' => (int) $this->query->value("SELECT COUNT(*) FROM shop_orders WHERE status = 'paid'"),
            'revenue' => (int) $this->query->value("SELECT COALESCE(SUM(price), 0) FROM shop_orders WHERE status = 'paid'"),
            'today_orders' => (int) $this->query->value(
                "SELECT COUNT(*) FROM shop_orders WHERE status = 'paid' AND created_at >= :today",
                ['today' => date('Y-m-d 00:00:00')],
            ),
            'best' => $this->query->all(
                "SELECT p.name, COUNT(o.id) AS sold, COALESCE(SUM(o.price), 0) AS revenue
                 FROM shop_orders o JOIN shop_products p ON p.id = o.product_id
                 WHERE o.status = 'paid' GROUP BY p.id ORDER BY sold DESC LIMIT 5",
            ),
        ];
    }

    /** 管理员补发（不扣款，但记录审计） */
    public function grantProduct(int $userId, int $productId, int $actorId): void
    {
        $product = $this->query->one('SELECT * FROM shop_products WHERE id = :id', ['id' => $productId]);

        if ($product === null) {
            throw HttpException::notFound('商品不存在');
        }

        $this->query->run(
            'INSERT INTO user_inventory (user_id, product_id, quantity, acquired_at, updated_at)
             VALUES (:user_id, :product_id, 1, :now_insert, :now_update)
             ON DUPLICATE KEY UPDATE quantity = quantity + 1, updated_at = VALUES(updated_at)',
            [
                'user_id' => $userId,
                'product_id' => $productId,
                'now_insert' => date('Y-m-d H:i:s'),
                'now_update' => date('Y-m-d H:i:s'),
            ],
        );

        $this->deliver($userId, $product);
        $this->audit->log($actorId, 'shop.grant', 'user', $userId, ['product_id' => $productId]);

        $this->notifications->push($userId, null, 'system', 'product', $productId, [
            'message' => sprintf('你收到一件物品：%s', (string) $product['name']),
            'url' => '/me/growth',
        ]);
    }

    /** @param array<string, mixed> $data */
    public function saveProduct(?int $id, array $data, int $actorId): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw HttpException::badRequest('请填写商品名称');
        }

        $payload = [
            'name' => mb_substr($name, 0, 64),
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'icon' => mb_substr(trim((string) ($data['icon'] ?? 'redeem')), 0, 64),
            'image' => mb_substr(trim((string) ($data['image'] ?? '')), 0, 255),
            'price' => max(0, (int) ($data['price'] ?? 0)),
            'pay_mode' => ($data['pay_mode'] ?? 'mofu') === 'cny' ? 'cny' : 'mofu',
            'cny_price' => max(0.0, round((float) ($data['cny_price'] ?? 0), 2)),
            'delivery' => ($data['delivery'] ?? 'auto') === 'manual' ? 'manual' : 'auto',
            'type' => array_key_exists((string) ($data['type'] ?? ''), self::TYPE_LABELS) ? (string) $data['type'] : 'badge',
            'stock' => ($data['stock'] ?? '') === '' || $data['stock'] === null ? null : max(0, (int) $data['stock']),
            'active' => !empty($data['active']) ? 1 : 0,
            'per_user_limit' => ($data['per_user_limit'] ?? '') === '' || $data['per_user_limit'] === null ? null : max(1, (int) $data['per_user_limit']),
            'starts_at' => self::nullableDate($data['starts_at'] ?? null),
            'ends_at' => self::nullableDate($data['ends_at'] ?? null),
            'payload' => isset($data['payload']) && is_array($data['payload']) ? (string) json_encode($data['payload'], JSON_UNESCAPED_UNICODE) : null,
            'sort' => (int) ($data['sort'] ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($payload['type'] === 'title' && $payload['payload'] === null && isset($data['title_slug'])) {
            $payload['payload'] = (string) json_encode(['title_slug' => (string) $data['title_slug']], JSON_UNESCAPED_UNICODE);
        }

        if ($payload['type'] === 'title' && $payload['payload'] === null && $id !== null && $id > 0) {
            $existingPayload = $this->query->value('SELECT payload FROM shop_products WHERE id = :id', ['id' => $id]);

            if (is_string($existingPayload) && $existingPayload !== '') {
                $payload['payload'] = $existingPayload;
            }
        }

        if ($payload['type'] === 'avatar_frame') {
            $frame = trim((string) ($data['frame_key'] ?? ''));

            if ($frame === '' || !isset(ProfileDecorations::FRAMES[$frame])) {
                throw HttpException::badRequest('请填写有效的头像框标识');
            }

            $payload['payload'] = (string) json_encode(['frame' => $frame], JSON_UNESCAPED_UNICODE);
        }

        if ($payload['type'] === 'badge') {
            $badge = trim((string) ($data['badge_key'] ?? ''));

            if ($badge === '' || !isset(ProfileDecorations::BADGES[$badge])) {
                throw HttpException::badRequest('请填写有效的昵称挂件标识');
            }

            $payload['payload'] = (string) json_encode(['badge' => $badge], JSON_UNESCAPED_UNICODE);
        }

        if ($id !== null && $id > 0) {
            $this->query->update('shop_products', $payload, 'id = :id', ['id' => $id]);
            $this->audit->log($actorId, 'shop.update', 'product', $id, ['name' => $name]);

            return $id;
        }

        $slug = trim((string) ($data['slug'] ?? ''));

        if ($slug === '') {
            $slug = 'item-' . bin2hex(random_bytes(4));
        }

        $newId = $this->query->insert('shop_products', $payload + [
            'slug' => preg_replace('/[^a-z0-9\-]/', '', mb_strtolower($slug)) ?: ('item-' . bin2hex(random_bytes(4))),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log($actorId, 'shop.create', 'product', $newId, ['name' => $name]);

        return $newId;
    }

    public function toggle(int $id, bool $active, int $actorId): void
    {
        $this->query->update('shop_products', ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
        $this->audit->log($actorId, $active ? 'shop.enable' : 'shop.disable', 'product', $id, []);
    }

    public function delete(int $id, int $actorId): void
    {
        $this->query->delete('shop_products', 'id = :id', ['id' => $id]);
        $this->audit->log($actorId, 'shop.delete', 'product', $id, []);
    }

    private function assertPurchasable(int $userId, array $product, bool $checkBalance = true): void
    {
        if ((int) $product['active'] !== 1) {
            throw HttpException::badRequest('商品已下架');
        }

        $now = date('Y-m-d H:i:s');

        if ($product['starts_at'] !== null && (string) $product['starts_at'] > $now) {
            throw HttpException::badRequest('商品尚未开售');
        }

        if ($product['ends_at'] !== null && (string) $product['ends_at'] < $now) {
            throw HttpException::badRequest('商品已下架');
        }

        if ($product['stock'] !== null && (int) $product['stock'] <= 0) {
            throw HttpException::badRequest('商品已售罄');
        }

        if ($product['per_user_limit'] !== null) {
            $owned = (int) $this->query->value(
                'SELECT COALESCE(SUM(quantity), 0) FROM user_inventory WHERE user_id = :user_id AND product_id = :product_id',
                ['user_id' => $userId, 'product_id' => (int) $product['id']],
            );

            if ($owned >= (int) $product['per_user_limit']) {
                throw HttpException::badRequest('已达到该商品的购买上限');
            }
        }

        if ($checkBalance && $this->wallet->balance($userId) < (int) $product['price']) {
            throw HttpException::badRequest('M币余额不足');
        }
    }

    /**
     * 真实货币商品：校验并返回商品与应付金额（不校验 M币 余额）。
     *
     * @return array{product: array<string, mixed>, amount: float}
     */
    public function beginCnyOrder(int $userId, int $productId): array
    {
        $product = $this->query->one('SELECT * FROM shop_products WHERE id = :id', ['id' => $productId]);

        if ($product === null) {
            throw HttpException::notFound('商品不存在');
        }

        if ((string) ($product['pay_mode'] ?? 'mofu') !== 'cny') {
            throw HttpException::badRequest('该商品不支持在线支付');
        }

        $this->assertPurchasable($userId, $product, false);

        $price = (float) $product['cny_price'];

        if ($price <= 0) {
            throw HttpException::badRequest('商品价格无效');
        }

        return ['product' => $this->presentProduct($product), 'amount' => $price];
    }

    /** 支付下单后写入待支付的小店订单 */
    public function attachCnyOrder(int $userId, int $productId, string $paymentOrderNo): void
    {
        $this->query->insert('shop_orders', [
            'order_no' => 'S' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4))),
            'user_id' => $userId,
            'product_id' => $productId,
            'price' => 0,
            'channel' => 'cny',
            'payment_order_no' => $paymentOrderNo,
            'quantity' => 1,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 支付成功后发货：仅「pending → paid」那一次生效（幂等） */
    public function completeCnyOrder(string $paymentOrderNo): void
    {
        $order = $this->query->one('SELECT * FROM shop_orders WHERE payment_order_no = :no', ['no' => $paymentOrderNo]);

        if ($order === null) {
            return;
        }

        $updated = $this->query->run(
            "UPDATE shop_orders SET status = 'paid' WHERE id = :id AND status = 'pending'",
            ['id' => (int) $order['id']],
        )->rowCount() > 0;

        if (!$updated) {
            return;
        }

        $product = $this->query->one('SELECT * FROM shop_products WHERE id = :id', ['id' => (int) $order['product_id']]);

        if ($product === null) {
            return;
        }

        $userId = (int) $order['user_id'];

        $this->query->transaction(function (Query $q) use ($userId, $product): void {
            if ($product['stock'] !== null) {
                $q->run('UPDATE shop_products SET stock = GREATEST(0, stock - 1) WHERE id = :id', ['id' => (int) $product['id']]);
            }

            $q->run(
                'INSERT INTO user_inventory (user_id, product_id, quantity, acquired_at, updated_at)
                 VALUES (:user_id, :product_id, 1, :now_insert, :now_update)
                 ON DUPLICATE KEY UPDATE quantity = quantity + 1, updated_at = VALUES(updated_at)',
                [
                    'user_id' => $userId,
                    'product_id' => (int) $product['id'],
                    'now_insert' => date('Y-m-d H:i:s'),
                    'now_update' => date('Y-m-d H:i:s'),
                ],
            );
        });

        // 手动发货的商品只进后台待处理，自动发货的立即执行
        if ((string) ($product['delivery'] ?? 'auto') === 'auto') {
            $this->deliver($userId, $product);
        }

        $this->notifications->push($userId, null, 'system', 'product', (int) $product['id'], [
            'message' => sprintf('购买成功：%s', (string) $product['name']),
            'url' => '/shop',
        ]);
    }

    /** 发货：称号类商品直接授予称号，其余进入收藏 */
    private function deliver(int $userId, array $product): void
    {
        if ((string) $product['type'] === 'title') {
            $payload = $product['payload'] !== null ? (json_decode((string) $product['payload'], true) ?: []) : [];
            $slug = is_array($payload) ? (string) ($payload['title_slug'] ?? '') : '';

            if ($slug !== '') {
                $this->titles->grantBySlug($userId, $slug, 'shop');
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function presentProduct(array $row): array
    {
        $now = date('Y-m-d H:i:s');

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'icon' => (string) $row['icon'],
            'image' => (string) $row['image'],
            'price' => (int) $row['price'],
            'pay_mode' => (string) ($row['pay_mode'] ?? 'mofu'),
            'cny_price' => (float) ($row['cny_price'] ?? 0),
            'delivery' => (string) ($row['delivery'] ?? 'auto'),
            'type' => (string) $row['type'],
            'type_label' => self::TYPE_LABELS[(string) $row['type']] ?? (string) $row['type'],
            'stock' => $row['stock'] !== null ? (int) $row['stock'] : null,
            'active' => (int) $row['active'] === 1,
            'per_user_limit' => $row['per_user_limit'] !== null ? (int) $row['per_user_limit'] : null,
            'starts_at' => $row['starts_at'] !== null ? (string) $row['starts_at'] : null,
            'ends_at' => $row['ends_at'] !== null ? (string) $row['ends_at'] : null,
            'sort' => (int) $row['sort'],
            'sold_out' => $row['stock'] !== null && (int) $row['stock'] <= 0,
            'expired' => $row['ends_at'] !== null && (string) $row['ends_at'] < $now,
            'payload' => $row['payload'] !== null ? (json_decode((string) $row['payload'], true) ?: null) : null,
        ];
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
