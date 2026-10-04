<?php

declare(strict_types=1);

namespace Mofu\Modules\Reactions;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Modules\Confessions\ConfessionService;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Posts\PostRepository;
use Mofu\Modules\Posts\PostService;

/**
 * 点赞/收藏切换。重复操作与并发请求由唯一键 + 事务保证幂等。
 * 支持对象：动态（post）、评论（comment）、表白墙/树洞（confession）、匿名评论（confession_comment）。
 */
final class ReactionService
{
    /** kind => [目标类型 => 计数列] */
    private const COUNTERS = [
        'like' => [
            'post' => 'like_count',
            'comment' => 'like_count',
            'confession' => 'like_count',
            'confession_comment' => 'like_count',
            'board_entry' => 'like_count',
        ],
        'favorite' => [
            'post' => 'favorite_count',
            'confession' => 'favorite_count',
            'board_entry' => 'favorite_count',
        ],
    ];

    private const TABLES = [
        'post' => 'posts',
        'comment' => 'comments',
        'confession' => 'confessions',
        'confession_comment' => 'confession_comments',
        'board_entry' => 'board_entries',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly PostRepository $posts,
        private readonly PostService $postService,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * 切换互动状态。
     *
     * @return array{active: bool, count: int}
     */
    public function toggle(int $userId, string $targetType, int $targetId, string $kind): array
    {
        if (!isset(self::TABLES[$targetType])) {
            throw HttpException::badRequest('不支持的互动对象');
        }

        if (!isset(self::COUNTERS[$kind][$targetType])) {
            throw HttpException::badRequest('不支持的互动类型');
        }

        $target = $this->loadTarget($targetType, $targetId, $userId);
        $counterColumn = self::COUNTERS[$kind][$targetType];
        $table = self::TABLES[$targetType];

        $existing = $this->query->value(
            'SELECT id FROM reactions
             WHERE user_id = :user_id AND target_type = :target_type AND target_id = :target_id AND kind = :kind',
            ['user_id' => $userId, 'target_type' => $targetType, 'target_id' => $targetId, 'kind' => $kind],
        );

        // 计数增减以实际生效的插入/删除为准，避免并发下与 reactions 行数漂移
        $created = false;

        $result = $this->query->transaction(function (Query $q) use ($existing, $userId, $targetType, $targetId, $kind, $counterColumn, $table, &$created): array {
            $active = $existing === null;
            $delta = 0;

            if ($active) {
                try {
                    $q->insert('reactions', [
                        'user_id' => $userId,
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'kind' => $kind,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $delta = 1;
                    $created = true;
                } catch (\PDOException $e) {
                    // 并发下唯一键冲突：对方已插入，本次不重复计数
                    if ((string) $e->getCode() !== '23000') {
                        throw $e;
                    }
                }
            } else {
                $removed = $q->delete('reactions', 'id = :id', ['id' => (int) $existing]);

                if ($removed > 0) {
                    $delta = -1;
                }
            }

            if ($delta !== 0) {
                $q->run(
                    sprintf('UPDATE %s SET %s = GREATEST(0, %s + :delta) WHERE id = :id', $table, $counterColumn, $counterColumn),
                    ['delta' => $delta, 'id' => $targetId],
                );
            }

            $count = (int) $q->value(
                sprintf('SELECT %s FROM %s WHERE id = :id', $counterColumn, $table),
                ['id' => $targetId],
            );

            return ['active' => $active, 'count' => $count];
        });

        if ($created && $kind === 'like') {
            $this->notifyLike($userId, $targetType, $targetId, $target);
        }

        return $result;
    }

    /** @param array<string, mixed> $target */
    private function notifyLike(int $userId, string $targetType, int $targetId, array $target): void
    {
        $excerpt = Text::excerpt((string) ($target['content'] ?? ''), 60);

        [$notifyUserId, $type, $url] = match ($targetType) {
            'post' => [
                (int) $target['user_id'],
                'post_liked',
                '/posts/' . $targetId,
            ],
            'comment' => [
                (int) $target['user_id'],
                'comment_liked',
                '/posts/' . (int) $target['post_id'] . '#comments',
            ],
            'confession' => [
                $target['creator_id'] !== null ? (int) $target['creator_id'] : null,
                'confession_liked',
                ConfessionService::boardUrl((string) $target['board']) . '/' . $targetId,
            ],
            'confession_comment' => [
                $target['user_id'] !== null ? (int) $target['user_id'] : null,
                'comment_liked',
                ConfessionService::boardUrl((string) $target['board']) . '/' . (int) $target['confession_id'],
            ],
            'board_entry' => [
                (int) $target['user_id'],
                'entry_liked',
                '/boards/' . rawurlencode((string) $target['board_slug']) . '/' . $targetId,
            ],
            default => [null, '', ''],
        };

        if ($notifyUserId === null || $type === '') {
            return;
        }

        $this->notifications->push($notifyUserId, $userId, $type, $targetType, $targetId, [
            'excerpt' => $excerpt,
            'url' => $url,
        ]);
    }

    /** @return array<string, mixed> */
    private function loadTarget(string $targetType, int $targetId, int $userId): array
    {
        switch ($targetType) {
            case 'post': {
                $post = $this->posts->find($targetId);

                if ($post === null || !$this->postService->canView($post, $userId, false)) {
                    throw HttpException::notFound('动态不存在');
                }

                return $post;
            }

            case 'comment': {
                $comment = $this->query->one(
                    "SELECT c.* FROM comments c WHERE c.id = :id AND c.status = 'published'",
                    ['id' => $targetId],
                );

                if ($comment === null) {
                    throw HttpException::notFound('评论不存在');
                }

                // 评论所属动态必须对当前用户可见
                $post = $this->posts->find((int) $comment['post_id']);

                if ($post === null || !$this->postService->canView($post, $userId, false)) {
                    throw HttpException::notFound('评论不存在');
                }

                return $comment;
            }

            case 'confession': {
                $confession = $this->query->one(
                    "SELECT c.* FROM confessions c WHERE c.id = :id AND c.status = 'approved' AND c.deleted_at IS NULL",
                    ['id' => $targetId],
                );

                if ($confession === null) {
                    throw HttpException::notFound('内容不存在');
                }

                return $confession;
            }

            case 'confession_comment': {
                $comment = $this->query->one(
                    "SELECT cc.*, c.board FROM confession_comments cc
                     JOIN confessions c ON c.id = cc.confession_id
                     WHERE cc.id = :id AND cc.status = 'published'",
                    ['id' => $targetId],
                );

                if ($comment === null) {
                    throw HttpException::notFound('评论不存在');
                }

                return $comment;
            }

            case 'board_entry': {
                $entry = $this->query->one(
                    "SELECT e.*, b.slug AS board_slug FROM board_entries e
                     JOIN boards b ON b.id = e.board_id
                     WHERE e.id = :id AND e.status = 'published' AND e.deleted_at IS NULL",
                    ['id' => $targetId],
                );

                if ($entry === null) {
                    throw HttpException::notFound('帖子不存在');
                }

                return $entry;
            }
        }

        throw HttpException::badRequest('不支持的互动对象');
    }
}
