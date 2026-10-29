<?php

declare(strict_types=1);

namespace Mofu\Modules\Follows;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\View\Present;
use Mofu\Modules\Users\ProfileDecorations;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Users\BlockService;

final class FollowService
{
    public function __construct(
        private readonly Query $query,
        private readonly NotificationService $notifications,
        private readonly BlockService $blocks,
    ) {
    }

    /**
     * 切换关注状态。
     *
     * @return array{following: bool, followers_count: int}
     */
    public function toggle(int $followerId, int $followeeId): array
    {
        if ($followerId === $followeeId) {
            throw HttpException::badRequest('不能关注自己哦');
        }

        $target = $this->query->value(
            "SELECT id FROM users WHERE id = :id AND status != 'banned'",
            ['id' => $followeeId],
        );

        if ($target === null) {
            throw HttpException::notFound('用户不存在');
        }

        if ($this->blocks->isBlockedEither($followerId, $followeeId)) {
            throw HttpException::badRequest('你们之间存在拉黑关系，无法关注');
        }

        $existing = $this->query->value(
            'SELECT 1 FROM follows WHERE follower_id = :follower AND followee_id = :followee',
            ['follower' => $followerId, 'followee' => $followeeId],
        );

        if ($existing !== null) {
            $this->query->delete('follows', 'follower_id = :follower AND followee_id = :followee', [
                'follower' => $followerId,
                'followee' => $followeeId,
            ]);
            $following = false;
        } else {
            try {
                $this->query->insert('follows', [
                    'follower_id' => $followerId,
                    'followee_id' => $followeeId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }

            $following = true;

            $this->notifications->push($followeeId, $followerId, 'new_follower', 'user', $followerId);
        }

        return [
            'following' => $following,
            'followers_count' => $this->followersCount($followeeId),
        ];
    }

    public function isFollowing(int $followerId, int $followeeId): bool
    {
        return $this->query->value(
            'SELECT 1 FROM follows WHERE follower_id = :follower AND followee_id = :followee',
            ['follower' => $followerId, 'followee' => $followeeId],
        ) !== null;
    }

    public function followersCount(int $userId): int
    {
        return (int) $this->query->value(
            'SELECT COUNT(*) FROM follows WHERE followee_id = :id',
            ['id' => $userId],
        );
    }

    public function followingCount(int $userId): int
    {
        return (int) $this->query->value(
            'SELECT COUNT(*) FROM follows WHERE follower_id = :id',
            ['id' => $userId],
        );
    }

    /**
     * 粉丝列表。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function followers(int $userId, ?int $viewerId, int $page, int $perPage): array
    {
        $sql = 'SELECT u.id, u.username, p.nickname, p.avatar_frame, p.name_badge, p.bio,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                FROM follows f
                JOIN users u ON u.id = f.follower_id
                LEFT JOIN user_profiles p ON p.user_id = u.id
                LEFT JOIN media am ON am.id = p.avatar_media_id
                WHERE f.followee_id = :user_id AND u.status != :banned
                ORDER BY f.created_at DESC';
        $countSql = 'SELECT COUNT(*) FROM follows f JOIN users u ON u.id = f.follower_id
                     WHERE f.followee_id = :user_id AND u.status != :banned';

        $result = $this->query->paginate($sql, $countSql, ['user_id' => $userId, 'banned' => 'banned'], $page, $perPage);
        $result['items'] = $this->presentUsers($result['items'], $viewerId);

        return $result;
    }

    /**
     * 关注列表。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function following(int $userId, ?int $viewerId, int $page, int $perPage): array
    {
        $sql = 'SELECT u.id, u.username, p.nickname, p.avatar_frame, p.name_badge, p.bio,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                FROM follows f
                JOIN users u ON u.id = f.followee_id
                LEFT JOIN user_profiles p ON p.user_id = u.id
                LEFT JOIN media am ON am.id = p.avatar_media_id
                WHERE f.follower_id = :user_id AND u.status != :banned
                ORDER BY f.created_at DESC';
        $countSql = 'SELECT COUNT(*) FROM follows f JOIN users u ON u.id = f.followee_id
                     WHERE f.follower_id = :user_id AND u.status != :banned';

        $result = $this->query->paginate($sql, $countSql, ['user_id' => $userId, 'banned' => 'banned'], $page, $perPage);
        $result['items'] = $this->presentUsers($result['items'], $viewerId);

        return $result;
    }

    /**
     * 推荐关注：近期活跃且当前用户未关注的用户。
     *
     * @return list<array<string, mixed>>
     */
    public function suggestions(int $viewerId, int $limit = 5): array
    {
        $rows = $this->query->all(
            'SELECT u.id, u.username, p.nickname, p.avatar_frame, p.name_badge, p.bio, COUNT(po.id) AS recent_posts,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN media am ON am.id = p.avatar_media_id
             LEFT JOIN posts po ON po.user_id = u.id AND po.deleted_at IS NULL
                 AND po.created_at >= :since
             WHERE u.id != :viewer AND u.status = :active
               AND u.id NOT IN (SELECT followee_id FROM follows WHERE follower_id = :viewer_follow)
             GROUP BY u.id, u.username, p.nickname, p.avatar_frame, p.name_badge, p.bio
             ORDER BY recent_posts DESC, u.id DESC
             LIMIT ' . max(1, min($limit, 10)),
            [
                'since' => date('Y-m-d H:i:s', time() - 30 * 86400),
                'viewer' => $viewerId,
                'viewer_follow' => $viewerId,
                'active' => 'active',
            ],
        );

        return $this->presentUsers($rows, $viewerId);
    }

    /**
     * 将用户行转换为展示结构（关注列表与全站搜索共用）。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function presentUsers(array $rows, ?int $viewerId): array
    {
        if ($rows === []) {
            return [];
        }

        $followingSet = [];

        if ($viewerId !== null) {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = [];
            $params = ['follower' => $viewerId];

            foreach (array_values($ids) as $i => $id) {
                $placeholders[] = ':u_' . $i;
                $params['u_' . $i] = $id;
            }

            $rows2 = $this->query->all(
                sprintf(
                    'SELECT followee_id FROM follows WHERE follower_id = :follower AND followee_id IN (%s)',
                    implode(', ', $placeholders),
                ),
                $params,
            );

            $followingSet = array_flip(array_map(static fn (array $row): int => (int) $row['followee_id'], $rows2));
        }

        return array_map(static function (array $row) use ($viewerId, $followingSet): array {
            $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
                ? (string) $row['nickname']
                : (string) $row['username'];

            return [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue((int) $row['id']),
                'bio' => (string) ($row['bio'] ?? ''),
                'url' => '/u/' . (string) $row['username'],
                'avatar_url' => Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                ),
                ...ProfileDecorations::present($row),
                'is_self' => $viewerId !== null && $viewerId === (int) $row['id'],
                'is_following' => isset($followingSet[(int) $row['id']]),
            ];
        }, $rows);
    }
}
