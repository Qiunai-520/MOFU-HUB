<?php

declare(strict_types=1);

namespace Mofu\Core\Support;

use Mofu\Core\Database\Query;

/**
 * 操作审计日志。
 */
final class AuditLogger
{
    public function __construct(private readonly Query $query)
    {
    }

    /** @param array<string, mixed> $meta */
    public function log(
        ?int $actorId,
        string $action,
        string $targetType = '',
        ?int $targetId = null,
        array $meta = [],
    ): void {
        $this->query->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta' => $meta === [] ? null : (string) json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
