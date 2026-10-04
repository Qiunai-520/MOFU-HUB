<?php

declare(strict_types=1);

namespace Mofu\Modules\News;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;
use Mofu\Modules\Media\MediaService;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 校园资讯：管理员编辑发布（通知公告 / 校园新闻 / 学习资源），前台只读浏览。
 *
 * 首次发布（published 且 notified_at 为空）会广播通知全站活跃用户；
 * notified_at 同时作为幂等标记，避免重复广播。
 */
final class NewsService
{
    public const TITLE_MIN = 2;
    public const TITLE_MAX = 80;
    public const SUMMARY_MAX = 200;
    public const CONTENT_MAX = 20000;

    /** @var array<string, string> */
    public const CATEGORIES = [
        'notice' => '通知公告',
        'campus' => '校园新闻',
        'resource' => '学习资源',
    ];

    /** @var array<string, string> */
    public const STATUSES = [
        'draft' => '草稿',
        'published' => '已发布',
        'hidden' => '已隐藏',
        'removed' => '已移除',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly MediaService $media,
    ) {
    }

    // ---------------------------------------------------------------- 前台

    /**
     * 公开资讯列表（仅 published）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listPage(string $category, int $page, int $perPage = 10): array
    {
        $base = $this->baseFrom() . " WHERE n.status = 'published' AND n.deleted_at IS NULL";
        $params = [];

        if (isset(self::CATEGORIES[$category])) {
            $base .= ' AND n.category = :category';
            $params['category'] = $category;
        }

        $order = 'ORDER BY n.is_pinned DESC, n.published_at DESC, n.id DESC';
        $sql = 'SELECT n.*, ' . $this->selectExtras() . ' ' . $base . ' ' . $order;
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = array_map($this->present(...), $result['items']);

        return $result;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->query->one(
            'SELECT n.*, ' . $this->selectExtras() . ' ' . $this->baseFrom() . ' WHERE n.id = :id',
            ['id' => $id],
        );
    }

    /** @return array<string, mixed> */
    public function findOrFail(int $id): array
    {
        $news = $this->find($id);

        if ($news === null || $news['deleted_at'] !== null) {
            throw HttpException::notFound('资讯不存在或已删除');
        }

        return $news;
    }

    public function incrementView(int $id): void
    {
        $this->query->run('UPDATE news SET view_count = view_count + 1 WHERE id = :id', ['id' => $id]);
    }

    // ---------------------------------------------------------------- 管理

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function adminPage(string $status, string $category, int $page, int $perPage = 20): array
    {
        $conditions = [];
        $params = [];

        if (isset(self::STATUSES[$status])) {
            $conditions[] = 'n.status = :status';
            $params['status'] = $status;
        }

        if (isset(self::CATEGORIES[$category])) {
            $conditions[] = 'n.category = :category';
            $params['category'] = $category;
        }

        $where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);
        $base = $this->baseFrom() . ' WHERE ' . $where;
        $sql = 'SELECT n.*, ' . $this->selectExtras() . ' ' . $base . ' ORDER BY n.is_pinned DESC, n.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = array_map(static function (array $row): array {
            $publishedTs = $row['published_at'] !== null ? (strtotime((string) $row['published_at']) ?: 0) : 0;

            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'category' => (string) $row['category'],
                'category_label' => self::CATEGORIES[(string) $row['category']] ?? (string) $row['category'],
                'username' => (string) $row['username'],
                'status' => (string) $row['status'],
                'status_label' => self::STATUSES[(string) $row['status']] ?? (string) $row['status'],
                'is_pinned' => (int) $row['is_pinned'] === 1,
                'view_count' => (int) $row['view_count'],
                'published_text' => $publishedTs > 0 ? date('Y-m-d', $publishedTs) : '',
                'deleted' => $row['deleted_at'] !== null,
                'url' => '/news/' . (int) $row['id'],
                'edit_url' => '/admin/news/' . (int) $row['id'] . '/edit',
            ];
        }, $result['items']);

        return $result;
    }

    /**
     * 创建资讯（action=publish 则直接发布并广播）。
     *
     * @param array<string, mixed> $input
     */
    public function create(int $userId, array $input, ?UploadedFileInterface $cover): int
    {
        $data = $this->validate($input);
        $coverMediaId = $this->storeCover($userId, $cover);
        $publish = (string) ($input['action'] ?? '') === 'publish';
        $now = date('Y-m-d H:i:s');

        $newsId = $this->query->insert('news', [
            'user_id' => $userId,
            'title' => $data['title'],
            'summary' => $data['summary'],
            'content' => $data['content'],
            'category' => $data['category'],
            'cover_media_id' => $coverMediaId,
            'is_pinned' => $data['is_pinned'] ? 1 : 0,
            'status' => $publish ? 'published' : 'draft',
            'published_at' => $publish ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($publish) {
            $this->broadcastIfNeeded($newsId);
        }

        return $newsId;
    }

    /**
     * 编辑资讯（action=publish 触发发布）。
     *
     * @param array<string, mixed> $input
     */
    public function update(int $newsId, array $input, ?UploadedFileInterface $cover): void
    {
        $news = $this->findOrFail($newsId);
        $data = $this->validate($input);
        $coverMediaId = $this->storeCover((int) $news['user_id'], $cover);

        $update = [
            'title' => $data['title'],
            'summary' => $data['summary'],
            'content' => $data['content'],
            'category' => $data['category'],
            'is_pinned' => $data['is_pinned'] ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($coverMediaId !== null) {
            $update['cover_media_id'] = $coverMediaId;
        }

        $this->query->update('news', $update, 'id = :id', ['id' => $newsId]);

        if ((string) ($input['action'] ?? '') === 'publish') {
            $this->publish($newsId);
        }
    }

    public function publish(int $newsId): void
    {
        $news = $this->findOrFail($newsId);
        $now = date('Y-m-d H:i:s');

        $update = ['status' => 'published', 'updated_at' => $now];

        if ($news['published_at'] === null) {
            $update['published_at'] = $now;
        }

        $this->query->update('news', $update, 'id = :id', ['id' => $newsId]);

        $this->broadcastIfNeeded($newsId);
    }

    public function setStatus(int $newsId, string $status): void
    {
        if ($status === 'published') {
            $this->publish($newsId);

            return;
        }

        if (!in_array($status, ['draft', 'hidden', 'removed'], true)) {
            throw HttpException::badRequest('状态无效');
        }

        $this->findOrFail($newsId);

        $this->query->update('news', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $newsId]);
    }

    public function togglePin(int $newsId): void
    {
        $news = $this->findOrFail($newsId);

        $this->query->update('news', [
            'is_pinned' => (int) $news['is_pinned'] === 1 ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $newsId]);
    }

    public function adminDelete(int $newsId): void
    {
        $this->findOrFail($newsId);

        $this->query->update('news', [
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $newsId]);
    }

    // ---------------------------------------------------------------- 展示

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $authorId = (int) $row['user_id'];
        $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
            ? (string) $row['nickname']
            : (string) $row['username'];
        $publishedTs = $row['published_at'] !== null ? (strtotime((string) $row['published_at']) ?: 0) : 0;
        $summary = trim((string) $row['summary']);

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'summary_text' => $summary !== '' ? $summary : Text::excerpt((string) $row['content']),
            'content_html' => Text::renderContent((string) $row['content']),
            'category' => (string) $row['category'],
            'category_label' => self::CATEGORIES[(string) $row['category']] ?? (string) $row['category'],
            'is_pinned' => (int) $row['is_pinned'] === 1,
            'status' => (string) $row['status'],
            'status_label' => self::STATUSES[(string) $row['status']] ?? (string) $row['status'],
            'is_hidden' => (string) $row['status'] !== 'published',
            'view_count' => (int) $row['view_count'],
            'published_text' => $publishedTs > 0 ? date('Y-m-d', $publishedTs) : '',
            'time_ago' => $publishedTs > 0 ? Text::relativeTime((string) $row['published_at']) : '',
            'cover_url' => Present::uploadUrl(
                isset($row['cover_thumb']) ? (string) $row['cover_thumb'] : null,
                isset($row['cover_path']) ? (string) $row['cover_path'] : null,
            ),
            'author' => [
                'id' => $authorId,
                'username' => (string) $row['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($authorId),
                'url' => '/u/' . (string) $row['username'],
                'avatar_url' => Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                ),
            ],
            'url' => '/news/' . (int) $row['id'],
            'edit_url' => '/admin/news/' . (int) $row['id'] . '/edit',
        ];
    }

    // ---------------------------------------------------------------- 内部

    /**
     * @param array<string, mixed> $input
     * @return array{title: string, summary: string, content: string, category: string, is_pinned: bool}
     */
    private function validate(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $summary = trim((string) ($input['summary'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));
        $category = (string) ($input['category'] ?? '');
        $isPinned = (string) ($input['is_pinned'] ?? '') === '1';

        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw HttpException::badRequest('标题需为 ' . self::TITLE_MIN . '-' . self::TITLE_MAX . ' 字');
        }

        if (mb_strlen($summary) > self::SUMMARY_MAX) {
            throw HttpException::badRequest('摘要最多 ' . self::SUMMARY_MAX . ' 字');
        }

        if ($content === '' || mb_strlen($content) > self::CONTENT_MAX) {
            throw HttpException::badRequest('正文需为 1-' . self::CONTENT_MAX . ' 字');
        }

        if (!isset(self::CATEGORIES[$category])) {
            throw HttpException::badRequest('请选择资讯分类');
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'content' => $content,
            'category' => $category,
            'is_pinned' => $isPinned,
        ];
    }

    /** 首次发布时广播通知全站活跃用户（notified_at 幂等） */
    private function broadcastIfNeeded(int $newsId): void
    {
        $news = $this->query->one('SELECT id, user_id, title, notified_at FROM news WHERE id = :id', ['id' => $newsId]);

        if ($news === null) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $data = (string) json_encode([
            'message' => '新资讯：' . (string) $news['title'],
            'url' => '/news/' . (int) $news['id'],
        ], JSON_UNESCAPED_UNICODE);

        $this->query->transaction(function (Query $q) use ($newsId, $news, $data, $now): void {
            // 幂等闸门：只有把 notified_at 从 NULL 改为非 NULL 的那次才广播
            $claimed = $q->update('news', ['notified_at' => $now], 'id = :id AND notified_at IS NULL', ['id' => $newsId]);

            if ($claimed === 0) {
                return;
            }

            $q->run(
                "INSERT INTO notifications (user_id, actor_id, type, target_type, target_id, data, created_at)
                 SELECT id, NULL, 'news_published', 'news', :news, :data, :now
                 FROM users WHERE status = 'active' AND id != :author",
                [
                    'news' => (int) $newsId,
                    'data' => $data,
                    'now' => $now,
                    'author' => (int) $news['user_id'],
                ],
            );
        });
    }

    private function storeCover(int $userId, ?UploadedFileInterface $cover): ?int
    {
        if ($cover === null || $cover->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $media = $this->media->storeImage($userId, $cover);

        return (int) $media['id'];
    }

    private function selectExtras(): string
    {
        return 'u.username, u.status AS author_status, pr.nickname,
                cm.thumb_path AS cover_thumb, cm.disk_path AS cover_path,
                am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path';
    }

    private function baseFrom(): string
    {
        return 'FROM news n
                JOIN users u ON u.id = n.user_id
                LEFT JOIN user_profiles pr ON pr.user_id = u.id
                LEFT JOIN media cm ON cm.id = n.cover_media_id
                LEFT JOIN media am ON am.id = pr.avatar_media_id';
    }
}
