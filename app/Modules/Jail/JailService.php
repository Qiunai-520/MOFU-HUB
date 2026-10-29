<?php

declare(strict_types=1);

namespace Mofu\Modules\Jail;

use Mofu\Core\Database\Query;

/**
 * 小黑屋：处罚公示。
 *
 * 只展示 user_bans 中 is_public = 1 的记录；公示说明优先用 public_note，留空回退到原因。
 */
final class JailService
{
    public const TYPE_LABELS = [
        'warning' => '警告',
        'suspended' => '临时封禁',
        'banned' => '永久封禁',
    ];

    public function __construct(
        private readonly Query $query,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function entries(int $page = 1, int $perPage = 20): array
    {
        $base = 'FROM user_bans b
                 JOIN users u ON u.id = b.user_id
                 LEFT JOIN user_profiles p ON p.user_id = b.user_id
                 WHERE b.is_public = 1';

        $result = $this->query->paginate(
            'SELECT b.*, u.username, p.nickname ' . $base . ' ORDER BY b.id DESC',
            'SELECT COUNT(*) ' . $base,
            [],
            max(1, $page),
            max(1, min(50, $perPage)),
        );

        $result['items'] = array_map($this->present(...), $result['items']);

        return $result;
    }

    /** @return array{total: int, active: int} */
    public function stats(): array
    {
        return [
            'total' => (int) $this->query->value('SELECT COUNT(*) FROM user_bans WHERE is_public = 1'),
            'active' => (int) $this->query->value('SELECT COUNT(*) FROM user_bans WHERE is_public = 1 AND active = 1'),
        ];
    }

    public function setPublic(int $banId, bool $isPublic, string $publicNote = ''): void
    {
        $this->query->update('user_bans', [
            'is_public' => $isPublic ? 1 : 0,
            'public_note' => mb_substr(trim($publicNote), 0, 255),
        ], 'id = :id', ['id' => $banId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $nickname = trim((string) ($row['nickname'] ?? '')) !== '' ? (string) $row['nickname'] : (string) $row['username'];
        $isActive = (int) $row['active'] === 1;
        $endsAt = $row['ends_at'] !== null ? (string) $row['ends_at'] : null;
        $expired = $endsAt !== null && $endsAt < date('Y-m-d H:i:s');
        $publicNote = trim((string) ($row['public_note'] ?? ''));

        return [
            'id' => (int) $row['id'],
            'nickname' => $nickname,
            'username' => (string) $row['username'],
            'type' => (string) $row['type'],
            'type_label' => self::TYPE_LABELS[(string) $row['type']] ?? (string) $row['type'],
            'reason' => $publicNote !== '' ? $publicNote : (string) $row['reason'],
            'starts_at' => (string) $row['starts_at'],
            'ends_at' => $endsAt,
            'permanent' => $endsAt === null,
            'status' => !$isActive ? 'released' : ($expired ? 'expired' : 'active'),
        ];
    }
}
