-- 027 搜索与信息流的浏览索引

-- 说明：搜索仍是 LIKE 匹配 —— MySQL 的中文分词要用 `WITH PARSER ngram`，而 MariaDB 没有该分词器，
-- 写进通用迁移会让 MariaDB 部署直接失败，因此这里不建 FULLTEXT，改为补齐「状态过滤 + 排序」的复合索引，
-- 降低过滤后的排序成本（配合搜索最小长度与频率限制，避免无意义的全表扫描）。

CREATE INDEX idx_posts_browse ON posts (status, visibility, deleted_at, community_id, id);

-- @@DOWN

DROP INDEX idx_posts_browse ON posts;
