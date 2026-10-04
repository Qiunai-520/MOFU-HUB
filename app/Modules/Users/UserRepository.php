<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

use Mofu\Core\Database\Query;

final class UserRepository
{
    public function __construct(private readonly Query $query)
    {
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        return $this->query->one(
            'SELECT u.id, u.username, u.email, u.status, u.created_at, u.updated_at,
                    p.nickname, p.avatar_media_id, p.bio,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN media am ON am.id = p.avatar_media_id
             WHERE u.id = :id',
            ['id' => $id],
        );
    }

    /**
     * 会话用户：含角色与权限列表。
     *
     * @return array<string, mixed>|null
     */
    public function findForSession(int $id): ?array
    {
        $user = $this->findById($id);

        if ($user === null) {
            return null;
        }

        $roles = $this->query->all(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id',
            ['id' => $id],
        );

        $permissions = $this->query->all(
            'SELECT DISTINCT p.slug
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :id',
            ['id' => $id],
        );

        $user['roles'] = array_map(static fn (array $row): string => (string) $row['slug'], $roles);
        $user['permissions'] = array_map(static fn (array $row): string => (string) $row['slug'], $permissions);

        return $user;
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->query->one(
            'SELECT u.id, u.username, u.email, u.status, u.created_at,
                    p.nickname, p.avatar_media_id, p.bio, p.cover_media_id,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path,
                    cm.thumb_path AS cover_thumb, cm.disk_path AS cover_path
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN media am ON am.id = p.avatar_media_id
             LEFT JOIN media cm ON cm.id = p.cover_media_id
             WHERE u.username = :username',
            ['username' => $username],
        );
    }

    /**
     * 我发表的动态评论（含来源可见性）。
     *
     * @return list<array<string, mixed>>
     */
    public function commentsByUser(int $userId, int $limit = 50): array
    {
        return $this->query->all(
            'SELECT c.id, c.content, c.created_at, c.post_id,
                    p.deleted_at, p.status AS post_status
             FROM comments c
             JOIN posts p ON p.id = c.post_id
             WHERE c.user_id = :user_id AND c.status = :status
             ORDER BY c.id DESC
             LIMIT ' . max(1, min($limit, 100)),
            ['user_id' => $userId, 'status' => 'published'],
        );
    }

    /**
     * 我发表的表白墙/树洞回应（含来源可见性）。
     *
     * @return list<array<string, mixed>>
     */
    public function confessionCommentsByUser(int $userId, int $limit = 50): array
    {
        return $this->query->all(
            'SELECT cc.id, cc.content, cc.created_at, cc.confession_id,
                    c.board, c.deleted_at, c.status AS confession_status
             FROM confession_comments cc
             JOIN confessions c ON c.id = cc.confession_id
             WHERE cc.user_id = :user_id AND cc.status = :status
             ORDER BY cc.id DESC
             LIMIT ' . max(1, min($limit, 100)),
            ['user_id' => $userId, 'status' => 'published'],
        );
    }

    public function countAll(): int
    {
        return (int) $this->query->value('SELECT COUNT(*) FROM users');
    }

    /**
     * @提及搜索：按用户名或昵称模糊匹配，仅返回活跃用户。
     *
     * @return list<array{id: int, username: string, nickname: string, avatar: string}>
     */
    public function searchForMention(string $keyword, int $limit = 6): array
    {
        $keyword = trim($keyword);

        if ($keyword === '') {
            return [];
        }

        $rows = $this->query->all(
            'SELECT u.id, u.username,
                    COALESCE(NULLIF(p.nickname, \'\'), u.username) AS nickname,
                    COALESCE(NULLIF(am.thumb_path, \'\'), NULLIF(am.disk_path, \'\')) AS avatar_path
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN media am ON am.id = p.avatar_media_id
             WHERE u.status = :status
               AND (u.username LIKE :kw_name OR p.nickname LIKE :kw_nick)
             ORDER BY u.id ASC
             LIMIT ' . max(1, min($limit, 20)),
            ['status' => 'active', 'kw_name' => '%' . $keyword . '%', 'kw_nick' => '%' . $keyword . '%'],
        );

        return array_map(static function (array $row): array {
            $avatar = (string) ($row['avatar_path'] ?? '');

            return [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'nickname' => (string) $row['nickname'],
                'avatar' => $avatar !== '' ? '/uploads/' . ltrim($avatar, '/') : '',
            ];
        }, $rows);
    }
}
