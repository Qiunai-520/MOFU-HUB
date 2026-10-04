-- 020 板块 Logo 图像：复用 media 表存储，管理员在后台设置
ALTER TABLE boards ADD COLUMN logo_media_id BIGINT UNSIGNED NULL AFTER icon;
ALTER TABLE boards ADD CONSTRAINT fk_boards_logo FOREIGN KEY (logo_media_id) REFERENCES media (id) ON DELETE SET NULL;

-- @@DOWN

ALTER TABLE boards DROP FOREIGN KEY fk_boards_logo;
ALTER TABLE boards DROP COLUMN logo_media_id;
