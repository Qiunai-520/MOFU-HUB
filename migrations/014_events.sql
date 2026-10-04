-- 014 校园活动与报名
-- events / event_signups

CREATE TABLE events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(60) NOT NULL,
    description TEXT NOT NULL,
    location VARCHAR(80) NOT NULL DEFAULT '',
    start_at DATETIME NOT NULL,
    end_at DATETIME NULL,
    signup_deadline DATETIME NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 0,
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    cover_media_id BIGINT UNSIGNED NULL,
    status ENUM('published','cancelled','hidden','removed') NOT NULL DEFAULT 'published',
    signup_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_events_start (status, start_at),
    KEY idx_events_user (user_id, created_at),
    CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_signups (
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','approved','rejected','removed','cancelled') NOT NULL DEFAULT 'pending',
    note VARCHAR(200) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (event_id, user_id),
    KEY idx_esignups_user (user_id, created_at),
    KEY idx_esignups_status (event_id, status),
    CONSTRAINT fk_esignups_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_esignups_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS event_signups;
DROP TABLE IF EXISTS events;
