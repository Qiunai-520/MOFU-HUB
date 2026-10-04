<?php

declare(strict_types=1);

namespace Mofu\Modules\Communities;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;

/**
 * 兴趣圈子：创建、加入（公开/审批）、退出、成员管理与可见性判定。
 * 圈子动态复用 Posts 系统（posts.community_id），本服务只负责圈子与成员维度。
 */
final class CommunityService
{
    public const NAME_MAX = 24;
    public const DESC_MAX = 300;
    public const VISIBILITIES = ['public', 'approval'];

    public function __construct(private readonly Query $query)
    {
    }

    /** @return array<string, mixed>|null */
    public function findActiveBySlug(string $slug): ?array
    {
        return $this->query->one(
            'SELECT * FROM communities WHERE slug = :slug AND is_active = 1',
            ['slug' => $slug],
        );
    }

    /** @return array<string, mixed>|null */
    public function findActiveById(int $id): ?array
    {
        return $this->query->one(
            'SELECT * FROM communities WHERE id = :id AND is_active = 1',
            ['id' => $id],
        );
    }

    /**
     * 任意状态的成员记录。
     *
     * @return array<string, mixed>|null
     */
    public function membership(int $communityId, int $userId): ?array
    {
        return $this->query->one(
            'SELECT * FROM community_members WHERE community_id = :community AND user_id = :user',
            ['community' => $communityId, 'user' => $userId],
        );
    }

    public function isMember(int $communityId, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->query->value(
            "SELECT 1 FROM community_members
             WHERE community_id = :community AND user_id = :user AND status = 'active'",
            ['community' => $communityId, 'user' => $userId],
        ) !== null;
    }

    public function isManager(int $communityId, int $userId): bool
    {
        return $this->query->value(
            "SELECT 1 FROM community_members
             WHERE community_id = :community AND user_id = :user AND status = 'active'
               AND role IN ('owner','moderator')",
            ['community' => $communityId, 'user' => $userId],
        ) !== null;
    }

    /**
     * 创建圈子（创建者自动成为圈主）。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> 新圈子（展示结构）
     */
    public function create(int $userId, array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $visibility = (string) ($input['visibility'] ?? 'public');

        if (mb_strlen($name) < 2 || mb_strlen($name) > self::NAME_MAX) {
            throw HttpException::badRequest('圈子名称需为 2-' . self::NAME_MAX . ' 字');
        }

        if (mb_strlen($description) > self::DESC_MAX) {
            throw HttpException::badRequest('圈子简介最多 ' . self::DESC_MAX . ' 字');
        }

        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw HttpException::badRequest('加入方式无效');
        }

        $now = date('Y-m-d H:i:s');
        $slug = $this->uniqueSlug($name);

        $communityId = $this->query->transaction(function (Query $q) use ($userId, $name, $slug, $description, $visibility, $now): int {
            $id = $q->insert('communities', [
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'icon' => 'groups',
                'visibility' => $visibility,
                'owner_id' => $userId,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $q->insert('community_members', [
                'community_id' => $id,
                'user_id' => $userId,
                'role' => 'owner',
                'status' => 'active',
                'joined_at' => $now,
            ]);

            return $id;
        });

        $row = $this->query->one('SELECT * FROM communities WHERE id = :id', ['id' => $communityId]) ?? [];
        $presented = $this->present($row);
        $presented['my_status'] = 'active';
        $presented['my_role'] = 'owner';

        return $presented;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Text::slugifyTopic($name);
        $base = $base === '' ? 'circle' : mb_substr($base, 0, 40);
        $slug = $base;

        for ($i = 0; $i < 5; $i++) {
            $exists = $this->query->value('SELECT 1 FROM communities WHERE slug = :slug', ['slug' => $slug]);

            if ($exists === null) {
                return $slug;
            }

            $slug = $base . '-' . bin2hex(random_bytes(2));
        }

        return $base . '-' . bin2hex(random_bytes(4));
    }

    /**
     * 加入圈子。
     *
     * @param array<string, mixed> $community
     * @return array{status: string} active（已加入）| pending（待审批）
     */
    public function join(array $community, int $userId): array
    {
        $communityId = (int) $community['id'];
        $existing = $this->membership($communityId, $userId);

        if ($existing !== null) {
            if ((string) $existing['status'] === 'pending') {
                throw HttpException::badRequest('申请已提交，请等待审批');
            }

            throw HttpException::badRequest('你已经在这个圈子里了');
        }

        $status = (string) $community['visibility'] === 'approval' ? 'pending' : 'active';

        try {
            $this->query->insert('community_members', [
                'community_id' => $communityId,
                'user_id' => $userId,
                'role' => 'member',
                'status' => $status,
                'joined_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        return ['status' => $status];
    }

    /** @param array<string, mixed> $community */
    public function leave(array $community, int $userId): void
    {
        $member = $this->membership((int) $community['id'], $userId);

        if ($member === null) {
            throw HttpException::badRequest('你还没有加入这个圈子');
        }

        if ((string) $member['role'] === 'owner') {
            throw HttpException::badRequest('圈主不能退出自己的圈子');
        }

        $this->query->delete(
            'community_members',
            'community_id = :community AND user_id = :user',
            ['community' => (int) $community['id'], 'user' => $userId],
        );
    }

    /** 审批通过（圈主/管理员） */
    public function approve(array $community, int $actorId, int $targetUserId): void
    {
        $this->assertManager($community, $actorId);

        $updated = $this->query->run(
            "UPDATE community_members SET status = 'active'
             WHERE community_id = :community AND user_id = :user AND status = 'pending'",
            ['community' => (int) $community['id'], 'user' => $targetUserId],
        )->rowCount();

        if ($updated === 0) {
            throw HttpException::notFound('没有待处理的申请');
        }
    }

    public function reject(array $community, int $actorId, int $targetUserId): void
    {
        $this->assertManager($community, $actorId);

        $deleted = $this->query->delete(
            'community_members',
            "community_id = :community AND user_id = :user AND status = 'pending'",
            ['community' => (int) $community['id'], 'user' => $targetUserId],
        );

        if ($deleted === 0) {
            throw HttpException::notFound('没有待处理的申请');
        }
    }

    public function removeMember(array $community, int $actorId, int $targetUserId): void
    {
        $this->assertManager($community, $actorId);

        if ($targetUserId === (int) $community['owner_id']) {
            throw HttpException::badRequest('不能移除圈主');
        }

        $deleted = $this->query->delete(
            'community_members',
            'community_id = :community AND user_id = :user',
            ['community' => (int) $community['id'], 'user' => $targetUserId],
        );

        if ($deleted === 0) {
            throw HttpException::notFound('该用户不在圈子里');
        }
    }

    /**
     * 更新圈子资料（仅圈主）。
     *
     * @param array<string, mixed> $community
     * @param array<string, mixed> $input
     */
    public function updateInfo(array $community, int $actorId, array $input): void
    {
        if ((int) $community['owner_id'] !== $actorId) {
            throw HttpException::forbidden('只有圈主可以修改圈子资料');
        }

        $description = trim((string) ($input['description'] ?? ''));
        $visibility = (string) ($input['visibility'] ?? (string) $community['visibility']);

        if (mb_strlen($description) > self::DESC_MAX) {
            throw HttpException::badRequest('圈子简介最多 ' . self::DESC_MAX . ' 字');
        }

        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw HttpException::badRequest('加入方式无效');
        }

        $this->query->update('communities', [
            'description' => $description,
            'visibility' => $visibility,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => (int) $community['id']]);
    }

    /**
     * 圈子发现列表（成员数优先，手动分页）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listPage(?int $viewerId, int $page, int $perPage = 12): array
    {
        $perPage = max(1, min($perPage, 30));
        $total = (int) $this->query->value('SELECT COUNT(*) FROM communities WHERE is_active = 1');
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $rows = $total === 0 ? [] : $this->query->all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM community_members m
                      WHERE m.community_id = c.id AND m.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM posts p
                      WHERE p.community_id = c.id AND p.status = 'published' AND p.deleted_at IS NULL) AS post_count,
                    (SELECT m2.status FROM community_members m2
                      WHERE m2.community_id = c.id AND m2.user_id = :viewer) AS my_status
             FROM communities c
             WHERE c.is_active = 1
             ORDER BY member_count DESC, c.id DESC
             LIMIT $perPage OFFSET $offset",
            ['viewer' => $viewerId ?? 0],
        );

        return [
            'items' => array_map($this->present(...), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * 我加入的圈子。
     *
     * @return list<array<string, mixed>>
     */
    public function myCommunities(int $userId): array
    {
        $rows = $this->query->all(
            "SELECT c.*, m.role AS my_role,
                    (SELECT COUNT(*) FROM community_members m3
                      WHERE m3.community_id = c.id AND m3.status = 'active') AS member_count,
                    (SELECT COUNT(*) FROM posts p
                      WHERE p.community_id = c.id AND p.status = 'published' AND p.deleted_at IS NULL) AS post_count
             FROM community_members m
             JOIN communities c ON c.id = m.community_id
             WHERE m.user_id = :user AND m.status = 'active' AND c.is_active = 1
             ORDER BY m.joined_at DESC",
            ['user' => $userId],
        );

        return array_map(function (array $row): array {
            $presented = $this->present($row);
            $presented['my_status'] = 'active';
            $presented['my_role'] = (string) $row['my_role'];

            return $presented;
        }, $rows);
    }

    /** @return array{members: int, pending: int, posts: int} */
    public function counts(int $communityId): array
    {
        return [
            'members' => (int) $this->query->value(
                "SELECT COUNT(*) FROM community_members WHERE community_id = :id AND status = 'active'",
                ['id' => $communityId],
            ),
            'pending' => (int) $this->query->value(
                "SELECT COUNT(*) FROM community_members WHERE community_id = :id AND status = 'pending'",
                ['id' => $communityId],
            ),
            'posts' => (int) $this->query->value(
                "SELECT COUNT(*) FROM posts WHERE community_id = :id AND status = 'published' AND deleted_at IS NULL",
                ['id' => $communityId],
            ),
        ];
    }

    /**
     * 成员分页（圈主/管理员优先）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function membersPage(int $communityId, int $page, int $perPage = 24): array
    {
        $sql = "SELECT m.role, m.status, m.joined_at,
                       u.id, u.username, p.nickname, p.bio,
                       am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                FROM community_members m
                JOIN users u ON u.id = m.user_id
                LEFT JOIN user_profiles p ON p.user_id = u.id
                LEFT JOIN media am ON am.id = p.avatar_media_id
                WHERE m.community_id = :community AND m.status = 'active'
                ORDER BY FIELD(m.role, 'owner', 'moderator', 'member'), m.joined_at ASC";
        $countSql = "SELECT COUNT(*) FROM community_members m
                     WHERE m.community_id = :community AND m.status = 'active'";

        $result = $this->query->paginate($sql, $countSql, ['community' => $communityId], $page, $perPage);
        $result['items'] = $this->presentMembers($result['items']);

        return $result;
    }

    /**
     * 待审批申请（限 100 条）。
     *
     * @return list<array<string, mixed>>
     */
    public function pendingMembers(int $communityId): array
    {
        $rows = $this->query->all(
            "SELECT m.role, m.status, m.joined_at,
                    u.id, u.username, p.nickname, p.bio,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM community_members m
             JOIN users u ON u.id = m.user_id
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN media am ON am.id = p.avatar_media_id
             WHERE m.community_id = :community AND m.status = 'pending'
             ORDER BY m.joined_at ASC
             LIMIT 100",
            ['community' => $communityId],
        );

        return $this->presentMembers($rows);
    }

    // ---------------------------------------------------------------- 后台管理

    /**
     * 后台圈子列表。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function adminPage(string $filter, int $page, int $perPage = 20): array
    {
        $where = $filter === 'inactive' ? 'c.is_active = 0' : '1 = 1';

        $sql = 'SELECT c.*, u.username AS owner_username,
                       (SELECT COUNT(*) FROM community_members m
                         WHERE m.community_id = c.id AND m.status = \'active\') AS member_count,
                       (SELECT COUNT(*) FROM posts p
                         WHERE p.community_id = c.id AND p.deleted_at IS NULL) AS post_count
                FROM communities c
                JOIN users u ON u.id = c.owner_id
                WHERE ' . $where . '
                ORDER BY c.id DESC';
        $countSql = 'SELECT COUNT(*) FROM communities c JOIN users u ON u.id = c.owner_id WHERE ' . $where;

        $result = $this->query->paginate($sql, $countSql, [], $page, $perPage);
        $result['items'] = array_map(function (array $row): array {
            $presented = $this->present($row);
            $presented['owner_username'] = (string) $row['owner_username'];
            $presented['is_active'] = (int) $row['is_active'] === 1;

            return $presented;
        }, $result['items']);

        return $result;
    }

    public function setActive(int $communityId, bool $active): void
    {
        if ($this->query->value('SELECT 1 FROM communities WHERE id = :id', ['id' => $communityId]) === null) {
            throw HttpException::notFound('圈子不存在');
        }

        $this->query->update('communities', [
            'is_active' => $active ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $communityId]);
    }

    /** 删除圈子（级联删除成员与圈子动态，仅后台） */
    public function delete(int $communityId): void
    {
        if ($this->query->value('SELECT 1 FROM communities WHERE id = :id', ['id' => $communityId]) === null) {
            throw HttpException::notFound('圈子不存在');
        }

        $this->query->delete('communities', 'id = :id', ['id' => $communityId]);
    }

    // ---------------------------------------------------------------- 展示

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $myStatus = (string) ($row['my_status'] ?? '');

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'icon' => (string) $row['icon'],
            'visibility' => (string) $row['visibility'],
            'visibility_label' => (string) $row['visibility'] === 'approval' ? '审批加入' : '自由加入',
            'member_count' => (int) ($row['member_count'] ?? 0),
            'post_count' => (int) ($row['post_count'] ?? 0),
            'my_status' => $myStatus === '' ? null : $myStatus,
            'url' => '/circles/' . rawurlencode((string) $row['slug']),
            'created_at' => date('Y-m-d', strtotime((string) $row['created_at']) ?: time()),
        ];
    }

    /** @param array<string, mixed> $community */
    private function assertManager(array $community, int $actorId): void
    {
        if (!$this->isManager((int) $community['id'], $actorId)) {
            throw HttpException::forbidden('需要圈主或管理员权限');
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentMembers(array $rows): array
    {
        return array_map(static function (array $row): array {
            $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
                ? (string) $row['nickname']
                : (string) $row['username'];

            return [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue((int) $row['id']),
                'avatar_url' => Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                ),
                'url' => '/u/' . (string) $row['username'],
                'role' => (string) $row['role'],
                'role_label' => match ((string) $row['role']) {
                    'owner' => '圈主',
                    'moderator' => '管理员',
                    default => '成员',
                },
                'joined_at' => date('Y-m-d', strtotime((string) $row['joined_at']) ?: time()),
            ];
        }, $rows);
    }
}
