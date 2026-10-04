-- 019 申诉查看令牌
-- 提交申诉时生成一次性令牌并写入 Cookie，申诉人凭令牌查看自己的处理结果；
-- 避免匿名按用户名读取他人申诉状态与管理员回复。

ALTER TABLE user_appeals ADD COLUMN access_token CHAR(64) NULL AFTER user_id;
ALTER TABLE user_appeals ADD KEY idx_appeals_token (access_token);

-- @@DOWN

ALTER TABLE user_appeals DROP KEY idx_appeals_token;
ALTER TABLE user_appeals DROP COLUMN access_token;
