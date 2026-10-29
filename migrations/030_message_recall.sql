-- 030 私信撤回：软删除标记（撤回后气泡显示「消息已撤回」）

ALTER TABLE messages
    ADD COLUMN deleted_at DATETIME NULL AFTER read_at,
    ADD KEY idx_messages_active (conversation_id, deleted_at);

-- @@DOWN

ALTER TABLE messages DROP KEY idx_messages_active, DROP COLUMN deleted_at;
