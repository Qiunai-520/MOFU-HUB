<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;

/**
 * 用户拉黑：拉黑后双方不能互相关注与私信，已有关注关系自动解除。
 */
final class BlockService
{
    public function __construct(private readonly Query $query)
    {
    }

    public function isBlocked(int $blockerId, int $blockedId): bool
    {
        return $this->query->value(
            'SELECT 1 FROM user_blocks WHERE blocker_id = :blocker AND blocked_id = :blocked',
            ['blocker' => $blockerId, 'blocked' => $blockedId],
        ) !== null;
    }

    /** 任意方向的拉黑关系 */
    public function isBlockedEither(int $a, int $b): bool
    {
        return $this->query->value(
            'SELECT 1 FROM user_blocks
             WHERE (blocker_id = :a AND blocked_id = :b) OR (blocker_id = :b2 AND blocked_id = :a2)',
            ['a' => $a, 'b' => $b, 'b2' => $b, 'a2' => $a],
        ) !== null;
    }

    /**
     * 切换拉黑状态。
     *
     * @return array{blocked: bool}
     */
    public function toggle(int $actorId, int $targetId): array
    {
        if ($actorId === $targetId) {
            throw HttpException::badRequest('不能拉黑自己');
        }

        $target = $this->query->value(
            "SELECT id FROM users WHERE id = :id AND status != 'banned'",
            ['id' => $targetId],
        );

        if ($target === null) {
            throw HttpException::notFound('用户不存在');
        }

        if ($this->isBlocked($actorId, $targetId)) {
            $this->query->delete(
                'user_blocks',
                'blocker_id = :blocker AND blocked_id = :blocked',
                ['blocker' => $actorId, 'blocked' => $targetId],
            );

            return ['blocked' => false];
        }

        $this->query->transaction(function (Query $q) use ($actorId, $targetId): void {
            try {
                $q->insert('user_blocks', [
                    'blocker_id' => $actorId,
                    'blocked_id' => $targetId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }

            // 解除双方已有的关注关系
            $q->run(
                'DELETE FROM follows
                 WHERE (follower_id = :actor AND followee_id = :target)
                    OR (follower_id = :target2 AND followee_id = :actor2)',
                ['actor' => $actorId, 'target' => $targetId, 'target2' => $targetId, 'actor2' => $actorId],
            );
        });

        return ['blocked' => true];
    }

    /**
     * 我拉黑的用户列表（含展示字段，供设置页使用）。
     *
     * @return list<array<string, mixed>>
     */
    public function listBlocked(int $userId): array
    {
        return $this->query->all(
            'SELECT u.id, u.username, p.nickname, p.bio,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM user_blocks b
             JOIN users u ON u.id = b.blocked_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN media am ON am.id = p.avatar_media_id
             WHERE b.blocker_id = :id
             ORDER BY b.created_at DESC',
            ['id' => $userId],
        );
    }
}
