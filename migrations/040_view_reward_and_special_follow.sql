-- 040 「每日观看 10 篇得 0.3 M币」结算去重（同一 IP 当日仅首个账号可得）+ 特别关注

-- post_views 已记录 (user_id, post_id, viewed_at)，按 DATE(viewed_at) 统计当日已看篇数；
-- 这里只加「当日结算」表，用 (reward_date, ip) 唯一键保证同 IP 当日只结算一次。
CREATE TABLE view_reward_settlements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    reward_date DATE NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    post_count INT UNSIGNED NOT NULL DEFAULT 0,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_view_reward_user_day (user_id, reward_date),
    UNIQUE KEY uk_view_reward_ip_day (reward_date, ip),
    CONSTRAINT fk_view_reward_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 特别关注（硬核会员上限由 30 提升至 60）
ALTER TABLE follows
    ADD COLUMN special TINYINT(1) NOT NULL DEFAULT 0 AFTER followee_id,
    ADD KEY idx_follows_special (follower_id, special);

-- @@DOWN

ALTER TABLE follows
    DROP KEY idx_follows_special,
    DROP COLUMN special;
DROP TABLE IF EXISTS view_reward_settlements;
