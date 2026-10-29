-- 028 闪电 Lv6：满级（Lv.6 / 28800 XP）后可参加进阶答题
-- 说明：经验封顶由服务端（LevelService）处理；本迁移只加状态列与题库分层。
--       quiz_questions.tier / quiz_attempts.tier：1 = 转正，2 = 进阶。

ALTER TABLE users ADD COLUMN is_flash TINYINT(1) NOT NULL DEFAULT 0 AFTER is_formal;

ALTER TABLE quiz_questions ADD COLUMN tier TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER active;

ALTER TABLE quiz_attempts
    ADD COLUMN tier TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER user_id,
    ADD KEY idx_quiz_attempts_tier (user_id, tier, id);

-- 存量：已自动获得称号但从未佩戴的用户，默认戴上最早获得的一个（否则帖子/主页看不到称号）
UPDATE users u
JOIN (
    SELECT ut.user_id, MIN(ut.title_id) AS title_id
    FROM user_titles ut
    JOIN titles t ON t.id = ut.title_id AND t.active = 1 AND t.wearable = 1
    GROUP BY ut.user_id
) pick ON pick.user_id = u.id
SET u.equipped_title_id = pick.title_id
WHERE u.equipped_title_id IS NULL;

-- @@DOWN

ALTER TABLE quiz_attempts DROP KEY idx_quiz_attempts_tier, DROP COLUMN tier;
ALTER TABLE quiz_questions DROP COLUMN tier;
ALTER TABLE users DROP COLUMN is_flash;
