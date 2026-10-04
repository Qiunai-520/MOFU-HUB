-- 013 兴趣圈子与统一板块框架
-- communities / community_members / boards / board_entries / board_entry_comments
-- + posts.community_id + reactions 目标类型扩展

CREATE TABLE communities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(48) NOT NULL,
    name VARCHAR(48) NOT NULL,
    description VARCHAR(300) NOT NULL DEFAULT '',
    icon VARCHAR(32) NOT NULL DEFAULT 'groups',
    visibility ENUM('public','approval') NOT NULL DEFAULT 'public',
    owner_id BIGINT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_communities_slug (slug),
    KEY idx_communities_active (is_active, created_at),
    CONSTRAINT fk_communities_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE community_members (
    community_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner','moderator','member') NOT NULL DEFAULT 'member',
    status ENUM('active','pending') NOT NULL DEFAULT 'active',
    joined_at DATETIME NOT NULL,
    PRIMARY KEY (community_id, user_id),
    KEY idx_cmembers_user (user_id, status),
    KEY idx_cmembers_status (community_id, status),
    CONSTRAINT fk_cmembers_community FOREIGN KEY (community_id) REFERENCES communities (id) ON DELETE CASCADE,
    CONSTRAINT fk_cmembers_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE posts
    ADD COLUMN community_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD KEY idx_posts_community (community_id, created_at),
    ADD CONSTRAINT fk_posts_community FOREIGN KEY (community_id) REFERENCES communities (id) ON DELETE CASCADE;

CREATE TABLE boards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(32) NOT NULL,
    name VARCHAR(32) NOT NULL,
    kind VARCHAR(16) NOT NULL,
    icon VARCHAR(32) NOT NULL DEFAULT 'dashboard',
    description VARCHAR(200) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_boards_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO boards (slug, name, kind, icon, description, sort_order, is_active, created_at) VALUES
    ('forum', '校园论坛', 'forum', 'forum', '版块化讨论，沉淀校园知识与经验', 10, 1, NOW()),
    ('qa', '校园问答', 'qa', 'help', '有问题来提问，热心同学来解答', 20, 1, NOW()),
    ('help', '校园互助', 'help', 'volunteer_activism', '互助求援、经验分享，让校园更温暖', 30, 1, NOW()),
    ('study', '学习交流', 'study', 'school', '课程交流、学习搭子、资料分享', 40, 1, NOW()),
    ('lost', '失物招领', 'lost', 'find_in_page', '丢了东西？捡到东西？都来这里', 50, 1, NOW()),
    ('market', '二手交易', 'market', 'storefront', '校园闲置流转，环保又省钱', 60, 1, NOW());

CREATE TABLE board_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    board_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(80) NOT NULL,
    content TEXT NOT NULL,
    extra LONGTEXT NULL,  -- 用 LONGTEXT 存 JSON：兼容不支持 JSON 类型的旧版数据库
    status ENUM('published','hidden','removed') NOT NULL DEFAULT 'published',
    like_count INT UNSIGNED NOT NULL DEFAULT 0,
    comment_count INT UNSIGNED NOT NULL DEFAULT 0,
    favorite_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_entries_board (board_id, status, created_at),
    KEY idx_entries_user (user_id, created_at),
    KEY idx_entries_hot (board_id, status, like_count, created_at),
    CONSTRAINT fk_entries_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE,
    CONSTRAINT fk_entries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE board_entry_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    content VARCHAR(1000) NOT NULL,
    status ENUM('published','hidden','removed') NOT NULL DEFAULT 'published',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_bentry_comments (entry_id, created_at),
    KEY idx_bentry_comments_user (user_id, created_at),
    CONSTRAINT fk_bentry_comments_entry FOREIGN KEY (entry_id) REFERENCES board_entries (id) ON DELETE CASCADE,
    CONSTRAINT fk_bentry_comments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reactions 目标类型扩展（新增 board_entry）
ALTER TABLE reactions
    MODIFY COLUMN target_type ENUM('post','comment','confession','confession_comment','board_entry') NOT NULL;

-- @@DOWN

-- 回退前需先清理 board_entry 相关互动记录
DELETE FROM reactions WHERE target_type = 'board_entry';

ALTER TABLE reactions
    MODIFY COLUMN target_type ENUM('post','comment','confession','confession_comment') NOT NULL;

DROP TABLE IF EXISTS board_entry_comments;
DROP TABLE IF EXISTS board_entries;
DROP TABLE IF EXISTS boards;

ALTER TABLE posts DROP FOREIGN KEY fk_posts_community;
ALTER TABLE posts DROP KEY idx_posts_community;
ALTER TABLE posts DROP COLUMN community_id;

DROP TABLE IF EXISTS community_members;
DROP TABLE IF EXISTS communities;
