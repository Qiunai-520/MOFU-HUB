-- 036 邀请码：Lv5 每月可买 1 个、Lv6 每月 2 个；被邀请人免答题转正

CREATE TABLE invite_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(24) NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    used_by BIGINT UNSIGNED NULL,
    used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_invite_codes_code (code),
    KEY idx_invite_codes_owner (owner_id, created_at),
    KEY idx_invite_codes_used_by (used_by),
    CONSTRAINT fk_invite_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_invite_used_by FOREIGN KEY (used_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS invite_codes;
