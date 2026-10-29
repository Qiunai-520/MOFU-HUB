-- 025 彻底下线小游戏：删除数据表与相关字段
-- 022 引入的 games / game_records 及 user_stats.games_played 已无任何代码读写，此处清理。

DELETE FROM growth_daily_counters WHERE source IN ('game', 'xp:game');

DROP TABLE IF EXISTS game_records;
DROP TABLE IF EXISTS games;

ALTER TABLE user_stats DROP COLUMN games_played;

-- @@DOWN

ALTER TABLE user_stats ADD COLUMN games_played INT NOT NULL DEFAULT 0 AFTER last_checkin_date;

CREATE TABLE games (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(40) NOT NULL,
    name VARCHAR(48) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    icon VARCHAR(64) NOT NULL DEFAULT 'sports_esports',
    cover VARCHAR(255) NOT NULL DEFAULT '',
    entry VARCHAR(120) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    max_score INT NOT NULL DEFAULT 0,
    daily_plays INT NOT NULL DEFAULT 0,
    reward_mofu INT NOT NULL DEFAULT 0,
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
