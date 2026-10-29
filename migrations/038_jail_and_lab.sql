-- 038 小黑屋（处罚公示）与社区实验室（功能开关 + 灰度）

ALTER TABLE user_bans
    ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 1 AFTER reason,
    ADD COLUMN public_note VARCHAR(255) NOT NULL DEFAULT '' AFTER is_public,
    ADD KEY idx_user_bans_public (is_public, active, starts_at);

CREATE TABLE lab_features (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feature_key VARCHAR(48) NOT NULL,
    name VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    rollout ENUM('all','verified','flash','invite') NOT NULL DEFAULT 'flash',
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lab_features_key (feature_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lab_access (
    user_id BIGINT UNSIGNED NOT NULL,
    feature_key VARCHAR(48) NOT NULL,
    enabled_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, feature_key),
    CONSTRAINT fk_lab_access_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS lab_access;
DROP TABLE IF EXISTS lab_features;
ALTER TABLE user_bans
    DROP KEY idx_user_bans_public,
    DROP COLUMN public_note,
    DROP COLUMN is_public;
