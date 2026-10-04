-- 005 通知、举报与审核记录
-- notifications / reports / moderation_records

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    type VARCHAR(40) NOT NULL,
    target_type VARCHAR(20) NOT NULL DEFAULT '',
    target_id BIGINT UNSIGNED NULL,
    data LONGTEXT NULL,  -- 用 LONGTEXT 存 JSON：兼容不支持 JSON 类型的旧版数据库
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id, read_at, created_at),
    KEY idx_notifications_actor (actor_id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reporter_id BIGINT UNSIGNED NOT NULL,
    target_type VARCHAR(20) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(64) NOT NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    handled_by BIGINT UNSIGNED NULL,
    handled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_reports_status (status, created_at),
    KEY idx_reports_target (target_type, target_id),
    CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_reports_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE moderation_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    moderator_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(40) NOT NULL,
    target_type VARCHAR(20) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_moderation_target (target_type, target_id),
    KEY idx_moderation_moderator (moderator_id, created_at),
    CONSTRAINT fk_moderation_moderator FOREIGN KEY (moderator_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS moderation_records;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS notifications;
