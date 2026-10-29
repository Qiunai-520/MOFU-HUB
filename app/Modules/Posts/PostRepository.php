<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Database\Query;

final class PostRepository
{
    public function __construct(private readonly Query $query)
    {
    }

    /**
     * 动态基础查询（含作者信息）。
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->query->one(
            'SELECT p.*, u.username, u.status AS author_status,
                    pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path,
                    cm.slug AS community_slug, cm.name AS community_name
             FROM posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             LEFT JOIN communities cm ON cm.id = p.community_id
             WHERE p.id = :id',
            ['id' => $id],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->query->insert('posts', $data);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->query->update('posts', $data, 'id = :id', ['id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->query->update('posts', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function mediaForPost(int $postId): array
    {
        return $this->query->all(
            'SELECT m.* FROM post_media pm JOIN media m ON m.id = pm.media_id
             WHERE pm.post_id = :post_id ORDER BY pm.sort_order ASC, m.id ASC',
            ['post_id' => $postId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function topicsForPost(int $postId): array
    {
        return $this->query->all(
            'SELECT t.id, t.name, t.slug FROM post_topics pt JOIN topics t ON t.id = pt.topic_id
             WHERE pt.post_id = :post_id ORDER BY t.name ASC',
            ['post_id' => $postId],
        );
    }

    /**
     * 批量查询多条动态的媒体。
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> post_id => media
     */
    public function mediaForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->inClause('p', $postIds);

        $rows = $this->query->all(
            sprintf(
                'SELECT pm.post_id, m.* FROM post_media pm JOIN media m ON m.id = pm.media_id
                 WHERE pm.post_id IN (%s) ORDER BY pm.sort_order ASC, m.id ASC',
                $placeholders,
            ),
            $params,
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['post_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * 批量查询多条动态的话题。
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> post_id => topics
     */
    public function topicsForPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->inClause('p', $postIds);

        $rows = $this->query->all(
            sprintf(
                'SELECT pt.post_id, t.id, t.name, t.slug FROM post_topics pt JOIN topics t ON t.id = pt.topic_id
                 WHERE pt.post_id IN (%s) ORDER BY t.name ASC',
                $placeholders,
            ),
            $params,
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['post_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private function inClause(string $prefix, array $ids): array
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':' . $prefix . '_' . $i;
            $params[$prefix . '_' . $i] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    /**
     * 信息流分页。
     *
     * 排序规则（可解释）：
     * - latest：置顶优先，时间倒序；
     * - recommend：14 天内，互动加权（点赞×3 + 评论×4）按小时数衰减（Hacker News 风格）；
     * - hot：7 天内互动总量排序。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function feed(string $tab, int $page, int $perPage, ?int $viewerId = null): array
    {
        $base = 'FROM posts p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE p.status = :status AND p.visibility = :visibility AND p.deleted_at IS NULL
                   AND p.community_id IS NULL';
        $params = ['status' => 'published', 'visibility' => 'public'];
        $order = 'ORDER BY p.is_pinned DESC, p.id DESC';

        if ($tab === 'recommend') {
            $base .= ' AND p.created_at >= :window_start';
            $params['window_start'] = date('Y-m-d H:i:s', time() - 14 * 86400);
            // paginate() 会把同一组参数同时绑定到 count SQL；排序里的“当前时间”直接写
            // 服务端生成的时间字面量，避免 count SQL 出现未使用参数而报 HY093。
            $rankNow = date('Y-m-d H:i:s');
            $order = 'ORDER BY p.is_pinned DESC,
                      (p.like_count * 3 + p.comment_count * 4 + 1)
                      / POW(GREATEST(2, TIMESTAMPDIFF(HOUR, p.created_at, \'' . $rankNow . '\') + 2), 1.2) DESC,
                      p.id DESC';
        } elseif ($tab === 'hot') {
            $base .= ' AND p.created_at >= :window_start';
            $params['window_start'] = date('Y-m-d H:i:s', time() - 7 * 86400);
            $order = 'ORDER BY p.is_pinned DESC, (p.like_count * 3 + p.comment_count * 4) DESC, p.id DESC';
        } elseif ($tab === 'following' && $viewerId !== null) {
            $base .= ' AND p.user_id IN (SELECT followee_id FROM follows WHERE follower_id = :follower) ';
            $params['follower'] = $viewerId;
        }

        $sql = 'SELECT p.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                ' . $base . ' ' . $order;
        $countSql = 'SELECT COUNT(*) ' . $base;

        return $this->query->paginate($sql, $countSql, $params, $page, $perPage);
    }

    /**
     * 某用户的公开动态分页。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function byUser(int $userId, bool $includePrivate, int $page, int $perPage, string $keyword = ''): array
    {
        $base = 'FROM posts p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE p.user_id = :user_id AND p.status = :status AND p.deleted_at IS NULL';
        $params = ['user_id' => $userId, 'status' => 'published'];

        if (!$includePrivate) {
            $base .= ' AND p.visibility = :visibility AND p.community_id IS NULL';
            $params['visibility'] = 'public';
        } else {
            $base .= ' AND (p.community_id IS NULL OR p.user_id = :community_self)';
            $params['community_self'] = $userId;
        }

        $keyword = trim($keyword);

        if ($keyword !== '') {
            $base .= ' AND p.content LIKE :keyword';
            $params['keyword'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
        }

        $sql = 'SELECT p.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                ' . $base . ' ORDER BY p.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $base;

        return $this->query->paginate($sql, $countSql, $params, $page, $perPage);
    }

    /**
     * 某话题下的公开动态分页。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function byTopic(int $topicId, int $page, int $perPage): array
    {
        $base = 'FROM posts p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 JOIN post_topics pt ON pt.post_id = p.id
                 WHERE pt.topic_id = :topic_id AND p.status = :status AND p.visibility = :visibility
                   AND p.deleted_at IS NULL AND p.community_id IS NULL';
        $params = ['topic_id' => $topicId, 'status' => 'published', 'visibility' => 'public'];

        $sql = 'SELECT p.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                ' . $base . ' ORDER BY p.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $base;

        return $this->query->paginate($sql, $countSql, $params, $page, $perPage);
    }

    /**
     * 某圈子内的动态分页（成员视角：公开帖 + 自己的私密帖）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function byCommunity(int $communityId, ?int $viewerId, int $page, int $perPage): array
    {
        $base = 'FROM posts p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE p.community_id = :community_id AND p.status = :status AND p.deleted_at IS NULL
                   AND (p.visibility = :visibility OR p.user_id = :viewer_self)';
        $params = [
            'community_id' => $communityId,
            'status' => 'published',
            'visibility' => 'public',
            'viewer_self' => $viewerId ?? 0,
        ];

        $sql = 'SELECT p.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                ' . $base . ' ORDER BY p.is_pinned DESC, p.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $base;

        return $this->query->paginate($sql, $countSql, $params, $page, $perPage);
    }

    public function communitySlug(int $communityId): string
    {
        return (string) ($this->query->value(
            'SELECT slug FROM communities WHERE id = :id',
            ['id' => $communityId],
        ) ?? '');
    }

    /** @return array<string, mixed>|null */
    public function findTopicBySlug(string $slug): ?array
    {
        return $this->query->one(
            'SELECT id, name, slug, use_count FROM topics WHERE slug = :slug',
            ['slug' => $slug],
        );
    }

    /** 批量查询当前用户对一组动态的点赞状态 */
    public function likedPostIds(array $postIds, ?int $userId): array
    {
        return $this->reactionPostIds($postIds, $userId, 'like');
    }

    /** 批量查询当前用户对一组动态的收藏状态 */
    public function favoritedPostIds(array $postIds, ?int $userId): array
    {
        return $this->reactionPostIds($postIds, $userId, 'favorite');
    }

    /**
     * 批量查询转发目标原帖（含作者与头像）。
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id => row
     */
    public function repostOriginals(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$placeholders, $params] = $this->inClause('r', $ids);

        $rows = $this->query->all(
            sprintf(
                'SELECT p.*, u.username, u.status AS author_status, pr.nickname, pr.avatar_frame, pr.name_badge,
                        am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                 FROM posts p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE p.id IN (%s)',
                $placeholders,
            ),
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /**
     * 当前用户对一组原帖的未删除转发记录。
     *
     * @param list<int> $targetIds
     * @return array<int, int> target_id => repost post id
     */
    public function myReposts(array $targetIds, ?int $userId): array
    {
        if ($userId === null || $targetIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->inClause('t', $targetIds);
        $params['user'] = $userId;

        $rows = $this->query->all(
            sprintf(
                'SELECT id, repost_of_id FROM posts
                 WHERE user_id = :user AND repost_of_id IN (%s) AND deleted_at IS NULL',
                $placeholders,
            ),
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['repost_of_id']] = (int) $row['id'];
        }

        return $out;
    }

    /** 我转发过某原帖的未删除记录 id */
    public function myRepostOf(int $targetId, int $userId): ?int
    {
        $value = $this->query->value(
            'SELECT id FROM posts WHERE user_id = :user AND repost_of_id = :target AND deleted_at IS NULL ORDER BY id DESC LIMIT 1',
            ['user' => $userId, 'target' => $targetId],
        );

        return $value === null ? null : (int) $value;
    }

    public function repostCount(int $postId): int
    {
        return (int) $this->query->value('SELECT repost_count FROM posts WHERE id = :id', ['id' => $postId]);
    }

    private function reactionPostIds(array $postIds, ?int $userId, string $kind): array
    {
        if ($userId === null || $postIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['user_id' => $userId, 'kind' => $kind, 'type' => 'post'];

        foreach (array_values($postIds) as $i => $id) {
            $placeholders[] = ':id_' . $i;
            $params['id_' . $i] = (int) $id;
        }

        $rows = $this->query->all(
            sprintf(
                'SELECT target_id FROM reactions
                 WHERE user_id = :user_id AND kind = :kind AND target_type = :type AND target_id IN (%s)',
                implode(', ', $placeholders),
            ),
            $params,
        );

        return array_map(static fn (array $row): int => (int) $row['target_id'], $rows);
    }

    public function incrementCounter(int $postId, string $column, int $delta): void
    {
        if (!in_array($column, ['like_count', 'comment_count', 'favorite_count', 'repost_count'], true)) {
            return;
        }

        $this->query->run(
            sprintf('UPDATE posts SET %s = GREATEST(0, %s + :delta) WHERE id = :id', $column, $column),
            ['delta' => $delta, 'id' => $postId],
        );
    }

    /** 全站统计：动态总数（后台仪表盘用） */
    public function publishedCount(): int
    {
        return (int) $this->query->value(
            "SELECT COUNT(*) FROM posts WHERE status = 'published' AND deleted_at IS NULL",
        );
    }
}
