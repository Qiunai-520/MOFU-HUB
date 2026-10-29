-- 035 易支付订单 + MOFU小店支持真实货币商品

CREATE TABLE payment_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('shop','membership') NOT NULL,
    ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    subject VARCHAR(120) NOT NULL DEFAULT '',
    amount_cny DECIMAL(10,2) NOT NULL,
    status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    pay_type VARCHAR(16) NOT NULL DEFAULT '',
    ezfp_trade_no VARCHAR(64) NOT NULL DEFAULT '',
    notified_at DATETIME NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_payment_orders_no (order_no),
    KEY idx_payment_orders_trade (ezfp_trade_no),
    KEY idx_payment_orders_user (user_id, status),
    CONSTRAINT fk_payment_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 商品：支付方式（M币 / 真实货币）、真实货币价格、发货方式（自动 / 后台手动）
ALTER TABLE shop_products
    ADD COLUMN pay_mode ENUM('mofu','cny') NOT NULL DEFAULT 'mofu' AFTER price,
    ADD COLUMN cny_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER pay_mode,
    ADD COLUMN delivery ENUM('auto','manual') NOT NULL DEFAULT 'auto' AFTER type;

-- 订单：来源渠道 + 关联的支付订单号；状态新增 pending（真实货币待支付）
ALTER TABLE shop_orders
    MODIFY status ENUM('pending','paid','refunded') NOT NULL DEFAULT 'paid',
    ADD COLUMN channel ENUM('mofu','cny') NOT NULL DEFAULT 'mofu' AFTER price,
    ADD COLUMN payment_order_no VARCHAR(40) NOT NULL DEFAULT '' AFTER channel,
    ADD KEY idx_shop_orders_payment (payment_order_no);

-- @@DOWN

ALTER TABLE shop_orders
    DROP KEY idx_shop_orders_payment,
    DROP COLUMN payment_order_no,
    DROP COLUMN channel,
    MODIFY status ENUM('paid','refunded') NOT NULL DEFAULT 'paid';

ALTER TABLE shop_products
    DROP COLUMN delivery,
    DROP COLUMN cny_price,
    DROP COLUMN pay_mode;

DROP TABLE IF EXISTS payment_orders;
