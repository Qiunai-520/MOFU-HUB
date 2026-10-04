<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

use Mofu\Core\Database\Query;

/**
 * 用户的收藏与点赞列表（动态 + 表白墙/树洞）。
 */
final class FavoriteService
{
    private const LIMIT = 20;

    public function __construct(private readonly Query $query)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function favoritePosts(int $userId, int $limit = self::LIMIT): array
    {
        return $this->reactionPosts($userId, 'favorite', $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function likedPosts(int $userId, int $limit = self::LIMIT): array
    {
        return $this->reactionPosts($userId, 'like', $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function favoriteConfessions(int $userId, int $limit = self::LIMIT): array
    {
        return $this->reactionConfessions($userId, 'favorite', $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function likedConfessions(int $userId, int $limit = self::LIMIT): array
    {
        return $this->reactionConfessions($userId, 'like', $limit);
    }

    /** @return list<array<string, mixed>> */
    private function reactionPosts(int $userId, string $kind, int $limit): array
    {
        return $this->query->all(
            "SELECT p.*, u.username, pr.nickname, pr.avatar_media_id,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM reactions r
             JOIN posts p ON p.id = r.target_id
             JOIN users u ON u.id = p.user_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE r.user_id = :user_id AND r.kind = :kind AND r.target_type = 'post'
               AND p.deleted_at IS NULL AND p.status = 'published'
               AND p.visibility = 'public' AND p.community_id IS NULL
             ORDER BY r.id DESC
             LIMIT " . max(1, min($limit, 50)),
            ['user_id' => $userId, 'kind' => $kind],
        );
    }

    /** @return list<array<string, mixed>> */
    private function reactionConfessions(int $userId, string $kind, int $limit): array
    {
        return $this->query->all(
            "SELECT c.*, u.username AS author_username, pr.nickname AS author_nickname
             FROM reactions r
             JOIN confessions c ON c.id = r.target_id
             LEFT JOIN users u ON u.id = c.creator_id
             LEFT JOIN user_profiles pr ON pr.user_id = c.creator_id
             WHERE r.user_id = :user_id AND r.kind = :kind AND r.target_type = 'confession'
               AND c.deleted_at IS NULL AND c.status = 'approved'
             ORDER BY r.id DESC
             LIMIT " . max(1, min($limit, 50)),
            ['user_id' => $userId, 'kind' => $kind],
        );
    }
}
