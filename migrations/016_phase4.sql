-- 016 阶段四 B：转发与浏览记录
-- posts.repost_of_id / posts.repost_count + post_views

ALTER TABLE posts
    ADD COLUMN repost_of_id BIGINT UNSIGNED NULL AFTER user_id,
    ADD COLUMN repost_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER favorite_count,
    ADD KEY idx_posts_repost (repost_of_id),
    ADD CONSTRAINT fk_posts_repost FOREIGN KEY (repost_of_id) REFERENCES posts (id) ON DELETE SET NULL;

CREATE TABLE post_views (
    user_id BIGINT UNSIGNED NOT NULL,
    post_id BIGINT UNSIGNED NOT NULL,
    viewed_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, post_id),
    KEY idx_post_views_user (user_id, viewed_at),
    KEY idx_post_views_post (post_id),
    CONSTRAINT fk_pv_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_pv_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS post_views;

ALTER TABLE posts DROP FOREIGN KEY fk_posts_repost;
ALTER TABLE posts DROP KEY idx_posts_repost;
ALTER TABLE posts DROP COLUMN repost_count;
ALTER TABLE posts DROP COLUMN repost_of_id;
