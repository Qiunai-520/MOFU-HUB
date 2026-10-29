-- 039 昵称修改：首次免费，之后每次 6 M币，且两次间隔不少于 3 天

ALTER TABLE user_profiles
    ADD COLUMN nickname_changed_at DATETIME NULL AFTER nickname,
    ADD COLUMN nickname_change_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER nickname_changed_at;

CREATE TABLE nickname_change_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    old_nickname VARCHAR(48) NOT NULL DEFAULT '',
    new_nickname VARCHAR(48) NOT NULL DEFAULT '',
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_nickname_log_user (user_id, created_at),
    CONSTRAINT fk_nickname_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS nickname_change_log;
ALTER TABLE user_profiles
    DROP COLUMN nickname_change_count,
    DROP COLUMN nickname_changed_at;
