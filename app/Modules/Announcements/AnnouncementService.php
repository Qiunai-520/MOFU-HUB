<?php

declare(strict_types=1);

namespace Mofu\Modules\Announcements;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\AuditLogger;

/**
 * 弹窗公告。阅读状态按登录用户记录（announcement_reads）；游客由前端 localStorage 记忆。
 */
final class AnnouncementService
{
    public const TYPE_LABELS = [
        'normal' => '普通公告',
        'system' => '系统通知',
        'important' => '重要通知',
        'activity' => '活动公告',
        'maintenance' => '维护公告',
    ];

    public const STATUS_LABELS = [
        'draft' => '草稿',
        'published' => '已发布',
        'archived' => '已归档',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * 当前需要展示给用户的公告（含是否需要弹出）。
     *
     * @return list<array<string, mixed>>
     */
    public function feed(?int $userId): array
    {
        $now = date('Y-m-d H:i:s');

        $rows = $this->query->all(
            "SELECT * FROM announcements
             WHERE status = 'published'
               AND (starts_at IS NULL OR starts_at <= :now_start)
               AND (ends_at IS NULL OR ends_at >= :now_end)
             ORDER BY pinned DESC, priority DESC, id DESC
             LIMIT 20",
            ['now_start' => $now, 'now_end' => $now],
        );

        $readIds = [];

        if ($userId !== null) {
            foreach ($this->query->all(
                'SELECT announcement_id, dismissed FROM announcement_reads WHERE user_id = :user_id',
                ['user_id' => $userId],
            ) as $row) {
                $readIds[(int) $row['announcement_id']] = (bool) $row['dismissed'];
            }
        }

        $items = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $repeat = (int) $row['repeat_show'] === 1;
            $seen = array_key_exists($id, $readIds);
            $dismissed = $readIds[$id] ?? false;

            $items[] = $this->present($row) + [
                'read' => $seen,
                'dismissed' => $dismissed,
                'should_show' => $repeat || !$seen,
            ];
        }

        return $items;
    }

    /** @param list<int> $ids */
    public function markRead(int $userId, array $ids): void
    {
        $now = date('Y-m-d H:i:s');

        foreach (array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)) as $id) {
            $this->query->run(
                'INSERT INTO announcement_reads (announcement_id, user_id, read_at, dismissed)
                 VALUES (:announcement_id, :user_id, :now, 0)
                 ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)',
                ['announcement_id' => $id, 'user_id' => $userId, 'now' => $now],
            );
        }
    }

    public function dismiss(int $userId, int $id): void
    {
        $this->query->run(
            'INSERT INTO announcement_reads (announcement_id, user_id, read_at, dismissed)
             VALUES (:announcement_id, :user_id, :now, 1)
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at), dismissed = 1',
            ['announcement_id' => $id, 'user_id' => $userId, 'now' => date('Y-m-d H:i:s')],
        );
    }

    /** @return list<array<string, mixed>> */
    public function adminList(): array
    {
        return array_map(
            $this->present(...),
            $this->query->all('SELECT * FROM announcements ORDER BY id DESC LIMIT 200'),
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->query->one('SELECT * FROM announcements WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    /** @param array<string, mixed> $data */
    public function save(?int $id, array $data, int $actorId): int
    {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));

        if ($title === '') {
            throw HttpException::badRequest('请填写公告标题');
        }

        if ($content === '') {
            throw HttpException::badRequest('请填写公告内容');
        }

        $type = (string) ($data['type'] ?? 'normal');
        $status = (string) ($data['status'] ?? 'published');

        $payload = [
            'title' => mb_substr($title, 0, 120),
            'content' => mb_substr($content, 0, 5000),
            'type' => array_key_exists($type, self::TYPE_LABELS) ? $type : 'normal',
            'priority' => (int) ($data['priority'] ?? 0),
            'starts_at' => self::nullableDate($data['starts_at'] ?? null),
            'ends_at' => self::nullableDate($data['ends_at'] ?? null),
            'pinned' => !empty($data['pinned']) ? 1 : 0,
            'forced' => !empty($data['forced']) ? 1 : 0,
            // 强制显示的公告不允许随手关掉（只能确认已知晓）
            'closable' => !empty($data['forced']) ? 0 : (array_key_exists('closable', $data) && !$data['closable'] ? 0 : 1),
            'repeat_show' => !empty($data['repeat_show']) ? 1 : 0,
            'status' => array_key_exists($status, self::STATUS_LABELS) ? $status : 'published',
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($id !== null && $id > 0) {
            $this->query->update('announcements', $payload, 'id = :id', ['id' => $id]);
            $this->audit->log($actorId, 'announcement.update', 'announcement', $id, ['title' => $title]);

            return $id;
        }

        $newId = $this->query->insert('announcements', $payload + [
            'created_by' => $actorId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log($actorId, 'announcement.create', 'announcement', $newId, ['title' => $title]);

        return $newId;
    }

    public function delete(int $id, int $actorId): void
    {
        $this->query->delete('announcements', 'id = :id', ['id' => $id]);
        $this->audit->log($actorId, 'announcement.delete', 'announcement', $id, []);
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'content' => (string) $row['content'],
            'type' => (string) $row['type'],
            'type_label' => self::TYPE_LABELS[(string) $row['type']] ?? (string) $row['type'],
            'priority' => (int) $row['priority'],
            'starts_at' => $row['starts_at'] !== null ? (string) $row['starts_at'] : null,
            'ends_at' => $row['ends_at'] !== null ? (string) $row['ends_at'] : null,
            'pinned' => (int) $row['pinned'] === 1,
            'forced' => (int) $row['forced'] === 1,
            'closable' => (int) $row['closable'] === 1,
            'repeat_show' => (int) $row['repeat_show'] === 1,
            'status' => (string) $row['status'],
            'status_label' => self::STATUS_LABELS[(string) $row['status']] ?? (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
}
