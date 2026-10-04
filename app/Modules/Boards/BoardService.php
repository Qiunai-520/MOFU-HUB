<?php

declare(strict_types=1);

namespace Mofu\Modules\Boards;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 统一板块框架：论坛/问答/互助/学习/失物/二手共用 boards + board_entries，
 * 通过 boards.kind 与 board_entries.extra(JSON) 做差异化（价格、成色、地点、状态机）。
 */
final class BoardService
{
    public const TITLE_MIN = 2;
    public const TITLE_MAX = 80;
    public const CONTENT_MAX = 5000;
    public const COMMENT_MAX = 1000;

    /** kind 对应的状态机（qa/help 是否解决、market 是否售出） @var array<string, array<string, string>> */
    public const STATES = [
        'qa' => ['pending' => '待解决', 'solved' => '已解决'],
        'help' => ['pending' => '求助中', 'solved' => '已解决'],
        'market' => ['selling' => '在售', 'sold' => '已售'],
    ];

    /** @var array<string, string> */
    public const LOST_TYPES = ['lost' => '寻物', 'found' => '招领'];

    /** @var array<string, string> */
    public const CONDITIONS = [
        'brand_new' => '全新',
        'like_new' => '几乎全新',
        'used' => '有使用痕迹',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly NotificationService $notifications,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->query->all(
            'SELECT b.*, am.disk_path AS logo_disk, am.thumb_path AS logo_thumb
             FROM boards b LEFT JOIN media am ON am.id = b.logo_media_id
             WHERE b.is_active = 1 ORDER BY b.sort_order ASC, b.id ASC',
        );

        return array_map($this->presentBoard(...), $rows);
    }

    /** 后台：全部板块（含停用），用于设置 Logo */
    public function adminBoards(): array
    {
        $rows = $this->query->all(
            'SELECT b.*, am.disk_path AS logo_disk, am.thumb_path AS logo_thumb
             FROM boards b LEFT JOIN media am ON am.id = b.logo_media_id
             ORDER BY b.sort_order ASC, b.id ASC',
        );

        return array_map($this->presentBoard(...), $rows);
    }

    /** 设置板块 Logo（传 null 表示清除） */
    public function setLogo(int $boardId, ?int $mediaId): void
    {
        $this->query->update('boards', ['logo_media_id' => $mediaId], 'id = :id', ['id' => $boardId]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->query->one(
            'SELECT * FROM boards WHERE slug = :slug AND is_active = 1',
            ['slug' => $slug],
        );
    }

    /** @return array<string, mixed>|null */
    public function findActiveById(int $id): ?array
    {
        return $this->query->one(
            'SELECT * FROM boards WHERE id = :id AND is_active = 1',
            ['id' => $id],
        );
    }

    /** @return array<int, int> board_id => 帖子数 */
    public function counts(): array
    {
        $rows = $this->query->all(
            "SELECT board_id, COUNT(*) AS total FROM board_entries
             WHERE status = 'published' AND deleted_at IS NULL GROUP BY board_id",
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['board_id']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * 板块帖子分页（filter 按 kind 解释：qa/help 状态、market 状态、lost 类型）。
     *
     * @param array<string, mixed> $board
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listPage(array $board, string $filter, string $sort, int $page, int $perPage = 15): array
    {
        $base = "FROM board_entries e
                 JOIN users u ON u.id = e.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE e.board_id = :board AND e.status = 'published' AND e.deleted_at IS NULL";
        $params = ['board' => (int) $board['id']];

        $kind = (string) $board['kind'];

        if ($filter !== '' && isset(self::STATES[$kind][$filter])) {
            // 用文本匹配（兼容旧版数据库）；extra 由 PHP json_encode 写入，格式固定
            $base .= ' AND CAST(e.extra AS CHAR) LIKE :state';
            $params['state'] = '%"state":"' . $filter . '"%';
        } elseif ($kind === 'lost' && isset(self::LOST_TYPES[$filter])) {
            $base .= ' AND CAST(e.extra AS CHAR) LIKE :lost_type';
            $params['lost_type'] = '%"type":"' . $filter . '"%';
        }

        $order = $sort === 'hot'
            ? 'ORDER BY (e.like_count * 3 + e.comment_count * 4) DESC, e.id DESC'
            : 'ORDER BY e.id DESC';

        $sql = 'SELECT e.*, u.username, u.status AS author_status, pr.nickname,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                ' . $base . ' ' . $order;
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = array_map(fn (array $row): array => $this->presentEntry($row, $board), $result['items']);

        return $result;
    }

    /**
     * 发布板块帖子。
     *
     * @param array<string, mixed> $board
     * @param array<string, mixed> $input
     */
    public function create(int $userId, array $board, array $input): int
    {
        $title = trim((string) ($input['title'] ?? ''));
        $content = trim((string) ($input['content'] ?? ''));

        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw HttpException::badRequest('标题需为 ' . self::TITLE_MIN . '-' . self::TITLE_MAX . ' 字');
        }

        if ($content === '' || mb_strlen($content) > self::CONTENT_MAX) {
            throw HttpException::badRequest('正文需为 1-' . self::CONTENT_MAX . ' 字');
        }

        $kind = (string) $board['kind'];
        $extra = $this->buildExtra($kind, $input);

        if (isset(self::STATES[$kind])) {
            $extra['state'] = (string) array_key_first(self::STATES[$kind]);
        }

        return $this->query->insert('board_entries', [
            'board_id' => (int) $board['id'],
            'user_id' => $userId,
            'title' => $title,
            'content' => $content,
            'extra' => $extra === [] ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
            'status' => 'published',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildExtra(string $kind, array $input): array
    {
        switch ($kind) {
            case 'market': {
                $price = trim((string) ($input['price'] ?? ''));
                $condition = (string) ($input['condition'] ?? '');

                if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $price) || (float) $price <= 0) {
                    throw HttpException::badRequest('请填写有效的价格（元，最多两位小数）');
                }

                if (!isset(self::CONDITIONS[$condition])) {
                    throw HttpException::badRequest('请选择成色');
                }

                return ['price' => $price, 'condition' => $condition];
            }

            case 'lost': {
                $type = (string) ($input['lost_type'] ?? '');

                if (!isset(self::LOST_TYPES[$type])) {
                    throw HttpException::badRequest('请选择寻物或招领');
                }

                $location = trim((string) ($input['location'] ?? ''));

                if (mb_strlen($location) > 40) {
                    throw HttpException::badRequest('地点最多 40 字');
                }

                $happenedOn = trim((string) ($input['happened_on'] ?? ''));

                if ($happenedOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $happenedOn)) {
                    throw HttpException::badRequest('日期格式不正确');
                }

                return array_filter([
                    'type' => $type,
                    'location' => $location,
                    'date' => $happenedOn,
                ], static fn (mixed $value): bool => $value !== '');
            }
        }

        return [];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->query->one(
            'SELECT e.*, u.username, u.status AS author_status, pr.nickname,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path,
                    b.slug AS board_slug, b.name AS board_name, b.kind AS board_kind, b.icon AS board_icon
             FROM board_entries e
             JOIN users u ON u.id = e.user_id
             JOIN boards b ON b.id = e.board_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE e.id = :id',
            ['id' => $id],
        );
    }

    /**
     * 切换帖子状态（问答/互助：待解决↔已解决；二手：在售↔已售），仅作者或管理员。
     *
     * @param array<string, mixed> $entry
     */
    public function toggleState(array $entry, int $userId, bool $canModerate): void
    {
        $kind = (string) $entry['board_kind'];
        $states = self::STATES[$kind] ?? null;

        if ($states === null) {
            throw HttpException::badRequest('该板块没有可切换的状态');
        }

        if ((int) $entry['user_id'] !== $userId && !$canModerate) {
            throw HttpException::forbidden();
        }

        $keys = array_keys($states);
        $current = (string) ($this->extra($entry)['state'] ?? $keys[0]);
        $next = $current === $keys[0] ? ($keys[1] ?? $keys[0]) : $keys[0];

        $extra = $this->extra($entry);
        $extra['state'] = $next;

        $this->query->update('board_entries', [
            'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $entry['id']]);
    }

    /**
     * 当前用户是否对帖子产生过某种互动（like / favorite）。
     */
    public function hasReaction(int $entryId, int $userId, string $kind): bool
    {
        return $this->query->value(
            'SELECT 1 FROM reactions
             WHERE user_id = :user AND target_type = :type AND target_id = :id AND kind = :kind',
            ['user' => $userId, 'type' => 'board_entry', 'id' => $entryId, 'kind' => $kind],
        ) !== null;
    }

    /** @param array<string, mixed> $entry */
    public function deleteEntry(array $entry, int $userId, bool $canModerate): void
    {
        if ((int) $entry['user_id'] !== $userId && !$canModerate) {
            throw HttpException::forbidden();
        }

        $this->query->update('board_entries', [
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $entry['id']]);
    }

    /**
     * 帖子回复（扁平）。
     *
     * @return list<array<string, mixed>>
     */
    public function comments(int $entryId): array
    {
        $rows = $this->query->all(
            "SELECT c.*, u.username, pr.nickname,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM board_entry_comments c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE c.entry_id = :entry AND c.status = 'published'
             ORDER BY c.id ASC
             LIMIT 200",
            ['entry' => $entryId],
        );

        return array_map(static function (array $row): array {
            $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
                ? (string) $row['nickname']
                : (string) $row['username'];

            return [
                'id' => (int) $row['id'],
                'content_html' => Text::renderContent((string) $row['content']),
                'time_ago' => Text::relativeTime((string) $row['created_at']),
                'author' => [
                    'id' => (int) $row['user_id'],
                    'username' => (string) $row['username'],
                    'nickname' => $nickname,
                    'initial' => mb_substr($nickname, 0, 1),
                    'hue' => Present::avatarHue((int) $row['user_id']),
                    'url' => '/u/' . (string) $row['username'],
                    'avatar_url' => Present::uploadUrl(
                        isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                        isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                    ),
                ],
            ];
        }, $rows);
    }

    /**
     * 添加回复并通知楼主。
     *
     * @param array<string, mixed> $entry
     */
    public function addComment(array $entry, int $userId, string $content): void
    {
        $content = trim($content);

        if ($content === '' || mb_strlen($content) > self::COMMENT_MAX) {
            throw HttpException::badRequest('回复内容需为 1-' . self::COMMENT_MAX . ' 字');
        }

        $now = date('Y-m-d H:i:s');
        $entryId = (int) $entry['id'];

        $this->query->transaction(function (Query $q) use ($entryId, $userId, $content, $now): void {
            $q->insert('board_entry_comments', [
                'entry_id' => $entryId,
                'user_id' => $userId,
                'content' => $content,
                'status' => 'published',
                'created_at' => $now,
            ]);

            $q->run(
                'UPDATE board_entries SET comment_count = comment_count + 1 WHERE id = :id',
                ['id' => $entryId],
            );
        });

        $authorId = (int) $entry['user_id'];

        if ($authorId !== $userId) {
            $this->notifications->push($authorId, $userId, 'entry_commented', 'board_entry', $entryId, [
                'excerpt' => Text::excerpt($content, 60),
                'url' => self::entryUrl((string) $entry['board_slug'], $entryId),
            ]);
        }
    }

    public static function entryUrl(string $boardSlug, int $entryId): string
    {
        return '/boards/' . rawurlencode($boardSlug) . '/' . $entryId;
    }

    // ---------------------------------------------------------------- 后台管理

    /**
     * 后台帖子列表（跨板块）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function adminPage(string $status, string $boardSlug, int $page, int $perPage = 20): array
    {
        $conditions = [];
        $params = [];

        if (in_array($status, ['published', 'hidden', 'removed'], true)) {
            $conditions[] = 'e.status = :status';
            $params['status'] = $status;
        }

        if ($boardSlug !== '') {
            $conditions[] = 'b.slug = :board';
            $params['board'] = $boardSlug;
        }

        $where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);

        $base = 'FROM board_entries e
                 JOIN boards b ON b.id = e.board_id
                 JOIN users u ON u.id = e.user_id
                 WHERE ' . $where;
        $sql = 'SELECT e.*, b.slug AS board_slug, b.name AS board_name, u.username ' . $base . ' ORDER BY e.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'excerpt' => Text::excerpt((string) $row['content'], 60),
                'board_name' => (string) $row['board_name'],
                'board_url' => '/boards/' . rawurlencode((string) $row['board_slug']),
                'username' => (string) $row['username'],
                'status' => (string) $row['status'],
                'deleted' => $row['deleted_at'] !== null,
                'like_count' => (int) $row['like_count'],
                'comment_count' => (int) $row['comment_count'],
                'time_ago' => Text::relativeTime((string) $row['created_at']),
                'url' => self::entryUrl((string) $row['board_slug'], (int) $row['id']),
            ];
        }, $result['items']);

        return $result;
    }

    public function setStatus(int $entryId, string $status): void
    {
        if (!in_array($status, ['published', 'hidden', 'removed'], true)) {
            throw HttpException::badRequest('状态无效');
        }

        if ($this->query->value('SELECT 1 FROM board_entries WHERE id = :id', ['id' => $entryId]) === null) {
            throw HttpException::notFound('帖子不存在');
        }

        $this->query->update('board_entries', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $entryId]);
    }

    public function adminDelete(int $entryId): void
    {
        if ($this->query->value('SELECT 1 FROM board_entries WHERE id = :id', ['id' => $entryId]) === null) {
            throw HttpException::notFound('帖子不存在');
        }

        $this->query->update('board_entries', [
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $entryId]);
    }

    // ---------------------------------------------------------------- 展示

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentBoard(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'kind' => (string) $row['kind'],
            'icon' => (string) $row['icon'],
            'logo' => Present::uploadUrl(
                isset($row['logo_thumb']) ? (string) $row['logo_thumb'] : null,
                isset($row['logo_disk']) ? (string) $row['logo_disk'] : null,
            ),
            'description' => (string) $row['description'],
            'url' => '/boards/' . rawurlencode((string) $row['slug']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    public function presentEntry(array $row, array $board): array
    {
        $extra = $this->extra($row);
        $kind = (string) $board['kind'];
        $authorId = (int) $row['user_id'];
        $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
            ? (string) $row['nickname']
            : (string) $row['username'];

        $badges = [];
        $stateLabel = null;

        if (isset(self::STATES[$kind])) {
            $keys = array_keys(self::STATES[$kind]);
            $stateKey = (string) ($extra['state'] ?? $keys[0]);
            $stateLabel = self::STATES[$kind][$stateKey] ?? null;

            if ($stateLabel !== null) {
                $badges[] = [
                    'label' => $stateLabel,
                    'variant' => $stateKey === $keys[0] ? 'accent' : 'muted',
                ];
            }
        }

        if ($kind === 'market') {
            if (isset($extra['price'])) {
                $badges[] = ['label' => '¥' . (string) $extra['price'], 'variant' => 'accent'];
            }

            if (isset(self::CONDITIONS[(string) ($extra['condition'] ?? '')])) {
                $badges[] = ['label' => self::CONDITIONS[(string) $extra['condition']], 'variant' => 'muted'];
            }
        }

        if ($kind === 'lost') {
            $lostType = (string) ($extra['type'] ?? '');

            if (isset(self::LOST_TYPES[$lostType])) {
                $badges[] = ['label' => self::LOST_TYPES[$lostType], 'variant' => $lostType === 'lost' ? 'warn' : 'accent'];
            }

            if (($extra['location'] ?? '') !== '') {
                $badges[] = ['label' => '地点：' . (string) $extra['location'], 'variant' => 'muted'];
            }

            if (($extra['date'] ?? '') !== '') {
                $badges[] = ['label' => (string) $extra['date'], 'variant' => 'muted'];
            }
        }

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'content_html' => Text::renderContent((string) $row['content']),
            'excerpt' => Text::excerpt((string) $row['content']),
            'status' => (string) $row['status'],
            'is_hidden' => (string) $row['status'] !== 'published',
            'badges' => $badges,
            'state_label' => $stateLabel,
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
            'time_ago' => Text::relativeTime((string) $row['created_at']),
            'like_count' => (int) $row['like_count'],
            'comment_count' => (int) $row['comment_count'],
            'favorite_count' => (int) $row['favorite_count'],
            'url' => self::entryUrl((string) $board['slug'], (int) $row['id']),
            'board' => [
                'id' => (int) $board['id'],
                'slug' => (string) $board['slug'],
                'name' => (string) $board['name'],
                'url' => '/boards/' . rawurlencode((string) $board['slug']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function extra(array $entry): array
    {
        $raw = $entry['extra'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
