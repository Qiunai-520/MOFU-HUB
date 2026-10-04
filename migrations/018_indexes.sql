-- 018 补充索引：高频过滤/排序列
-- users.status：推荐关注、资讯广播（INSERT ... SELECT）按 status 过滤，避免全表扫描
-- notifications(user_id, id)：通知列表按 id 倒序，原索引无法满足排序，会 filesort

ALTER TABLE users ADD KEY idx_users_status (status);

ALTER TABLE notifications ADD KEY idx_notifications_user_id (user_id, id);

-- @@DOWN

ALTER TABLE notifications DROP KEY idx_notifications_user_id;

ALTER TABLE users DROP KEY idx_users_status;
