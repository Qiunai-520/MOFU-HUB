-- 022 社区治理与成长体系
-- 举报/封禁/申诉升级 + 公告 + 钱包/等级/称号/成就/签到/小店/小游戏
-- 说明：所有金额、经验、等级、分数均由服务端计算，本文件只负责结构与种子数据。

-- ---------------------------------------------------------------------------
-- 一、用户状态与封禁
-- ---------------------------------------------------------------------------

-- 账号状态增加「警告」中间态；warned 仍可正常使用，只是前端会提示
ALTER TABLE users
    MODIFY COLUMN status ENUM('active','warned','suspended','banned') NOT NULL DEFAULT 'active',
    ADD COLUMN equipped_title_id BIGINT UNSIGNED NULL AFTER status,
    ADD KEY idx_users_equipped_title (equipped_title_id);

CREATE TABLE user_bans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('warning','suspended','banned') NOT NULL,
    reason VARCHAR(200) NOT NULL DEFAULT '',
    note VARCHAR(500) NOT NULL DEFAULT '',
    issued_by BIGINT UNSIGNED NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL, -- NULL 表示永久
    active TINYINT(1) NOT NULL DEFAULT 1,
    revoked_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    revoke_note VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_bans_user (user_id, active),
    KEY idx_bans_expire (active, ends_at),
    CONSTRAINT fk_bans_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_bans_issuer FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_bans_revoker FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 举报：补充被举报人、审核结果与管理员备注
ALTER TABLE reports
    ADD COLUMN reported_user_id BIGINT UNSIGNED NULL AFTER target_id,
    ADD COLUMN review_result VARCHAR(32) NOT NULL DEFAULT '' AFTER handled_at,
    ADD COLUMN admin_note VARCHAR(500) NOT NULL DEFAULT '' AFTER review_result,
    ADD KEY idx_reports_reported_user (reported_user_id, status);

-- 申诉：关联封禁记录，便于展示处罚详情
ALTER TABLE user_appeals
    ADD COLUMN ban_id BIGINT UNSIGNED NULL AFTER access_token,
    ADD KEY idx_appeals_ban (ban_id);

-- ---------------------------------------------------------------------------
-- 二、弹窗公告
-- ---------------------------------------------------------------------------

CREATE TABLE announcements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(120) NOT NULL,
    content TEXT NOT NULL,
    type ENUM('normal','system','important','activity','maintenance') NOT NULL DEFAULT 'normal',
    priority INT NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    forced TINYINT(1) NOT NULL DEFAULT 0,     -- 强制显示：不允许直接关闭
    closable TINYINT(1) NOT NULL DEFAULT 1,
    repeat_show TINYINT(1) NOT NULL DEFAULT 0, -- 1 = 每次访问都弹；0 = 已读后不再弹
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_announcements_live (status, starts_at, ends_at, priority),
    CONSTRAINT fk_announcements_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcement_reads (
    announcement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME NOT NULL,
    dismissed TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (announcement_id, user_id),
    KEY idx_announcement_reads_user (user_id, dismissed),
    CONSTRAINT fk_ar_announcement FOREIGN KEY (announcement_id) REFERENCES announcements (id) ON DELETE CASCADE,
    CONSTRAINT fk_ar_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 通知补充展示字段（图标 / 跳转）
ALTER TABLE notifications
    ADD COLUMN icon VARCHAR(64) NOT NULL DEFAULT '' AFTER type,
    ADD COLUMN url VARCHAR(255) NOT NULL DEFAULT '' AFTER target_id;

-- ---------------------------------------------------------------------------
-- 三、钱包与交易流水
-- ---------------------------------------------------------------------------

CREATE TABLE user_wallets (
    user_id BIGINT UNSIGNED NOT NULL,
    balance BIGINT NOT NULL DEFAULT 0,
    total_earned BIGINT NOT NULL DEFAULT 0,
    total_spent BIGINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wallet_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    type ENUM('earn','spend','adjust') NOT NULL,
    amount BIGINT NOT NULL,               -- 正数；方向由 type 决定
    balance_before BIGINT NOT NULL,
    balance_after BIGINT NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT '',   -- checkin / game / achievement / shop / admin / ...
    reference_id VARCHAR(64) NULL,
    description VARCHAR(200) NOT NULL DEFAULT '',
    idempotency_key VARCHAR(96) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_wallet_tx_idem (idempotency_key),
    KEY idx_wallet_tx_user (user_id, id),
    CONSTRAINT fk_wallet_tx_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 四、等级与成长统计
-- ---------------------------------------------------------------------------

CREATE TABLE level_configs (
    level INT UNSIGNED NOT NULL,
    xp_required BIGINT NOT NULL,      -- 达到该等级所需的累计 XP
    name VARCHAR(32) NOT NULL DEFAULT '',
    reward_mofu INT NOT NULL DEFAULT 0,
    PRIMARY KEY (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_levels (
    user_id BIGINT UNSIGNED NOT NULL,
    xp BIGINT NOT NULL DEFAULT 0,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_levels_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_stats (
    user_id BIGINT UNSIGNED NOT NULL,
    checkin_streak INT NOT NULL DEFAULT 0,
    checkin_best INT NOT NULL DEFAULT 0,
    checkin_total INT NOT NULL DEFAULT 0,
    last_checkin_date DATE NULL,
    games_played INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_stats_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 五、称号与成就
-- ---------------------------------------------------------------------------

CREATE TABLE titles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(48) NOT NULL,
    name VARCHAR(48) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    icon VARCHAR(64) NOT NULL DEFAULT 'military_tech',
    rarity ENUM('common','rare','epic','legendary','special') NOT NULL DEFAULT 'common',
    condition_type VARCHAR(32) NOT NULL DEFAULT 'manual',
    condition_value INT NOT NULL DEFAULT 0,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    wearable TINYINT(1) NOT NULL DEFAULT 1,
    limited TINYINT(1) NOT NULL DEFAULT 0,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_titles_slug (slug),
    KEY idx_titles_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_titles (
    user_id BIGINT UNSIGNED NOT NULL,
    title_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'system',
    acquired_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, title_id),
    KEY idx_user_titles_title (title_id),
    CONSTRAINT fk_user_titles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_titles_title FOREIGN KEY (title_id) REFERENCES titles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE achievements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(48) NOT NULL,
    name VARCHAR(48) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    icon VARCHAR(64) NOT NULL DEFAULT 'emoji_events',
    category VARCHAR(32) NOT NULL DEFAULT 'general',
    condition_type VARCHAR(32) NOT NULL,
    condition_value INT NOT NULL DEFAULT 1,
    reward_mofu INT NOT NULL DEFAULT 0,
    reward_xp INT NOT NULL DEFAULT 0,
    reward_title_id BIGINT UNSIGNED NULL,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_achievements_slug (slug),
    KEY idx_achievements_active (active, sort),
    CONSTRAINT fk_achievements_title FOREIGN KEY (reward_title_id) REFERENCES titles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_achievements (
    user_id BIGINT UNSIGNED NOT NULL,
    achievement_id BIGINT UNSIGNED NOT NULL,
    progress INT NOT NULL DEFAULT 0,
    unlocked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, achievement_id),
    KEY idx_user_achievements_ach (achievement_id),
    CONSTRAINT fk_ua_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ua_achievement FOREIGN KEY (achievement_id) REFERENCES achievements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 六、签到
-- ---------------------------------------------------------------------------

CREATE TABLE check_ins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    checkin_date DATE NOT NULL,
    streak INT NOT NULL DEFAULT 1,
    reward_mofu INT NOT NULL DEFAULT 0,
    reward_xp INT NOT NULL DEFAULT 0,
    label VARCHAR(64) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_checkins_user_date (user_id, checkin_date),
    KEY idx_checkins_user (user_id, checkin_date),
    CONSTRAINT fk_checkins_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE check_in_rewards (
    day INT UNSIGNED NOT NULL,
    reward_mofu INT NOT NULL DEFAULT 0,
    reward_xp INT NOT NULL DEFAULT 0,
    title_id BIGINT UNSIGNED NULL,
    label VARCHAR(64) NOT NULL DEFAULT '',
    PRIMARY KEY (day),
    CONSTRAINT fk_cir_title FOREIGN KEY (title_id) REFERENCES titles (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 七、小店
-- ---------------------------------------------------------------------------

CREATE TABLE shop_products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(48) NOT NULL,
    name VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    icon VARCHAR(64) NOT NULL DEFAULT 'redeem',
    image VARCHAR(255) NOT NULL DEFAULT '',
    price INT NOT NULL DEFAULT 0,
    type VARCHAR(32) NOT NULL DEFAULT 'badge',
    stock INT NULL,                      -- NULL = 不限量
    active TINYINT(1) NOT NULL DEFAULT 1,
    per_user_limit INT NULL,             -- NULL = 不限购
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    payload LONGTEXT NULL,               -- JSON：type 相关参数（如称号 slug / 主题色）
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_shop_products_slug (slug),
    KEY idx_shop_products_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE shop_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_no VARCHAR(40) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    price INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    status ENUM('paid','refunded') NOT NULL DEFAULT 'paid',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_shop_orders_no (order_no),
    KEY idx_shop_orders_user (user_id, id),
    KEY idx_shop_orders_product (product_id, created_at),
    CONSTRAINT fk_shop_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_shop_orders_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_inventory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    acquired_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_inventory_user_product (user_id, product_id),
    CONSTRAINT fk_inventory_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_product FOREIGN KEY (product_id) REFERENCES shop_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 八、小游戏
-- ---------------------------------------------------------------------------

CREATE TABLE games (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(48) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    icon VARCHAR(64) NOT NULL DEFAULT 'sports_esports',
    cover VARCHAR(255) NOT NULL DEFAULT '',
    entry VARCHAR(120) NOT NULL DEFAULT '',   -- 前端游戏实现标识
    active TINYINT(1) NOT NULL DEFAULT 1,
    max_score INT NOT NULL DEFAULT 0,          -- 服务端校验上限（0 = 不校验）
    daily_plays INT NOT NULL DEFAULT 0,        -- 每日可玩次数（0 = 不限）
    reward_mofu INT NOT NULL DEFAULT 0,        -- 满分时可得的 Mofu（按分数比例折算）
    reward_xp INT NOT NULL DEFAULT 0,
    sort INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_games_slug (slug),
    KEY idx_games_active (active, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    game_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    score INT NOT NULL DEFAULT 0,
    duration INT NOT NULL DEFAULT 0,
    result VARCHAR(32) NOT NULL DEFAULT '',
    reward_mofu INT NOT NULL DEFAULT 0,
    reward_xp INT NOT NULL DEFAULT 0,
    seed VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_game_records_seed (seed),
    KEY idx_game_records_board (game_id, score, created_at),
    KEY idx_game_records_user (user_id, created_at),
    KEY idx_game_records_daily (user_id, game_id, created_at),
    CONSTRAINT fk_game_records_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_game_records_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 每日奖励发放额度（防止小游戏刷币）
CREATE TABLE growth_daily_counters (
    user_id BIGINT UNSIGNED NOT NULL,
    counter_date DATE NOT NULL,
    source VARCHAR(32) NOT NULL,
    earned BIGINT NOT NULL DEFAULT 0,
    count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, counter_date, source),
    CONSTRAINT fk_gdc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 九、权限点补充
-- ---------------------------------------------------------------------------

INSERT INTO permissions (slug, name) VALUES
    ('announcement.manage', '公告管理'),
    ('ban.manage', '封禁管理'),
    ('user.warn', '警告用户'),
    ('user.ban.temp', '临时封禁'),
    ('user.ban.permanent', '永久封禁'),
    ('appeal.manage', '申诉管理'),
    ('title.manage', '称号管理'),
    ('shop.manage', '商店管理'),
    ('checkin.manage', '签到管理'),
    ('game.manage', '游戏管理'),
    ('economy.manage', '经济系统管理'),
    ('admin.manage', '管理员管理');

-- 内容审核员：可处理举报、警告与临时封禁
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'moderator'
  AND p.slug IN ('user.warn', 'user.ban.temp', 'ban.manage');

-- 管理员：在审核员之上增加运营与系统管理能力
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'admin'
  AND p.slug IN (
    'announcement.manage', 'ban.manage', 'user.warn', 'user.ban.temp', 'user.ban.permanent',
    'appeal.manage', 'title.manage', 'shop.manage', 'checkin.manage', 'game.manage', 'economy.manage'
  );

-- 超级管理员：全部新权限
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'super_admin'
  AND p.slug IN (
    'announcement.manage', 'ban.manage', 'user.warn', 'user.ban.temp', 'user.ban.permanent',
    'appeal.manage', 'title.manage', 'shop.manage', 'checkin.manage', 'game.manage',
    'economy.manage', 'admin.manage'
  );

-- ---------------------------------------------------------------------------
-- 十、种子数据
-- ---------------------------------------------------------------------------

-- 等级曲线：Lv.n 需要 50*(n-1)*n 累计 XP
INSERT INTO level_configs (level, xp_required, name, reward_mofu)
WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 60)
SELECT n, 50 * (n - 1) * n, CONCAT('Lv.', n), 0 FROM seq;

INSERT INTO titles (slug, name, description, icon, rarity, condition_type, condition_value, hidden, wearable, sort, created_at) VALUES
    ('newcomer', '初次见面', '注册账号即可获得', 'waving_hand', 'common', 'register', 0, 0, 1, 10, NOW()),
    ('regular', '常客', '连续签到 7 天', 'local_cafe', 'rare', 'checkin_streak', 7, 0, 1, 20, NOW()),
    ('veteran', '老朋友', '注册满 365 天', 'history_edu', 'epic', 'account_age', 365, 0, 1, 30, NOW()),
    ('active', '活跃用户', '累计获得 100 次互动', 'bolt', 'rare', 'interactions', 100, 0, 1, 40, NOW()),
    ('nightowl', '夜猫子', '在深夜完成一次签到', 'nightlight', 'rare', 'night_checkin', 0, 0, 1, 50, NOW()),
    ('gamer', '游戏达人', '小游戏累计获得 1000 分', 'sports_esports', 'epic', 'game_score_total', 1000, 0, 1, 60, NOW()),
    ('stargazer', '星光旅人', '在小店购买获得', 'auto_awesome', 'rare', 'shop', 0, 0, 1, 70, NOW()),
    ('founder', '创世元老', '由管理员授予', 'workspace_premium', 'legendary', 'manual', 0, 0, 1, 100, NOW());

INSERT INTO achievements (slug, name, description, icon, category, condition_type, condition_value, reward_mofu, reward_xp, reward_title_id, sort, created_at) VALUES
    ('first_post', '初次发言', '发布第一条动态', 'edit_note', 'content', 'post_count', 1, 20, 30, NULL, 10, NOW()),
    ('first_comment', '热心回复', '发表第一条评论', 'forum', 'content', 'comment_count', 1, 10, 20, NULL, 20, NOW()),
    ('checkin_7', '坚持一周', '连续签到 7 天', 'local_cafe', 'checkin', 'checkin_streak', 7, 50, 80, 2, 30, NOW()),
    ('checkin_30', '月度铁人', '连续签到 30 天', 'calendar_month', 'checkin', 'checkin_streak', 30, 200, 300, NULL, 40, NOW()),
    ('social_50', '小有名气', '累计获得 50 次点赞', 'favorite', 'social', 'likes_received', 50, 80, 100, NULL, 50, NOW()),
    ('games_10', '游戏新手', '游玩 10 局小游戏', 'sports_esports', 'game', 'games_played', 10, 40, 60, 6, 60, NOW());

INSERT INTO check_in_rewards (day, reward_mofu, reward_xp, title_id, label) VALUES
    (1, 10, 10, NULL, '今日签到'),
    (2, 12, 12, NULL, '连续 2 天'),
    (3, 20, 20, NULL, '连续 3 天 · 额外奖励'),
    (4, 14, 14, NULL, '连续 4 天'),
    (5, 16, 16, NULL, '连续 5 天'),
    (6, 18, 18, NULL, '连续 6 天'),
    (7, 50, 60, 2, '连续 7 天 · 称号「常客」'),
    (14, 120, 140, NULL, '连续 14 天 · 大额奖励'),
    (30, 300, 400, NULL, '连续 30 天 · 月度大奖');

INSERT INTO games (slug, name, description, icon, entry, max_score, daily_plays, reward_mofu, reward_xp, sort, created_at) VALUES
    ('clicker', '点击挑战', '在 15 秒内尽可能多地点击目标', 'ads_click', 'clicker', 500, 5, 30, 40, 10, NOW()),
    ('reaction', '反应速度', '目标出现后尽快点击，越快分越高', 'bolt', 'reaction', 100, 5, 30, 40, 20, NOW()),
    ('guess', '猜数字', '猜出系统生成的数字，次数越少分越高', 'casino', 'guess', 100, 5, 30, 40, 30, NOW()),
    ('memory', '记忆挑战', '记住闪现的符号序列', 'psychology', 'memory', 100, 5, 30, 40, 40, NOW()),
    ('lucky', '幸运抽奖', '使用活动券抽取随机奖励', 'redeem', 'lucky', 100, 3, 20, 20, 50, NOW());

INSERT INTO shop_products (slug, name, description, icon, price, type, stock, active, per_user_limit, payload, sort, created_at, updated_at) VALUES
    ('title-stargazer', '称号 · 星光旅人', '佩戴「星光旅人」称号', 'auto_awesome', 300, 'title', NULL, 1, 1, '{"title_slug":"stargazer"}', 10, NOW(), NOW()),
    ('frame-gold', '头像框 · 鎏金', '个人主页头像的金色描边', 'workspace_premium', 200, 'avatar_frame', NULL, 1, 1, '{"frame":"gold"}', 20, NOW(), NOW()),
    ('badge-early', '徽章 · 早期成员', '展示在个人主页的纪念徽章', 'verified', 150, 'badge', 500, 1, 1, '{"badge":"early"}', 30, NOW(), NOW()),
    ('boost-checkin', '签到加成卡', '连续签到奖励 +20%，持续 7 天', 'trending_up', 120, 'checkin_boost', NULL, 1, 3, '{"percent":20,"days":7}', 40, NOW(), NOW());

-- @@DOWN

DROP TABLE IF EXISTS growth_daily_counters;
DROP TABLE IF EXISTS game_records;
DROP TABLE IF EXISTS games;
DROP TABLE IF EXISTS user_inventory;
DROP TABLE IF EXISTS shop_orders;
DROP TABLE IF EXISTS shop_products;
DROP TABLE IF EXISTS check_in_rewards;
DROP TABLE IF EXISTS check_ins;
DROP TABLE IF EXISTS user_achievements;
DROP TABLE IF EXISTS achievements;
DROP TABLE IF EXISTS user_titles;
DROP TABLE IF EXISTS titles;
DROP TABLE IF EXISTS user_stats;
DROP TABLE IF EXISTS user_levels;
DROP TABLE IF EXISTS level_configs;
DROP TABLE IF EXISTS wallet_transactions;
DROP TABLE IF EXISTS user_wallets;
DROP TABLE IF EXISTS announcement_reads;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS user_bans;
ALTER TABLE user_appeals DROP KEY idx_appeals_ban, DROP COLUMN ban_id;
ALTER TABLE reports DROP KEY idx_reports_reported_user, DROP COLUMN admin_note, DROP COLUMN review_result, DROP COLUMN reported_user_id;
ALTER TABLE notifications DROP COLUMN url, DROP COLUMN icon;
ALTER TABLE users DROP KEY idx_users_equipped_title, DROP COLUMN equipped_title_id,
    MODIFY COLUMN status ENUM('active','suspended','banned') NOT NULL DEFAULT 'active';
DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
WHERE p.slug IN (
    'announcement.manage','ban.manage','user.warn','user.ban.temp','user.ban.permanent',
    'appeal.manage','title.manage','shop.manage','checkin.manage','game.manage','economy.manage','admin.manage'
);
DELETE FROM permissions WHERE slug IN (
    'announcement.manage','ban.manage','user.warn','user.ban.temp','user.ban.permanent',
    'appeal.manage','title.manage','shop.manage','checkin.manage','game.manage','economy.manage','admin.manage'
);
