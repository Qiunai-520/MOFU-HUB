-- 题库分类：抽题时按分类轮转，避免同一主题的题目扎堆出现。
-- 迁移只改结构，不删除既有题目；题库替换必须由管理员显式执行并先完成备份。
ALTER TABLE quiz_questions ADD COLUMN category VARCHAR(32) NOT NULL DEFAULT '' AFTER question;
