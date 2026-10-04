-- 006 用户偏好、主题与系统设置、审计日志
-- user_preferences / user_themes / system_settings / audit_logs（含系统设置种子）

CREATE TABLE user_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    pref_key VARCHAR(64) NOT NULL,
    pref_value LONGTEXT NOT NULL,  -- 用 LONGTEXT 存 JSON：兼容不支持 JSON 类型的旧版数据库
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, pref_key),
    CONSTRAINT fk_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_themes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(48) NOT NULL,
    source_color CHAR(7) NOT NULL,
    scheme ENUM('auto','light','dark') NOT NULL DEFAULT 'auto',
    contrast ENUM('standard','medium','high') NOT NULL DEFAULT 'standard',
    variant VARCHAR(32) NOT NULL DEFAULT 'tonalSpot',
    density TINYINT NOT NULL DEFAULT 0,
    motion ENUM('standard','expressive') NOT NULL DEFAULT 'standard',
    wallpaper_media_id BIGINT UNSIGNED NULL,
    css_vars MEDIUMTEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_themes_user (user_id),
    CONSTRAINT fk_themes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
    setting_key VARCHAR(64) NOT NULL,
    setting_value MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(20) NOT NULL DEFAULT '',
    target_id BIGINT UNSIGNED NULL,
    meta LONGTEXT NULL,
    ip_address VARCHAR(45) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_id, created_at),
    KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统设置种子
INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES
    ('site.registration_open', '1', NOW()),
    ('site.guest_view', '1', NOW()),
    ('site.default_theme', '{"sourceColor":"#5B67C7","variant":"tonalSpot","contrast":"standard","density":0,"motion":"standard"}', NOW());

-- @@DOWN

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS user_themes;
DROP TABLE IF EXISTS user_preferences;
