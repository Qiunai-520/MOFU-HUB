<?php

declare(strict_types=1);

namespace Mofu\Modules\Search;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\Json;
use Mofu\Modules\Confessions\ConfessionService;
use Mofu\Modules\Follows\FollowService;
use Mofu\Modules\Posts\FeedService;

/**
 * 全站搜索：动态 / 用户 / 话题 / 表白墙。
 *
 * 全部查询在服务端完成可见性过滤：动态仅公开且在架、表白墙仅审核通过、
 * 用户不含封禁账号；LIKE 通配符统一转义，避免输入被当作模式匹配。
 */
final class SearchService
{
    public const TYPES = ['all', 'posts', 'users', 'topics', 'confessions'];

    private const PER_PAGE = 10;
    private const PREVIEW = 5;

    public function __construct(
        private readonly Query $query,
        private readonly FeedService $feed,
        private readonly FollowService $follows,
        private readonly ConfessionService $confessions,
    ) {
    }

    /** 转义 LIKE 通配符（反斜杠最先处理） */
    public static function escapeLike(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $keyword, string $type, int $page, ?int $viewerId, bool $canModerate): array
    {
        $like = '%' . self::escapeLike($keyword) . '%';

        if ($type !== 'all') {
            return [
                'mode' => 'single',
                'type' => $type,
                'result' => match ($type) {
                    'posts' => $this->pagePosts($like, $viewerId, $canModerate, $page),
                    'users' => $this->pageUsers($like, $viewerId, $page),
                    'topics' => $this->pageTopics($like, $page),
                    'confessions' => $this->pageConfessions($like, $viewerId, $canModerate, $page),
                },
            ];
        }

        return [
            'mode' => 'all',
            'sections' => [
                'posts' => $this->sectionPosts($like, $viewerId, $canModerate),
                'users' => $this->sectionUsers($like, $viewerId),
                'topics' => $this->sectionTopics($like),
                'confessions' => $this->sectionConfessions($like, $viewerId, $canModerate),
            ],
        ];
    }

    /**
     * 搜索建议：匹配的话题与用户（轻量、限量，供输入时实时下拉）。
     *
     * @return array{topics: list<array{name: string, url: string}>, users: list<array{name: string, username: string, url: string}>}
     */
    public function suggest(string $keyword, int $limit = 5): array
    {
        $like = '%' . self::escapeLike($keyword) . '%';
        $cap = max(1, min($limit, 10));

        $topics = $this->query->all(
            sprintf('SELECT name, slug FROM topics WHERE name LIKE :like ORDER BY use_count DESC, id DESC LIMIT %d', $cap),
            ['like' => $like],
        );

        $users = $this->query->all(
            sprintf(
                'SELECT u.username, p.nickname
                 FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id
                 WHERE u.status = :status AND (u.username LIKE :like OR p.nickname LIKE :like2)
                 ORDER BY u.id DESC LIMIT %d',
                $cap,
            ),
            ['status' => 'active', 'like' => $like, 'like2' => $like],
        );

        return [
            'topics' => array_map(
                static fn (array $row): array => [
                    'name' => (string) $row['name'],
                    'url' => '/topics/' . rawurlencode((string) $row['slug']),
                ],
                $topics,
            ),
            'users' => array_map(
                static function (array $row): array {
                    $nickname = trim((string) ($row['nickname'] ?? ''));

                    return [
                        'name' => $nickname !== '' ? $nickname : (string) $row['username'],
                        'username' => (string) $row['username'],
                        'url' => '/u/' . rawurlencode((string) $row['username']),
                    ];
                },
                $users,
            ),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function hotTopics(int $limit = 12): array
    {
        return $this->query->all(
            'SELECT name, slug, use_count FROM topics ORDER BY use_count DESC, id DESC LIMIT ' . max(1, min($limit, 30)),
        );
    }

    // ---------------------------------------------------------------- 单类型分页

    /** @return array<string, mixed> */
    private function pagePosts(string $like, ?int $viewerId, bool $canModerate, int $page): array
    {
        [$where, $params] = $this->postsWhere($like);

        $result = $this->query->paginate(
            'SELECT p.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE ' . $where . ' ORDER BY p.id DESC',
            'SELECT COUNT(*) FROM posts p WHERE ' . $where,
            $params,
            $page,
            self::PER_PAGE,
        );

        $result['items'] = $this->feed->presentRows($result['items'], $viewerId, $canModerate);

        return $result;
    }

    /** @return array<string, mixed> */
    private function pageUsers(string $like, ?int $viewerId, int $page): array
    {
        [$where, $params] = $this->usersWhere($like);

        $result = $this->query->paginate(
            'SELECT u.id, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.bio,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM users u
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE ' . $where . ' ORDER BY u.id DESC',
            'SELECT COUNT(*) FROM users u LEFT JOIN user_profiles pr ON pr.user_id = u.id WHERE ' . $where,
            $params,
            $page,
            self::PER_PAGE,
        );

        $result['items'] = $this->follows->presentUsers($result['items'], $viewerId);

        return $result;
    }

    /** @return array<string, mixed> */
    private function pageTopics(string $like, int $page): array
    {
        [$where, $params] = $this->topicsWhere($like);

        return $this->query->paginate(
            'SELECT t.id, t.name, t.slug, t.use_count FROM topics t
             WHERE ' . $where . ' ORDER BY t.use_count DESC, t.id DESC',
            'SELECT COUNT(*) FROM topics t WHERE ' . $where,
            $params,
            $page,
            self::PER_PAGE,
        );
    }

    /** @return array<string, mixed> */
    private function pageConfessions(string $like, ?int $viewerId, bool $canModerate, int $page): array
    {
        [$where, $params] = $this->confessionsWhere($like);

        $result = $this->query->paginate(
            'SELECT c.*, u.username AS author_username, pr.nickname AS author_nickname
             FROM confessions c
             LEFT JOIN users u ON u.id = c.creator_id
             LEFT JOIN user_profiles pr ON pr.user_id = c.creator_id
             WHERE ' . $where . ' ORDER BY c.id DESC',
            'SELECT COUNT(*) FROM confessions c WHERE ' . $where,
            $params,
            $page,
            self::PER_PAGE,
        );

        $result['items'] = $this->confessions->presentRows($result['items'], $viewerId, $canModerate);

        return $result;
    }

    // ---------------------------------------------------------------- all 模式分组

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function sectionPosts(string $like, ?int $viewerId, bool $canModerate): array
    {
        [$where, $params] = $this->postsWhere($like);
        $total = (int) $this->query->value('SELECT COUNT(*) FROM posts p WHERE ' . $where, $params);

        $rows = $total > 0
            ? $this->query->all(
                'SELECT p.*, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.avatar_media_id,
                        am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                 FROM posts p
                 JOIN users u ON u.id = p.user_id
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE ' . $where . ' ORDER BY p.id DESC LIMIT ' . self::PREVIEW,
                $params,
            )
            : [];

        return ['total' => $total, 'items' => $this->feed->presentRows($rows, $viewerId, $canModerate)];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function sectionUsers(string $like, ?int $viewerId): array
    {
        [$where, $params] = $this->usersWhere($like);
        $total = (int) $this->query->value(
            'SELECT COUNT(*) FROM users u LEFT JOIN user_profiles pr ON pr.user_id = u.id WHERE ' . $where,
            $params,
        );

        $rows = $total > 0
            ? $this->query->all(
                'SELECT u.id, u.username, pr.nickname, pr.avatar_frame, pr.name_badge, pr.bio,
                        am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                 FROM users u
                 LEFT JOIN user_profiles pr ON pr.user_id = u.id
                 LEFT JOIN media am ON am.id = pr.avatar_media_id
                 WHERE ' . $where . ' ORDER BY u.id DESC LIMIT ' . self::PREVIEW,
                $params,
            )
            : [];

        return ['total' => $total, 'items' => $this->follows->presentUsers($rows, $viewerId)];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function sectionTopics(string $like): array
    {
        [$where, $params] = $this->topicsWhere($like);
        $total = (int) $this->query->value('SELECT COUNT(*) FROM topics t WHERE ' . $where, $params);

        $rows = $total > 0
            ? $this->query->all(
                'SELECT t.id, t.name, t.slug, t.use_count FROM topics t
                 WHERE ' . $where . ' ORDER BY t.use_count DESC, t.id DESC LIMIT ' . self::PREVIEW,
                $params,
            )
            : [];

        return ['total' => $total, 'items' => $rows];
    }

    /** @return array{total: int, items: list<array<string, mixed>>} */
    private function sectionConfessions(string $like, ?int $viewerId, bool $canModerate): array
    {
        [$where, $params] = $this->confessionsWhere($like);
        $total = (int) $this->query->value('SELECT COUNT(*) FROM confessions c WHERE ' . $where, $params);

        $rows = $total > 0
            ? $this->query->all(
                'SELECT c.*, u.username AS author_username, pr.nickname AS author_nickname
                 FROM confessions c
                 LEFT JOIN users u ON u.id = c.creator_id
                 LEFT JOIN user_profiles pr ON pr.user_id = c.creator_id
                 WHERE ' . $where . ' ORDER BY c.id DESC LIMIT ' . self::PREVIEW,
                $params,
            )
            : [];

        return ['total' => $total, 'items' => $this->confessions->presentRows($rows, $viewerId, $canModerate)];
    }

    // ---------------------------------------------------------------- WHERE 构造

    /** @return array{0: string, 1: array<string, string>} */
    private function postsWhere(string $like): array
    {
        return [
            "p.status = 'published' AND p.visibility = 'public' AND p.deleted_at IS NULL
             AND p.community_id IS NULL
             AND (
                 p.content LIKE :kw_content
                 OR p.title LIKE :kw_title
                 OR EXISTS (
                     SELECT 1 FROM post_topics pt
                     JOIN topics t ON t.id = pt.topic_id
                     WHERE pt.post_id = p.id AND t.name LIKE :kw_topic
                 )
             )",
            ['kw_content' => $like, 'kw_title' => $like, 'kw_topic' => $like],
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function usersWhere(string $like): array
    {
        return [
            "u.status != 'banned'
             AND (u.username LIKE :kw_username OR pr.nickname LIKE :kw_nickname OR pr.bio LIKE :kw_bio)",
            ['kw_username' => $like, 'kw_nickname' => $like, 'kw_bio' => $like],
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function topicsWhere(string $like): array
    {
        return ['t.name LIKE :kw_name', ['kw_name' => $like]];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function confessionsWhere(string $like): array
    {
        return [
            "c.status = 'approved' AND c.deleted_at IS NULL AND c.content LIKE :kw_content",
            ['kw_content' => $like],
        ];
    }
}
