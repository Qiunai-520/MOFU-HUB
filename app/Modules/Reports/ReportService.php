<?php

declare(strict_types=1);

namespace Mofu\Modules\Reports;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Mofu\Modules\Confessions\ConfessionService;

final class ReportService
{
    public const REASONS = [
        'spam' => '垃圾广告',
        'harassment' => '骚扰辱骂',
        'porn' => '色情低俗',
        'illegal' => '违法违规',
        'infringement' => '侵权内容',
        'other' => '其他',
    ];

    public const TARGET_TYPES = ['post', 'comment', 'confession', 'confession_comment'];

    public function __construct(
        private readonly Query $query,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * 提交举报（需登录；同一目标未处理前不可重复举报）。
     *
     * @param array<string, mixed> $input
     */
    public function submit(int $reporterId, array $input): void
    {
        $targetType = (string) ($input['target_type'] ?? '');
        $targetId = (int) ($input['target_id'] ?? 0);
        $reason = (string) ($input['reason'] ?? '');
        $detail = trim((string) ($input['detail'] ?? ''));

        if (!in_array($targetType, self::TARGET_TYPES, true)) {
            throw HttpException::badRequest('举报对象无效');
        }

        if ($targetId <= 0) {
            throw HttpException::badRequest('举报对象无效');
        }

        if (!isset(self::REASONS[$reason])) {
            throw HttpException::badRequest('请选择举报原因');
        }

        if (mb_strlen($detail) > 500) {
            throw HttpException::badRequest('补充说明最多 500 字');
        }

        if (!$this->limiter->attempt('report:' . $reporterId, 10, 3600)) {
            throw HttpException::tooManyRequests('举报太频繁，请稍后再试');
        }

        $this->assertTargetExists($targetType, $targetId);

        $duplicate = $this->query->value(
            "SELECT id FROM reports
             WHERE reporter_id = :reporter AND target_type = :type AND target_id = :target AND status = 'pending'",
            ['reporter' => $reporterId, 'type' => $targetType, 'target' => $targetId],
        );

        if ($duplicate !== null) {
            throw HttpException::badRequest('你已举报过该内容，正在处理中');
        }

        $this->query->insert('reports', [
            'reporter_id' => $reporterId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'detail' => $detail,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($targetType === 'confession') {
            $this->query->run(
                'UPDATE confessions SET report_count = report_count + 1 WHERE id = :id',
                ['id' => $targetId],
            );
        }
    }

    /**
     * 后台举报队列。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function queue(string $status, int $page, int $perPage): array
    {
        $where = 'FROM reports r JOIN users u ON u.id = r.reporter_id WHERE 1 = 1';
        $params = [];

        if (in_array($status, ['pending', 'accepted', 'rejected'], true)) {
            $where .= ' AND r.status = :status';
            $params['status'] = $status;
        }

        $sql = 'SELECT r.*, u.username AS reporter_username ' . $where . " ORDER BY (r.status = 'pending') DESC, r.id DESC";
        $countSql = 'SELECT COUNT(*) ' . $where;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);

        $targets = $this->loadTargets($result['items']);

        $result['items'] = array_map(
            static fn (array $row): array => self::presentReport($row, $targets),
            $result['items'],
        );

        return $result;
    }

    /**
     * 批量取举报目标的内容摘要与定位信息，避免逐条查询导致的 N+1。
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array{excerpt: string, parent_id: int, board: string}>
     */
    private function loadTargets(array $rows): array
    {
        $idsByType = [];
        foreach ($rows as $row) {
            $idsByType[(string) $row['target_type']][] = (int) $row['target_id'];
        }

        $targets = [];

        foreach ($idsByType as $type => $ids) {
            $ids = array_values(array_unique($ids));
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            $selected = match ($type) {
                'post' => $this->query->all(
                    sprintf('SELECT id, content FROM posts WHERE id IN (%s)', $placeholders),
                    $ids,
                ),
                'comment' => $this->query->all(
                    sprintf('SELECT id, content, post_id FROM comments WHERE id IN (%s)', $placeholders),
                    $ids,
                ),
                'confession' => $this->query->all(
                    sprintf('SELECT id, content, board FROM confessions WHERE id IN (%s)', $placeholders),
                    $ids,
                ),
                'confession_comment' => $this->query->all(
                    sprintf(
                        'SELECT cc.id, cc.content, cc.confession_id, c.board
                         FROM confession_comments cc JOIN confessions c ON c.id = cc.confession_id
                         WHERE cc.id IN (%s)',
                        $placeholders,
                    ),
                    $ids,
                ),
                default => [],
            };

            foreach ($selected as $item) {
                $targets[$type . ':' . (int) $item['id']] = [
                    'excerpt' => mb_substr((string) $item['content'], 0, 100),
                    'parent_id' => (int) ($item['post_id'] ?? $item['confession_id'] ?? 0),
                    'board' => (string) ($item['board'] ?? 'wall'),
                ];
            }
        }

        return $targets;
    }

    public function pendingCount(): int
    {
        return (int) $this->query->value("SELECT COUNT(*) FROM reports WHERE status = 'pending'");
    }

    /**
     * 处理举报：接受（同时下架目标内容）或驳回。
     *
     * @return array{target_type: string, target_id: int, target_owner: ?int, accepted: bool}
     */
    public function resolve(int $reportId, int $handlerId, bool $accepted): array
    {
        $report = $this->query->one('SELECT * FROM reports WHERE id = :id', ['id' => $reportId]);

        if ($report === null) {
            throw HttpException::notFound('举报记录不存在');
        }

        if ($report['status'] !== 'pending') {
            throw HttpException::badRequest('该举报已处理');
        }

        $targetType = (string) $report['target_type'];
        $targetId = (int) $report['target_id'];
        $targetOwner = null;

        if ($accepted) {
            $targetOwner = $this->hideTarget($targetType, $targetId);
        }

        $this->query->update('reports', [
            'status' => $accepted ? 'accepted' : 'rejected',
            'handled_by' => $handlerId,
            'handled_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $reportId]);

        return [
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_owner' => $targetOwner,
            'accepted' => $accepted,
        ];
    }

    public function find(int $reportId): ?array
    {
        return $this->query->one('SELECT * FROM reports WHERE id = :id', ['id' => $reportId]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array{excerpt: string, parent_id: int, board: string}> $targets
     */
    private static function presentReport(array $row, array $targets): array
    {
        $targetType = (string) $row['target_type'];
        $targetId = (int) $row['target_id'];
        $target = $targets[$targetType . ':' . $targetId] ?? null;

        return [
            'id' => (int) $row['id'],
            'target_type' => $targetType,
            'target_type_label' => self::targetTypeLabel($targetType),
            'target_id' => $targetId,
            'target_url' => self::targetUrl($targetType, $targetId, $target),
            'target_excerpt' => $target['excerpt'] ?? '（内容已不存在）',
            'reason' => (string) $row['reason'],
            'reason_label' => self::REASONS[(string) $row['reason']] ?? (string) $row['reason'],
            'detail' => (string) $row['detail'],
            'status' => (string) $row['status'],
            'reporter_username' => (string) ($row['reporter_username'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'handled_at' => $row['handled_at'],
        ];
    }

    public static function targetTypeLabel(string $type): string
    {
        return match ($type) {
            'post' => '动态',
            'comment' => '评论',
            'confession' => '表白墙/树洞',
            'confession_comment' => '匿名评论',
            default => $type,
        };
    }

    /** @param array{parent_id?: int, board?: string}|null $target */
    private static function targetUrl(string $type, int $id, ?array $target): string
    {
        return match ($type) {
            'post' => '/posts/' . $id,
            'comment' => '/posts/' . (int) ($target['parent_id'] ?? 0) . '#comments',
            'confession' => ConfessionService::boardUrl((string) ($target['board'] ?? 'wall')) . '/' . $id,
            'confession_comment' => ConfessionService::boardUrl((string) ($target['board'] ?? 'wall')) . '/' . (int) ($target['parent_id'] ?? 0),
            default => '/',
        };
    }

    private function assertTargetExists(string $type, int $id): void
    {
        [$table, $extra] = match ($type) {
            'post' => ['posts', 'AND deleted_at IS NULL'],
            'comment' => ['comments', ''],
            'confession' => ['confessions', 'AND deleted_at IS NULL'],
            'confession_comment' => ['confession_comments', ''],
        };

        $exists = $this->query->value(
            sprintf('SELECT id FROM %s WHERE id = :id %s', $table, $extra),
            ['id' => $id],
        );

        if ($exists === null) {
            throw HttpException::notFound('举报的内容不存在');
        }
    }

    /**
     * 接受举报时下架目标内容，返回内容作者的 user id（用于通知）。
     */
    private function hideTarget(string $type, int $id): ?int
    {
        switch ($type) {
            case 'post':
                $owner = $this->query->value('SELECT user_id FROM posts WHERE id = :id', ['id' => $id]);
                $this->query->run("UPDATE posts SET status = 'hidden', updated_at = :now WHERE id = :id", [
                    'id' => $id,
                    'now' => date('Y-m-d H:i:s'),
                ]);

                return $owner !== null ? (int) $owner : null;

            case 'comment':
                $row = $this->query->one('SELECT user_id, post_id FROM comments WHERE id = :id', ['id' => $id]);

                if ($row === null) {
                    return null;
                }

                $this->query->transaction(function (Query $q) use ($id, $row): void {
                    $affected = $q->update('comments', ['status' => 'removed'], "id = :id AND status <> 'removed'", ['id' => $id]);

                    if ($affected > 0) {
                        $q->run('UPDATE posts SET comment_count = GREATEST(0, comment_count - 1) WHERE id = :id', [
                            'id' => (int) $row['post_id'],
                        ]);
                    }
                });

                return (int) $row['user_id'];

            case 'confession':
                $owner = $this->query->value('SELECT creator_id FROM confessions WHERE id = :id', ['id' => $id]);
                $this->query->run("UPDATE confessions SET status = 'hidden', updated_at = :now WHERE id = :id", [
                    'id' => $id,
                    'now' => date('Y-m-d H:i:s'),
                ]);

                return $owner !== null ? (int) $owner : null;

            case 'confession_comment':
                $row = $this->query->one('SELECT user_id, confession_id FROM confession_comments WHERE id = :id', ['id' => $id]);

                if ($row === null) {
                    return null;
                }

                $this->query->transaction(function (Query $q) use ($id, $row): void {
                    $affected = $q->update('confession_comments', ['status' => 'removed'], "id = :id AND status <> 'removed'", ['id' => $id]);

                    if ($affected > 0) {
                        $q->run('UPDATE confessions SET comment_count = GREATEST(0, comment_count - 1) WHERE id = :id', [
                            'id' => (int) $row['confession_id'],
                        ]);
                    }
                });

                return $row['user_id'] !== null ? (int) $row['user_id'] : null;
        }

        return null;
    }
}
