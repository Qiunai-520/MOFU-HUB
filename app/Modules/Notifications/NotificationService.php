<?php

declare(strict_types=1);

namespace Mofu\Modules\Notifications;

use Mofu\Core\Database\Query;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;
use Mofu\Modules\Settings\PreferenceService;

/**
 * 跨板块统一通知系统。
 */
final class NotificationService
{
    public const TYPES = [
        'post_liked' => '点赞了你的动态',
        'post_commented' => '评论了你的动态',
        'comment_replied' => '回复了你的评论',
        'comment_liked' => '点赞了你的评论',
        'new_follower' => '关注了你',
        'confession_liked' => '赞了你的投稿',
        'confession_commented' => '评论了你的投稿',
        'confession_status' => '投稿审核结果',
        'entry_liked' => '点赞了你的帖子',
        'entry_commented' => '回复了你的帖子',
        'post_reposted' => '转发了你的动态',
        'event_signup_pending' => '有人报名了你的活动',
        'event_signup_result' => '活动报名结果',
        'event_cancelled' => '活动已取消',
        'news_published' => '校园资讯',
        'report_result' => '举报处理结果',
        'system' => '系统通知',
    ];

    /**
     * 可按用户偏好关闭的通知分组（审核结果、举报处理与系统通知不受偏好影响）。
     *
     * @var array<string, list<string>>
     */
    public const PREF_GROUPS = [
        'likes' => ['post_liked', 'comment_liked', 'confession_liked', 'entry_liked'],
        'comments' => ['post_commented', 'comment_replied', 'confession_commented', 'entry_commented'],
        'follows' => ['new_follower'],
    ];

    public function __construct(
        private readonly Query $query,
        private readonly PreferenceService $preferences,
    ) {
    }

    /**
     * 推送一条通知。自己触发自己的行为不会产生通知。
     *
     * @param array<string, mixed> $data
     */
    public function push(
        int $userId,
        ?int $actorId,
        string $type,
        string $targetType = '',
        ?int $targetId = null,
        array $data = [],
    ): void {
        if ($actorId !== null && $actorId === $userId) {
            return;
        }

        if (!$this->allows($userId, $type)) {
            return;
        }

        $this->query->insert('notifications', [
            'user_id' => $userId,
            'actor_id' => $actorId,
            'type' => $type,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'data' => $data === [] ? null : (string) json_encode($data, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->query->value(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL',
            ['user_id' => $userId],
        );
    }

    /** 该用户是否接收此类型通知（未分组类型始终接收；免打扰仅作用于可关闭分组） */
    private function allows(int $userId, string $type): bool
    {
        $group = null;

        foreach (self::PREF_GROUPS as $key => $types) {
            if (in_array($type, $types, true)) {
                $group = $key;
                break;
            }
        }

        if ($group === null) {
            return true;
        }

        $prefs = $this->preferences->notificationPrefs($userId);

        if (($prefs[$group] ?? true) !== true) {
            return false;
        }

        $dnd = $prefs['dnd'] ?? null;

        if (is_array($dnd) && ($dnd['enabled'] ?? false) === true
            && self::inDndWindow((int) ($dnd['start'] ?? 23), (int) ($dnd['end'] ?? 7))) {
            return false;
        }

        return true;
    }

    /** 当前时间是否处于免打扰时段（跨天窗口如 23→7；起止相同视为关闭） */
    public static function inDndWindow(int $start, int $end): bool
    {
        if ($start === $end) {
            return false;
        }

        $hour = (int) date('G');

        return $start < $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listForUser(int $userId, string $filter, int $page, int $perPage): array
    {
        $where = 'n.user_id = :user_id';
        $params = ['user_id' => $userId];

        if ($filter === 'unread') {
            $where .= ' AND n.read_at IS NULL';
        }

        $sql = 'SELECT n.*, a.username AS actor_username, ap.nickname AS actor_nickname,
                       am.thumb_path AS actor_avatar_thumb, am.disk_path AS actor_avatar_path
                FROM notifications n
                LEFT JOIN users a ON a.id = n.actor_id
                LEFT JOIN user_profiles ap ON ap.user_id = a.id
                LEFT JOIN media am ON am.id = ap.avatar_media_id
                WHERE ' . $where . '
                ORDER BY n.id DESC';
        $countSql = 'SELECT COUNT(*) FROM notifications n WHERE ' . $where;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = array_map($this->present(...), $result['items']);

        return $result;
    }

    /**
     * 标记已读。空数组表示全部标记。
     *
     * @param list<int> $ids
     */
    public function markRead(int $userId, array $ids = []): int
    {
        $now = date('Y-m-d H:i:s');

        if ($ids === []) {
            return $this->query->run(
                'UPDATE notifications SET read_at = :now WHERE user_id = :user_id AND read_at IS NULL',
                ['now' => $now, 'user_id' => $userId],
            )->rowCount();
        }

        $placeholders = [];
        $params = ['now' => $now, 'user_id' => $userId];

        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':n_' . $i;
            $params['n_' . $i] = (int) $id;
        }

        return $this->query->run(
            sprintf(
                'UPDATE notifications SET read_at = :now
                 WHERE user_id = :user_id AND read_at IS NULL AND id IN (%s)',
                implode(', ', $placeholders),
            ),
            $params,
        )->rowCount();
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        $type = (string) $row['type'];
        $data = $row['data'] !== null ? (json_decode((string) $row['data'], true) ?: []) : [];

        $actor = null;
        if ($row['actor_username'] !== null) {
            $nickname = trim((string) ($row['actor_nickname'] ?? '')) !== ''
                ? (string) $row['actor_nickname']
                : (string) $row['actor_username'];
            $actor = [
                'username' => (string) $row['actor_username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue((int) ($row['actor_id'] ?? 0)),
                'avatar_url' => Present::uploadUrl(
                    isset($row['actor_avatar_thumb']) ? (string) $row['actor_avatar_thumb'] : null,
                    isset($row['actor_avatar_path']) ? (string) $row['actor_avatar_path'] : null,
                ),
            ];
        }

        $url = '';

        if (isset($data['url']) && is_string($data['url']) && $data['url'] !== '') {
            $url = $data['url'];
        } elseif ((string) $row['target_type'] === 'post' && $row['target_id'] !== null) {
            $url = '/posts/' . (int) $row['target_id'];
        } elseif ($type === 'new_follower' && $row['actor_username'] !== null) {
            $url = '/u/' . (string) $row['actor_username'];
        }

        $message = match ($type) {
            'post_liked' => '赞了你的动态',
            'post_commented' => '评论了你的动态',
            'comment_replied' => '回复了你的评论',
            'comment_liked' => '点赞了你的评论',
            'new_follower' => '关注了你',
            'confession_liked' => '赞了你的投稿',
            'confession_commented' => '评论了你的投稿',
            'confession_status' => (string) ($data['message'] ?? '你的投稿状态有更新'),
            'entry_liked' => '赞了你的帖子',
            'entry_commented' => '回复了你的帖子',
            'post_reposted' => '转发了你的动态',
            'event_signup_pending' => (string) ($data['message'] ?? '有人报名了你的活动'),
            'event_signup_result' => (string) ($data['message'] ?? '你的活动报名状态有更新'),
            'event_cancelled' => (string) ($data['message'] ?? '你报名的活动已被取消'),
            'news_published' => (string) ($data['message'] ?? '校园资讯有更新'),
            'report_result' => (string) ($data['message'] ?? '你的举报已有处理结果'),
            default => (string) ($data['message'] ?? '发来一条通知'),
        };

        $excerpt = isset($data['excerpt']) ? (string) $data['excerpt'] : '';

        return [
            'id' => (int) $row['id'],
            'type' => $type,
            'message' => $message,
            'excerpt' => $excerpt,
            'actor' => $actor,
            'url' => $url,
            'read' => $row['read_at'] !== null,
            'time_ago' => Text::relativeTime((string) $row['created_at']),
            'created_at' => date('c', strtotime((string) $row['created_at']) ?: time()),
        ];
    }
}
