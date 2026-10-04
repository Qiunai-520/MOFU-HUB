-- 008 匿名表白墙与树洞
-- confessions / confession_comments / confession_media
-- 注意：creator_id 与 guest_key_hash 属于隔离字段，公开接口不得返回。

CREATE TABLE confessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board ENUM('wall','hole') NOT NULL DEFAULT 'wall',
    category VARCHAR(32) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    mode ENUM('anonymous','named') NOT NULL DEFAULT 'anonymous',
    creator_id BIGINT UNSIGNED NULL,
    guest_key_hash CHAR(64) NULL,
    alias VARCHAR(48) NOT NULL DEFAULT '',
    status ENUM('pending','approved','rejected','hidden','removed') NOT NULL DEFAULT 'approved',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    like_count INT UNSIGNED NOT NULL DEFAULT 0,
    comment_count INT UNSIGNED NOT NULL DEFAULT 0,
    favorite_count INT UNSIGNED NOT NULL DEFAULT 0,
    report_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_confessions_feed (board, status, created_at),
    KEY idx_confessions_hot (board, status, like_count, created_at),
    KEY idx_confessions_creator (creator_id, created_at),
    KEY idx_confessions_status (status, created_at),
    CONSTRAINT fk_confessions_creator FOREIGN KEY (creator_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE confession_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    confession_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    reply_to_user_id BIGINT UNSIGNED NULL,
    alias VARCHAR(48) NOT NULL DEFAULT '',
    content VARCHAR(1000) NOT NULL,
    status ENUM('published','hidden','removed') NOT NULL DEFAULT 'published',
    like_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cconfession_comments (confession_id, created_at),
    KEY idx_cconfession_parent (parent_id),
    CONSTRAINT fk_cconfession FOREIGN KEY (confession_id) REFERENCES confessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_ccuser FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ccparent FOREIGN KEY (parent_id) REFERENCES confession_comments (id) ON DELETE CASCADE,
    CONSTRAINT fk_ccreply FOREIGN KEY (reply_to_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE confession_media (
    confession_id BIGINT UNSIGNED NOT NULL,
    media_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (confession_id, media_id),
    KEY idx_cm_media (media_id),
    CONSTRAINT fk_cm_confession FOREIGN KEY (confession_id) REFERENCES confessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS confession_media;
DROP TABLE IF EXISTS confession_comments;
DROP TABLE IF EXISTS confessions;
