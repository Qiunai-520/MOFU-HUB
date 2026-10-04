-- 009 互动系统扩展：支持表白墙与树洞内容

ALTER TABLE reactions MODIFY COLUMN target_type ENUM('post','comment','confession','confession_comment') NOT NULL;

-- @@DOWN

-- 回退前需先清理 confession 相关的互动记录
DELETE FROM reactions WHERE target_type IN ('confession','confession_comment');

ALTER TABLE reactions MODIFY COLUMN target_type ENUM('post','comment') NOT NULL;
