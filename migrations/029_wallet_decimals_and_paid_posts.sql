-- 029 钱包支持小数 + 付费帖子
-- 钱包改 DECIMAL：投币回馈按 0.1 M币/币 结算，整数列装不下。

ALTER TABLE user_wallets
    MODIFY balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    MODIFY total_earned DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    MODIFY total_spent DECIMAL(12,2) NOT NULL DEFAULT 0.00;

ALTER TABLE wallet_transactions
    MODIFY amount DECIMAL(12,2) NOT NULL,
    MODIFY balance_before DECIMAL(12,2) NOT NULL,
    MODIFY balance_after DECIMAL(12,2) NOT NULL;

-- 付费帖子：价格上限由服务端约束（1 ~ 15 M币）
ALTER TABLE posts
    ADD COLUMN is_paid TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility,
    ADD COLUMN price DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER is_paid,
    ADD KEY idx_posts_paid (is_paid, status);

CREATE TABLE post_purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_post_purchases (post_id, user_id),
    KEY idx_post_purchases_user (user_id, created_at),
    CONSTRAINT fk_post_purchases_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_purchases_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS post_purchases;

ALTER TABLE posts DROP KEY idx_posts_paid, DROP COLUMN price, DROP COLUMN is_paid;

ALTER TABLE wallet_transactions
    MODIFY amount BIGINT NOT NULL,
    MODIFY balance_before BIGINT NOT NULL,
    MODIFY balance_after BIGINT NOT NULL;

ALTER TABLE user_wallets
    MODIFY balance BIGINT NOT NULL DEFAULT 0,
    MODIFY total_earned BIGINT NOT NULL DEFAULT 0,
    MODIFY total_spent BIGINT NOT NULL DEFAULT 0;
