<?php

declare(strict_types=1);

namespace Mofu\Modules\Confessions;

use Mofu\Core\Database\Query;

/**
 * 表白墙 / 树洞数据访问。
 * 注意：creator_id、guest_key_hash 为隔离字段，查询结果仅供服务层鉴别身份使用，
 * 任何公开输出必须经过 ConfessionPresenter 过滤。
 */
final class ConfessionRepository
{
    public function __construct(private readonly Query $query)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->query->one(
            'SELECT c.*, u.username AS author_username, pr.nickname AS author_nickname
             FROM confessions c
             LEFT JOIN users u ON u.id = c.creator_id
             LEFT JOIN user_profiles pr ON pr.user_id = c.creator_id
             WHERE c.id = :id',
            ['id' => $id],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->query->insert('confessions', $data);
    }

    public function update(int $id, array $data): void
    {
        $this->query->update('confessions', $data, 'id = :id', ['id' => $id]);
    }

    public function softDelete(int $id): void
    {
        $this->query->update('confessions', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    /**
     * 板块内容分页（仅公开可见：approved 且未删除）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function feed(string $board, string $sort, string $category, int $page, int $perPage): array
    {
        $where = "FROM confessions c
                  LEFT JOIN users u ON u.id = c.creator_id
                  LEFT JOIN user_profiles pr ON pr.user_id = c.creator_id
                  WHERE c.board = :board AND c.status = 'approved' AND c.deleted_at IS NULL";
        $params = ['board' => $board];

        if ($category !== '') {
            $where .= ' AND c.category = :category';
            $params['category'] = $category;
        }

        $order = match ($sort) {
            'hot' => 'ORDER BY c.is_featured DESC,
                      (c.like_count * 3 + c.comment_count * 4 + 1) / POW(TIMESTAMPDIFF(HOUR, c.created_at, NOW()) + 2, 1.2) DESC,
                      c.id DESC',
            'featured' => 'ORDER BY c.is_featured DESC, c.id DESC',
            'random' => 'ORDER BY RAND()',
            default => 'ORDER BY c.id DESC',
        };

        $sql = 'SELECT c.*, u.username AS author_username, pr.nickname AS author_nickname ' . $where . ' ' . $order;
        $countSql = 'SELECT COUNT(*) ' . $where;

        return $this->query->paginate($sql, $countSql, $params, $page, $perPage);
    }

    /**
     * 某用户（或游客凭证）投稿记录。
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function byUser(int $userId): array
    {
        return $this->query->all(
            'SELECT c.* FROM confessions c
             WHERE c.creator_id = :user_id AND c.deleted_at IS NULL
             ORDER BY c.id DESC LIMIT 100',
            ['user_id' => $userId],
        );
    }

    /**
     * 游客凭证投稿（按 id 集合）。
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function byGuestIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':id_' . $i;
            $params['id_' . $i] = (int) $id;
        }

        return $this->query->all(
            sprintf(
                'SELECT * FROM confessions WHERE id IN (%s) AND deleted_at IS NULL ORDER BY id DESC LIMIT 100',
                implode(', ', $placeholders),
            ),
            $params,
        );
    }

    /** @return list<array<string, mixed>> */
    public function mediaFor(int $confessionId): array
    {
        return $this->query->all(
            'SELECT m.* FROM confession_media cm JOIN media m ON m.id = cm.media_id
             WHERE cm.confession_id = :id ORDER BY cm.sort_order ASC, m.id ASC',
            ['id' => $confessionId],
        );
    }

    /**
     * @param list<int> $confessionIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function mediaForMany(array $confessionIds): array
    {
        if ($confessionIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values($confessionIds) as $i => $id) {
            $placeholders[] = ':m_' . $i;
            $params['m_' . $i] = (int) $id;
        }

        $rows = $this->query->all(
            sprintf(
                'SELECT cm.confession_id, m.* FROM confession_media cm JOIN media m ON m.id = cm.media_id
                 WHERE cm.confession_id IN (%s) ORDER BY cm.sort_order ASC, m.id ASC',
                implode(', ', $placeholders),
            ),
            $params,
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['confession_id']][] = $row;
        }

        return $grouped;
    }

    public function attachMedia(int $confessionId, int $mediaId, int $order): void
    {
        $this->query->insert('confession_media', [
            'confession_id' => $confessionId,
            'media_id' => $mediaId,
            'sort_order' => $order,
        ]);
    }

    public function incrementCounter(int $id, string $column, int $delta): void
    {
        if (!in_array($column, ['like_count', 'comment_count', 'favorite_count', 'report_count'], true)) {
            return;
        }

        $this->query->run(
            sprintf('UPDATE confessions SET %s = GREATEST(0, %s + :delta) WHERE id = :id', $column, $column),
            ['delta' => $delta, 'id' => $id],
        );
    }

    // ---------------------------------------------------------------- 评论

    /** @return array<string, mixed>|null */
    public function findComment(int $id): ?array
    {
        return $this->query->one('SELECT * FROM confession_comments WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $data */
    public function createComment(array $data): int
    {
        return $this->query->insert('confession_comments', $data);
    }

    public function setCommentStatus(int $id, string $status): void
    {
        $this->query->update('confession_comments', ['status' => $status], 'id = :id', ['id' => $id]);
    }

    /**
     * 顶层评论分页（时间正序），并附本页回复。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function commentPage(int $confessionId, int $page, int $perPage): array
    {
        $sql = "SELECT * FROM confession_comments
                WHERE confession_id = :confession_id AND parent_id IS NULL AND status = 'published'
                ORDER BY id ASC";
        $countSql = "SELECT COUNT(*) FROM confession_comments
                     WHERE confession_id = :confession_id AND parent_id IS NULL AND status = 'published'";
        $params = ['confession_id' => $confessionId];

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);

        $topIds = array_map(static fn (array $row): int => (int) $row['id'], $result['items']);

        if ($topIds !== []) {
            $placeholders = [];
            $replyParams = [];

            foreach (array_values($topIds) as $i => $id) {
                $placeholders[] = ':p_' . $i;
                $replyParams['p_' . $i] = $id;
            }

            // 注意：原生预处理（EMULATE_PREPARES=false）下传入 SQL 未使用的绑定键会报 HY093，
            // 因此这里只传 IN 占位符参数，不能复用含 confession_id 的 $params。
            $replies = $this->query->all(
                sprintf(
                    "SELECT * FROM confession_comments
                     WHERE parent_id IN (%s) AND status = 'published' ORDER BY id ASC",
                    implode(', ', $placeholders),
                ),
                $replyParams,
            );

            $grouped = [];
            foreach ($replies as $reply) {
                $grouped[(int) $reply['parent_id']][] = $reply;
            }

            foreach ($result['items'] as &$item) {
                $item['replies'] = $grouped[(int) $item['id']] ?? [];
            }
            unset($item);
        }

        return $result;
    }

    // ---------------------------------------------------------------- 统计

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        $rows = $this->query->all(
            "SELECT status, COUNT(*) AS total FROM confessions WHERE deleted_at IS NULL GROUP BY status",
        );

        $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'hidden' => 0, 'removed' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * 后台审核队列分页。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function moderationPage(string $status, int $page, int $perPage): array
    {
        $where = 'FROM confessions c LEFT JOIN users u ON u.id = c.creator_id WHERE c.deleted_at IS NULL';
        $params = [];

        if ($status !== '') {
            $where .= ' AND c.status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT c.*, u.username AS author_username ' . $where . ' ORDER BY c.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $where;

        return $this->query->paginate($sql, $countSql, $params, $page, $perPage);
    }
}
