-- 031 私信附件：一条消息最多带一个图片/视频

ALTER TABLE messages
    ADD COLUMN media_id BIGINT UNSIGNED NULL AFTER content,
    ADD KEY idx_messages_media (media_id),
    ADD CONSTRAINT fk_messages_media FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL;

-- @@DOWN

ALTER TABLE messages DROP FOREIGN KEY fk_messages_media, DROP KEY idx_messages_media, DROP COLUMN media_id;
