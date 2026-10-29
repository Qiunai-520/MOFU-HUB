<?php

declare(strict_types=1);

namespace Mofu\Modules\Comments;

use Mofu\Core\Database\Query;

final class CommentRepository
{
    public function __construct(private readonly Query $query)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->query->one(
            'SELECT c.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM comments c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE c.id = :id',
            ['id' => $id],
        );
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        return $this->query->insert('comments', $data);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->query->update('comments', ['status' => $status], 'id = :id', ['id' => $id]);
    }

    public function hardDelete(int $id): void
    {
        $this->query->delete('comments', 'id = :id', ['id' => $id]);
    }

    /** 顶层评论分页（时间正序） */
    public function topLevelPage(int $postId, int $page, int $perPage): array
    {
        $sql = 'SELECT c.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                FROM comments c
                JOIN users u ON u.id = c.user_id
                LEFT JOIN user_profiles pr ON pr.user_id = u.id
                LEFT JOIN media am ON am.id = pr.avatar_media_id
                WHERE c.post_id = :post_id AND c.parent_id IS NULL AND c.status = :status
                ORDER BY c.id ASC';
        $countSql = 'SELECT COUNT(*) FROM comments c
                     WHERE c.post_id = :post_id AND c.parent_id IS NULL AND c.status = :status';
        $params = ['post_id' => $postId, 'status' => 'published'];

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);

        // 附带本页顶层评论的回复
        $topIds = array_map(static fn (array $row): int => (int) $row['id'], $result['items']);

        if ($topIds !== []) {
            $placeholders = [];
            $params = ['status' => 'published'];
            foreach ($topIds as $i => $id) {
                $placeholders[] = ':p_' . $i;
                $params['p_' . $i] = $id;
            }

            $replies = $this->query->all(
                sprintf(
                    'SELECT c.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge,
                            ru.username AS reply_to_username, rp.nickname AS reply_to_nickname,
                            am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                     FROM comments c
                     JOIN users u ON u.id = c.user_id
                     LEFT JOIN user_profiles pr ON pr.user_id = u.id
                     LEFT JOIN media am ON am.id = pr.avatar_media_id
                     LEFT JOIN users ru ON ru.id = c.reply_to_user_id
                     LEFT JOIN user_profiles rp ON rp.user_id = ru.id
                     WHERE c.parent_id IN (%s) AND c.status = :status
                     ORDER BY c.id ASC',
                    implode(', ', $placeholders),
                ),
                $params,
            );

            $repliesByParent = [];
            foreach ($replies as $reply) {
                $repliesByParent[(int) $reply['parent_id']][] = $reply;
            }

            foreach ($result['items'] as &$item) {
                $item['replies'] = $repliesByParent[(int) $item['id']] ?? [];
            }
            unset($item);
        }

        return $result;
    }

    /** 已发布评论数（真实数据统计用） */
    public function publishedCount(): int
    {
        return (int) $this->query->value("SELECT COUNT(*) FROM comments WHERE status = 'published'");
    }
}
