-- 026 数据一致性：删用户不再连带删除圈子，并补齐 022 缺失的外键

-- 说明：
--  1. communities.owner_id 的外键原为 ON DELETE CASCADE：删除某个用户会先删掉他创建的圈子，
--     再由 posts.community_id 的 CASCADE 删掉圈子内**其他成员**发布的全部动态。改为 RESTRICT 后，
--     删号前必须先转让或解散圈子，避免误删他人内容。
--     注意 posts.community_id 仍保持 CASCADE —— 后台「解散圈子」的既定行为是连同圈内动态一起删除，
--     界面也已明确提示，这里不改。
--  2. 022 新增的 users.equipped_title_id / reports.reported_user_id / user_appeals.ban_id 三处
--     未配外键，被引用记录删除后会留下悬空 id，这里补上（统一 ON DELETE SET NULL，保留业务记录）。

-- ---------------------------------------------------------------------------
-- 一、圈子所有者：CASCADE → RESTRICT
-- ---------------------------------------------------------------------------

ALTER TABLE communities DROP FOREIGN KEY fk_communities_owner;

ALTER TABLE communities
    ADD CONSTRAINT fk_communities_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT;

-- ---------------------------------------------------------------------------
-- 二、users.equipped_title_id → titles(id)（先清理悬空值）
-- ---------------------------------------------------------------------------

UPDATE users u
LEFT JOIN titles t ON t.id = u.equipped_title_id
SET u.equipped_title_id = NULL
WHERE u.equipped_title_id IS NOT NULL AND t.id IS NULL;

ALTER TABLE users
    ADD CONSTRAINT fk_users_equipped_title FOREIGN KEY (equipped_title_id) REFERENCES titles (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 三、reports.reported_user_id → users(id)
-- ---------------------------------------------------------------------------

UPDATE reports r
LEFT JOIN users u ON u.id = r.reported_user_id
SET r.reported_user_id = NULL
WHERE r.reported_user_id IS NOT NULL AND u.id IS NULL;

ALTER TABLE reports
    ADD CONSTRAINT fk_reports_reported_user FOREIGN KEY (reported_user_id) REFERENCES users (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------------
-- 四、user_appeals.ban_id → user_bans(id)
-- ---------------------------------------------------------------------------

UPDATE user_appeals a
LEFT JOIN user_bans b ON b.id = a.ban_id
SET a.ban_id = NULL
WHERE a.ban_id IS NOT NULL AND b.id IS NULL;

ALTER TABLE user_appeals
    ADD CONSTRAINT fk_user_appeals_ban FOREIGN KEY (ban_id) REFERENCES user_bans (id) ON DELETE SET NULL;

-- @@DOWN

ALTER TABLE user_appeals DROP FOREIGN KEY fk_user_appeals_ban;
ALTER TABLE reports DROP FOREIGN KEY fk_reports_reported_user;
ALTER TABLE users DROP FOREIGN KEY fk_users_equipped_title;

ALTER TABLE communities DROP FOREIGN KEY fk_communities_owner;

ALTER TABLE communities
    ADD CONSTRAINT fk_communities_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE;
