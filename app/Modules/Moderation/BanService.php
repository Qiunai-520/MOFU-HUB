<?php

declare(strict_types=1);

namespace Mofu\Modules\Moderation;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\AuditLogger;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 封禁 / 警告体系。
 *
 * 账号状态以 users.status 作为快速判定（middleware / 视图直接用），
 * user_bans 作为可追溯的处罚记录；临时封禁到期后由 expireDue() 惰性恢复。
 */
final class BanService
{
    public const TYPE_LABELS = [
        'warning' => '警告',
        'suspended' => '临时封禁',
        'banned' => '永久封禁',
    ];

    /** 警告自动失效天数 */
    public const WARNING_DAYS = 30;

    public function __construct(
        private readonly Query $query,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /** 惰性恢复：把到期的临时封禁标记为已结束并同步账号状态 */
    public function expireDue(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = $this->query->all(
            "SELECT id, user_id FROM user_bans
             WHERE active = 1 AND type = 'suspended' AND ends_at IS NOT NULL AND ends_at <= :now",
            ['now' => $now],
        );

        foreach ($rows as $row) {
            $this->query->update('user_bans', ['active' => 0], 'id = :id', ['id' => (int) $row['id']]);
            $this->syncStatus((int) $row['user_id']);
            $this->notifications->push((int) $row['user_id'], null, 'system', '', null, [
                'message' => '你的临时封禁已到期，账号权限已恢复',
                'url' => '/me',
            ]);
        }
    }

    /** @return array<string, mixed>|null 当前生效的处罚（按严重程度） */
    public function activeBan(int $userId): ?array
    {
        return $this->query->one(
            "SELECT * FROM user_bans
             WHERE user_id = :user_id AND active = 1
             ORDER BY FIELD(type, 'banned', 'suspended', 'warning'), id DESC
             LIMIT 1",
            ['user_id' => $userId],
        );
    }

    /** @return array<string, mixed>|null 当前生效的封禁（不含警告） */
    public function activeSuspension(int $userId): ?array
    {
        $ban = $this->activeBan($userId);

        return $ban !== null && $ban['type'] !== 'warning' ? $ban : null;
    }

    /** 是否处于禁止发言 / 登录的状态 */
    public function isRestricted(array $user): bool
    {
        $status = (string) ($user['status'] ?? 'active');

        return $status === 'suspended' || $status === 'banned';
    }

    public function warn(int $userId, int $actorId, string $reason, string $note = '', bool $isPublic = true, string $publicNote = ''): void
    {
        $this->assertUser($userId);
        $this->assertNotSelf($userId, $actorId);

        $endsAt = date('Y-m-d H:i:s', strtotime('+' . self::WARNING_DAYS . ' days') ?: time());

        $this->query->transaction(function (Query $q) use ($userId, $actorId, $reason, $note, $endsAt, $isPublic, $publicNote): void {
            $q->insert('user_bans', [
                'user_id' => $userId,
                'type' => 'warning',
                'reason' => mb_substr($reason, 0, 200),
                'is_public' => $isPublic ? 1 : 0,
                'public_note' => mb_substr($publicNote, 0, 255),
                'note' => mb_substr($note, 0, 500),
                'issued_by' => $actorId,
                'starts_at' => date('Y-m-d H:i:s'),
                'ends_at' => $endsAt,
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ((string) $q->value('SELECT status FROM users WHERE id = :id', ['id' => $userId]) === 'active') {
                $q->update('users', ['status' => 'warned', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
            }
        });

        $this->audit->log($actorId, 'user.warn', 'user', $userId, ['reason' => $reason]);
        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => '你收到一条警告：' . $reason,
            'url' => '/me/growth',
        ]);
    }

    /** 临时封禁；$hours 为空时按 7 天处理 */
    public function suspend(int $userId, int $actorId, string $reason, ?int $hours, string $note = '', bool $isPublic = true, string $publicNote = ''): void
    {
        $this->assertUser($userId);
        $this->assertNotSelf($userId, $actorId);

        $hours = $hours === null || $hours <= 0 ? 168 : min($hours, 8760);
        $endsAt = date('Y-m-d H:i:s', time() + $hours * 3600);

        $this->createBan($userId, $actorId, 'suspended', $reason, $endsAt, $note, $isPublic, $publicNote);
        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => sprintf('你的账号被封禁至 %s：%s', $endsAt, $reason),
            'url' => '/banned',
        ]);
    }

    public function ban(int $userId, int $actorId, string $reason, string $note = '', bool $isPublic = true, string $publicNote = ''): void
    {
        $this->assertUser($userId);
        $this->assertNotSelf($userId, $actorId);

        $this->createBan($userId, $actorId, 'banned', $reason, null, $note, $isPublic, $publicNote);
        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => '你的账号已被永久封禁：' . $reason,
            'url' => '/banned',
        ]);
    }

    /** 解除当前封禁 / 警告 */
    public function unban(int $userId, int $actorId, string $note = ''): void
    {
        $this->assertUser($userId);

        $this->query->transaction(function (Query $q) use ($userId, $actorId, $note): void {
            $q->run(
                'UPDATE user_bans SET active = 0, revoked_by = :actor, revoked_at = :now, revoke_note = :note
                 WHERE user_id = :user_id AND active = 1',
                [
                    'actor' => $actorId,
                    'now' => date('Y-m-d H:i:s'),
                    'note' => mb_substr($note, 0, 500),
                    'user_id' => $userId,
                ],
            );
            $q->update('users', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
        });

        $this->audit->log($actorId, 'user.unban', 'user', $userId, ['note' => $note]);
        $this->notifications->push($userId, null, 'system', '', null, [
            'message' => '你的账号处罚已解除，权限已恢复',
            'url' => '/me/growth',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function history(int $userId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'type_label' => self::TYPE_LABELS[(string) $row['type']] ?? (string) $row['type'],
                'reason' => (string) $row['reason'],
                'note' => (string) $row['note'],
                'starts_at' => (string) $row['starts_at'],
                'ends_at' => $row['ends_at'] !== null ? (string) $row['ends_at'] : null,
                'active' => (bool) $row['active'],
                'revoke_note' => (string) ($row['revoke_note'] ?? ''),
            ],
            $this->query->all(
                'SELECT * FROM user_bans WHERE user_id = :user_id ORDER BY id DESC LIMIT 50',
                ['user_id' => $userId],
            ),
        );
    }

    /** @return array<string, mixed> 供封禁提示页 / API 使用 */
    public function presentBan(array $ban): array
    {
        $endsAt = $ban['ends_at'] !== null ? (string) $ban['ends_at'] : null;

        return [
            'id' => (int) $ban['id'],
            'type' => (string) $ban['type'],
            'type_label' => self::TYPE_LABELS[(string) $ban['type']] ?? (string) $ban['type'],
            'reason' => (string) $ban['reason'],
            'note' => (string) $ban['note'],
            'starts_at' => (string) $ban['starts_at'],
            'ends_at' => $endsAt,
            'permanent' => $endsAt === null,
            'can_appeal' => true,
        ];
    }

    private function createBan(int $userId, int $actorId, string $type, string $reason, ?string $endsAt, string $note, bool $isPublic = true, string $publicNote = ''): void
    {
        if ($reason === '') {
            throw HttpException::badRequest('请填写处罚原因');
        }

        $this->query->transaction(function (Query $q) use ($userId, $actorId, $type, $reason, $endsAt, $note, $isPublic, $publicNote): void {
            $q->run(
                'UPDATE user_bans SET active = 0, revoked_by = :actor, revoked_at = :now, revoke_note = :note
                 WHERE user_id = :user_id AND active = 1',
                [
                    'actor' => $actorId,
                    'now' => date('Y-m-d H:i:s'),
                    'note' => '被新的处罚取代',
                    'user_id' => $userId,
                ],
            );

            $q->insert('user_bans', [
                'user_id' => $userId,
                'type' => $type,
                'reason' => mb_substr($reason, 0, 200),
                'is_public' => $isPublic ? 1 : 0,
                'public_note' => mb_substr($publicNote, 0, 255),
                'note' => mb_substr($note, 0, 500),
                'issued_by' => $actorId,
                'starts_at' => date('Y-m-d H:i:s'),
                'ends_at' => $endsAt,
                'active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $q->update('users', [
                'status' => $type === 'banned' ? 'banned' : 'suspended',
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $userId]);
        });

        $this->audit->log($actorId, 'user.' . $type, 'user', $userId, [
            'reason' => $reason,
            'ends_at' => $endsAt,
            'note' => $note,
        ]);
    }

    private function syncStatus(int $userId): void
    {
        $ban = $this->activeBan($userId);

        $status = match ($ban['type'] ?? null) {
            'banned' => 'banned',
            'suspended' => 'suspended',
            'warning' => 'warned',
            default => 'active',
        };

        $this->query->update('users', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $userId]);
    }

    private function assertUser(int $userId): void
    {
        if ($this->query->value('SELECT id FROM users WHERE id = :id', ['id' => $userId]) === null) {
            throw HttpException::notFound('用户不存在');
        }
    }

    private function assertNotSelf(int $userId, int $actorId): void
    {
        if ($userId === $actorId) {
            throw HttpException::badRequest('不能对自己执行该操作');
        }
    }
}
