<?php

declare(strict_types=1);

namespace Mofu\Modules\Tags;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;

/**
 * 标签体系：标签库由后台维护，作者（Lv3+）从库里为自己投稿的帖子选择标签。
 *
 * 与「话题（topics）」的区别：话题是作者自由输入的 #词，标签是后台维护的固定集合，
 * 用于归类与筛选（可着色）。
 */
final class TagService
{
    public const MAX_PER_POST = 5;

    public function __construct(
        private readonly Query $query,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogue(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM tags';

        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }

        $sql .= ' ORDER BY sort ASC, id ASC';

        return array_map($this->present(...), $this->query->all($sql));
    }

    /** @return list<array<string, mixed>> 后台列表（含未启用） */
    public function adminList(): array
    {
        return $this->catalogue(false);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->query->one('SELECT * FROM tags WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    /** @return array<string, mixed>|null */
    public function findRawBySlug(string $slug): ?array
    {
        return $this->query->one('SELECT * FROM tags WHERE slug = :slug', ['slug' => $slug]);
    }

    /**
     * 把前端传来的 slug 列表收敛为「库里已存在且已启用」的标签 id。
     *
     * @param list<string> $slugs
     * @return list<int>
     */
    public function idsBySlugs(array $slugs): array
    {
        $out = [];

        foreach ($slugs as $slug) {
            if (!is_string($slug)) {
                continue;
            }

            $slug = trim($slug);

            if ($slug === '') {
                continue;
            }

            $id = $this->query->value('SELECT id FROM tags WHERE slug = :slug AND active = 1', ['slug' => $slug]);

            if ($id !== null) {
                $out[] = (int) $id;
            }

            if (count($out) >= self::MAX_PER_POST) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 批量取多条帖子的标签。
     *
     * @param list<int> $postIds
     * @return array<int, list<array<string, mixed>>> post_id => tags
     */
    public function forPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_filter($postIds, static fn (int $id): bool => $id > 0)));

        if ($postIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($postIds), '?'));
        $rows = $this->query->all(
            sprintf(
                'SELECT pt.post_id, t.* FROM post_tags pt JOIN tags t ON t.id = pt.tag_id
                 WHERE pt.post_id IN (%s) ORDER BY t.sort ASC, t.id ASC',
                $placeholders,
            ),
            $postIds,
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row['post_id']][] = $this->present($row);
        }

        return $grouped;
    }

    /** @return list<array<string, mixed>> */
    public function forPost(int $postId): array
    {
        return $this->forPosts([$postId])[$postId] ?? [];
    }

    /**
     * 同步一条帖子的标签关联（事务内调用）。
     *
     * @param list<int> $tagIds
     */
    public function syncPostTags(Query $q, int $postId, array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter($tagIds, static fn (int $id): bool => $id > 0)));
        $tagIds = array_slice($tagIds, 0, self::MAX_PER_POST);

        $q->run('DELETE FROM post_tags WHERE post_id = :post_id', ['post_id' => $postId]);

        $now = date('Y-m-d H:i:s');

        foreach ($tagIds as $tagId) {
            $q->run(
                'INSERT IGNORE INTO post_tags (post_id, tag_id, created_at) VALUES (:post_id, :tag_id, :now)',
                ['post_id' => $postId, 'tag_id' => $tagId, 'now' => $now],
            );
        }
    }

    /**
     * 后台新建 / 更新标签。
     *
     * @param array<string, mixed> $data
     */
    public function save(?int $id, array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw HttpException::badRequest('请填写标签名称');
        }

        $name = mb_substr($name, 0, 32);
        $slug = trim((string) ($data['slug'] ?? ''));

        if ($slug === '') {
            $slug = Text::slugifyTopic($name);
        } else {
            $slug = mb_substr(Text::slugifyTopic($slug), 0, 48);
        }

        $payload = [
            'slug' => $slug,
            'name' => $name,
            'description' => mb_substr(trim((string) ($data['description'] ?? '')), 0, 255),
            'color' => mb_substr(trim((string) ($data['color'] ?? '')), 0, 16),
            'sort' => (int) ($data['sort'] ?? 0),
            'active' => array_key_exists('active', $data) && !$data['active'] ? 0 : 1,
        ];

        $existing = $this->query->value('SELECT id FROM tags WHERE slug = :slug', ['slug' => $slug]);

        if ($existing !== null && ($id === null || (int) $existing !== $id)) {
            throw HttpException::badRequest('该标识已被占用，请换一个');
        }

        if ($id !== null && $id > 0) {
            $this->query->update('tags', $payload, 'id = :id', ['id' => $id]);

            return $id;
        }

        return $this->query->insert('tags', $payload + ['created_at' => date('Y-m-d H:i:s')]);
    }

    public function delete(int $id): void
    {
        $this->query->delete('tags', 'id = :id', ['id' => $id]);
    }

    /**
     * 某标签下的公开帖子分页。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function paginatePosts(int $tagId, int $page, int $perPage): array
    {
        $base = 'FROM posts p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 JOIN post_tags pt ON pt.post_id = p.id
                 WHERE pt.tag_id = :tag_id AND p.status = :status AND p.visibility = :visibility
                   AND p.deleted_at IS NULL AND p.community_id IS NULL';
        $params = ['tag_id' => $tagId, 'status' => 'published', 'visibility' => 'public'];

        return $this->query->paginate(
            'SELECT p.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             ' . $base . ' ORDER BY p.id DESC',
            'SELECT COUNT(*) ' . $base,
            $params,
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'color' => (string) ($row['color'] ?? ''),
            'sort' => (int) ($row['sort'] ?? 0),
            'active' => (int) ($row['active'] ?? 1) === 1,
            'url' => '/tags/' . rawurlencode((string) $row['slug']),
        ];
    }
}
