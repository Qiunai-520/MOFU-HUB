-- 034 大会员按月 / 季 / 年订阅（带到期时间）

ALTER TABLE users
    ADD COLUMN membership_plan ENUM('none','month','quarter','year') NOT NULL DEFAULT 'none' AFTER membership,
    ADD COLUMN membership_expires_at DATETIME NULL AFTER membership_plan,
    ADD KEY idx_users_membership_expires (membership_expires_at);

CREATE TABLE membership_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    plan ENUM('month','quarter','year') NOT NULL,
    months TINYINT UNSIGNED NOT NULL,
    amount_cny DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
    payment_order_no VARCHAR(40) NOT NULL DEFAULT '',
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_membership_orders_no (order_no),
    KEY idx_membership_orders_user (user_id, status),
    CONSTRAINT fk_membership_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS membership_orders;
ALTER TABLE users
    DROP KEY idx_users_membership_expires,
    DROP COLUMN membership_expires_at,
    DROP COLUMN membership_plan;
