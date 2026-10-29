-- 024 一次性任务记录（绑定邮箱 / 设置密码等一次性经验）
-- 每日任务复用 growth_daily_counters；一次性任务用本表记录，避免重复发放。

CREATE TABLE user_tasks (
    user_id BIGINT UNSIGNED NOT NULL,
    task VARCHAR(32) NOT NULL,
    reward_xp INT NOT NULL DEFAULT 0,
    reward_mofu INT NOT NULL DEFAULT 0,
    completed_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, task),
    CONSTRAINT fk_user_tasks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @@DOWN

DROP TABLE IF EXISTS user_tasks;
