-- 023 会员等级体系（Lv0-Lv6 / 答题转正）、M币打赏、邮箱注册、经验任务
-- 说明：等级只保留 Lv0-Lv6；移除小游戏相关的称号与成就种子（表保留，功能已下线）。

-- ---------------------------------------------------------------------------
-- 一、用户：正式会员 / 大会员 / 邮箱验证 / 密码状态
-- ---------------------------------------------------------------------------

ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL AFTER email,
    ADD COLUMN password_set TINYINT(1) NOT NULL DEFAULT 1 AFTER password_hash,
    ADD COLUMN is_formal TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN membership ENUM('normal','premium') NOT NULL DEFAULT 'normal' AFTER is_formal,
    ADD KEY idx_users_formal (is_formal);

-- 存量用户视为已转正（新体系只对新注册用户require答题）
UPDATE users SET is_formal = 1, email_verified_at = COALESCE(email_verified_at, created_at);

CREATE TABLE email_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    purpose VARCHAR(24) NOT NULL, -- register / reset
    code_hash CHAR(64) NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_email_verifications_lookup (email, purpose),
    KEY idx_email_verifications_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 二、答题转正
-- ---------------------------------------------------------------------------

CREATE TABLE quiz_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    question VARCHAR(255) NOT NULL,
    options LONGTEXT NOT NULL,   -- JSON 字符串数组
    answer TINYINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_quiz_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE quiz_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    score INT NOT NULL DEFAULT 0,
    total INT NOT NULL DEFAULT 100,
    passed TINYINT(1) NOT NULL DEFAULT 0,
    wrong_ids LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_quiz_attempts_user (user_id, id),
    CONSTRAINT fk_quiz_attempts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 三、M币打赏（投币）
-- ---------------------------------------------------------------------------

ALTER TABLE posts ADD COLUMN coin_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER favorite_count;

CREATE TABLE post_coins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    post_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    amount INT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_post_coins (post_id, user_id),
    KEY idx_post_coins_user (user_id, created_at),
    CONSTRAINT fk_post_coins_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE,
    CONSTRAINT fk_post_coins_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 四、等级曲线：Lv0 - Lv6
-- ---------------------------------------------------------------------------

DELETE FROM level_configs;

INSERT INTO level_configs (level, xp_required, name, reward_mofu) VALUES
    (0, 0, 'Lv.0 待转正', 0),
    (1, 0, 'Lv.1 正式会员', 0),
    (2, 200, 'Lv.2 常驻会员', 50),
    (3, 1500, 'Lv.3 活跃会员', 100),
    (4, 4500, 'Lv.4 资深会员', 200),
    (5, 10800, 'Lv.5 核心会员', 500),
    (6, 28800, 'Lv.6 荣誉会员', 1000);

-- ---------------------------------------------------------------------------
-- 五、下线小游戏：移除相关称号与成就种子
-- ---------------------------------------------------------------------------

DELETE FROM user_achievements WHERE achievement_id IN (
    SELECT id FROM achievements WHERE condition_type IN ('games_played', 'game_score')
);
DELETE FROM achievements WHERE condition_type IN ('games_played', 'game_score');
DELETE FROM user_titles WHERE title_id IN (SELECT id FROM titles WHERE slug = 'gamer');
DELETE FROM titles WHERE slug = 'gamer';

-- @@DOWN

ALTER TABLE posts DROP COLUMN coin_count;
DROP TABLE IF EXISTS post_coins;
DROP TABLE IF EXISTS quiz_attempts;
DROP TABLE IF EXISTS quiz_questions;
DROP TABLE IF EXISTS email_verifications;
ALTER TABLE users
    DROP KEY idx_users_formal,
    DROP COLUMN membership,
    DROP COLUMN is_formal,
    DROP COLUMN password_set,
    DROP COLUMN email_verified_at;
DELETE FROM level_configs;
INSERT INTO level_configs (level, xp_required, name, reward_mofu)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 60)
SELECT n, 50 * (n - 1) * n, CONCAT('Lv.', n), 0 FROM seq;
