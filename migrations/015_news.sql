-- 015 校园资讯
-- news + news.manage 权限种子

CREATE TABLE news (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(80) NOT NULL,
    summary VARCHAR(200) NOT NULL DEFAULT '',
    content TEXT NOT NULL,
    category ENUM('notice','campus','resource') NOT NULL DEFAULT 'campus',
    cover_media_id BIGINT UNSIGNED NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft','published','hidden','removed') NOT NULL DEFAULT 'draft',
    notified_at DATETIME NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_news_list (status, is_pinned, published_at),
    KEY idx_news_author (user_id, created_at),
    CONSTRAINT fk_news_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 校园资讯管理权限（管理员与超级管理员）
INSERT INTO permissions (slug, name) VALUES ('news.manage', '校园资讯管理');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug IN ('admin', 'super_admin') AND p.slug = 'news.manage';

-- @@DOWN

DELETE rp FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.slug = 'news.manage';

DELETE FROM permissions WHERE slug = 'news.manage';

DROP TABLE IF EXISTS news;
