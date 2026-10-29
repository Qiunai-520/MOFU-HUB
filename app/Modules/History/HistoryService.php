<?php

declare(strict_types=1);

namespace Mofu\Modules\History;

use Mofu\Core\Database\Query;
use Mofu\Core\Support\Text;
use Mofu\Modules\Posts\FeedService;

/**
 * 浏览记录：登录用户查看动态详情时记录，可列表回看与一键清空。
 * 仅回看公开可见内容（隐藏/移除/删除与圈子帖不出现）。
 */
final class HistoryService
{
    public function __construct(
        private readonly Query $query,
        private readonly FeedService $feed,
    ) {
    }

    public function record(int $userId, int $postId): void
    {
        $now = date('Y-m-d H:i:s');

        $this->query->run(
            'INSERT INTO post_views (user_id, post_id, viewed_at) VALUES (:user_id, :post_id, :viewed_at)
             ON DUPLICATE KEY UPDATE viewed_at = :viewed_again',
            ['user_id' => $userId, 'post_id' => $postId, 'viewed_at' => $now, 'viewed_again' => $now],
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listPage(int $userId, int $page, int $perPage = 10): array
    {
        $base = "FROM post_views pv
                 JOIN posts p ON p.id = pv.post_id
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE pv.user_id = :user
                   AND p.deleted_at IS NULL
                   AND p.status = 'published'
                   AND p.community_id IS NULL
                   AND (p.visibility = 'public' OR p.user_id = :self)";
        $params = ['user' => $userId, 'self' => $userId];

        $sql = 'SELECT p.*, pv.viewed_at, u.username, u.status AS author_status, pr.nickname, pr.avatar_frame, pr.name_badge,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                ' . $base . ' ORDER BY pv.viewed_at DESC, p.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);

        $viewedMap = [];
        foreach ($result['items'] as $row) {
            $viewedMap[(int) $row['id']] = (string) $row['viewed_at'];
        }

        $result['items'] = $this->feed->presentRows($result['items'], $userId, false);

        foreach ($result['items'] as $i => $item) {
            $viewed = $viewedMap[(int) $item['id']] ?? '';
            $result['items'][$i]['viewed_text'] = $viewed !== '' ? Text::relativeTime($viewed) : '';
        }

        return $result;
    }

    public function clear(int $userId): void
    {
        $this->query->delete('post_views', 'user_id = :user', ['user' => $userId]);
    }
}
