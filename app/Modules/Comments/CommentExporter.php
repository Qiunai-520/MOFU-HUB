<?php

declare(strict_types=1);

namespace Mofu\Modules\Comments;

use Mofu\Core\Database\Query;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;

/**
 * 站内评论 → Twikoo 导入文件。
 *
 * Twikoo 管理面板的「导入评论」支持三种源：Twikoo / Valine / Disqus / Artalk。
 * 这里产出的就是 **Twikoo 自己那份备份格式**（服务端 commentImportTwikoo 只做归一化、
 * 不做字段映射），所以字段名必须和它的存储文档一致：
 * _id / nick / avatar / link / comment(HTML) / url / created(毫秒) / pid / rid / isSpam / master。
 *
 * 关键点：url 必须是前端调用 Twikoo 时传的 path，即 `/posts/{id}`，否则导入后评论挂不到帖子上。
 */
final class CommentExporter
{
    /** 导入后的评论 id 前缀，避免与 Twikoo 已有数据撞 id */
    private const ID_PREFIX = 'mofu-';

    public function __construct(private readonly Query $query)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function twikoo(): array
    {
        $rows = $this->query->all(
            'SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.like_count, c.created_at,
                    u.username, pr.nickname,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM comments c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles pr ON pr.user_id = c.user_id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE c.status = :status
             ORDER BY c.id ASC',
            ['status' => 'published'],
        );

        // 先建索引，便于向上回溯根评论（rid）
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $items = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
            $created = (int) (strtotime((string) $row['created_at']) * 1000);
            $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
                ? (string) $row['nickname']
                : (string) $row['username'];

            $item = [
                '_id' => self::ID_PREFIX . $id,
                'nick' => $nickname,
                'avatar' => Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                ),
                'link' => '/u/' . (string) $row['username'],
                'mail' => '',
                'mailMd5' => '',
                'ua' => '',
                'ip' => '',
                'comment' => Text::renderContent((string) $row['content']),
                'url' => '/posts/' . (int) $row['post_id'],
                'created' => $created,
                'updated' => $created,
                'like' => (int) $row['like_count'],
                'isSpam' => false,
                'master' => false,
            ];

            if ($parentId !== null) {
                $item['pid'] = self::ID_PREFIX . $parentId;

                $rootId = $this->rootOf($byId, $parentId);

                if ($rootId !== null) {
                    $item['rid'] = self::ID_PREFIX . $rootId;
                }
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * 向上回溯到所属的顶层评论（Twikoo 用 rid 记录「属于哪条一级评论」）。
     *
     * @param array<int, array<string, mixed>> $byId
     */
    private function rootOf(array $byId, int $startId): ?int
    {
        $cursor = $startId;

        // 历史数据可能有环，限制最大深度
        for ($depth = 0; $depth < 32; $depth++) {
            if (!isset($byId[$cursor])) {
                return null;
            }

            $parent = $byId[$cursor]['parent_id'];

            if ($parent === null) {
                return $cursor;
            }

            $cursor = (int) $parent;
        }

        return null;
    }
}
