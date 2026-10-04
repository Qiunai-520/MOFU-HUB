<?php

declare(strict_types=1);

namespace Mofu\Modules\Comments;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Posts\PostRepository;
use Mofu\Modules\Posts\PostService;

final class CommentService
{
    public const CONTENT_MAX = 1000;

    public function __construct(
        private readonly Query $query,
        private readonly CommentRepository $comments,
        private readonly PostRepository $posts,
        private readonly PostService $postService,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * 发表评论或回复。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> 新评论记录（含作者信息）
     */
    public function create(int $userId, array $input): array
    {
        $postId = (int) ($input['post_id'] ?? 0);
        $content = trim((string) ($input['content'] ?? ''));
        $parentId = isset($input['parent_id']) ? (int) $input['parent_id'] : null;
        $parentId = $parentId !== null && $parentId > 0 ? $parentId : null;

        if ($content === '') {
            throw HttpException::badRequest('评论不能为空');
        }

        if (mb_strlen($content) > self::CONTENT_MAX) {
            throw HttpException::badRequest('评论太长啦，最多 ' . self::CONTENT_MAX . ' 字');
        }

        $post = $this->posts->find($postId);

        if ($post === null || !$this->postService->canView($post, $userId, false)) {
            throw HttpException::notFound('动态不存在或不可评论');
        }

        $replyToUserId = null;

        if ($parentId !== null) {
            $parent = $this->comments->find($parentId);

            if ($parent === null || (int) $parent['post_id'] !== $postId) {
                throw HttpException::badRequest('回复的评论不存在');
            }

            // 统一挂到顶层评论（两级结构）
            $topId = $parent['parent_id'] !== null ? (int) $parent['parent_id'] : $parentId;
            $replyToUserId = (int) $parent['user_id'];
            $parentId = $topId;
        }

        $now = date('Y-m-d H:i:s');

        $commentId = $this->query->transaction(function (Query $q) use ($postId, $userId, $parentId, $replyToUserId, $content, $now): int {
            $id = $q->insert('comments', [
                'post_id' => $postId,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'reply_to_user_id' => $replyToUserId,
                'content' => $content,
                'status' => 'published',
                'created_at' => $now,
            ]);

            $q->run('UPDATE posts SET comment_count = comment_count + 1 WHERE id = :id', ['id' => $postId]);

            return $id;
        });

        // 通知：回复 → 通知被回复者；否则通知动态作者
        $notifyUserId = $replyToUserId ?? (int) $post['user_id'];
        $this->notifications->push(
            $notifyUserId,
            $userId,
            $replyToUserId !== null ? 'comment_replied' : 'post_commented',
            'post',
            $postId,
            ['comment_id' => $commentId, 'excerpt' => Text::excerpt($content, 60)],
        );

        $created = $this->comments->find($commentId);

        return $created ?? [];
    }

    public function remove(int $commentId, int $userId, bool $canModerate): void
    {
        $comment = $this->comments->find($commentId);

        if ($comment === null) {
            throw HttpException::notFound('评论不存在');
        }

        if ((int) $comment['user_id'] !== $userId && !$canModerate) {
            throw HttpException::forbidden();
        }

        $this->query->transaction(function (Query $q) use ($comment): void {
            $affected = $q->update('comments', ['status' => 'removed'], "id = :id AND status <> 'removed'", ['id' => (int) $comment['id']]);

            if ($affected > 0) {
                $q->run('UPDATE posts SET comment_count = GREATEST(0, comment_count - 1) WHERE id = :id', [
                    'id' => (int) $comment['post_id'],
                ]);
            }
        });
    }

    /**
     * 评论列表（顶层分页，含回复树与点赞状态）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listForPost(int $postId, ?int $viewerId, bool $canModerate, int $page, int $perPage): array
    {
        $result = $this->comments->topLevelPage($postId, $page, $perPage);

        $allIds = [];
        foreach ($result['items'] as $item) {
            $allIds[] = (int) $item['id'];
            foreach ($item['replies'] ?? [] as $reply) {
                $allIds[] = (int) $reply['id'];
            }
        }

        $liked = $this->likedCommentIds($allIds, $viewerId);

        $result['items'] = array_map(
            fn (array $item): array => CommentPresenter::present($item, $viewerId, $canModerate, in_array((int) $item['id'], $liked, true)) + [
                'replies' => array_map(
                    fn (array $reply): array => CommentPresenter::present($reply, $viewerId, $canModerate, in_array((int) $reply['id'], $liked, true)),
                    $item['replies'] ?? [],
                ),
            ],
            $result['items'],
        );

        return $result;
    }

    /** @return list<int> */
    private function likedCommentIds(array $commentIds, ?int $viewerId): array
    {
        if ($viewerId === null || $commentIds === []) {
            return [];
        }

        $placeholders = [];
        $params = ['user_id' => $viewerId, 'kind' => 'like', 'type' => 'comment'];

        foreach (array_values($commentIds) as $i => $id) {
            $placeholders[] = ':c_' . $i;
            $params['c_' . $i] = (int) $id;
        }

        $rows = $this->query->all(
            sprintf(
                'SELECT target_id FROM reactions
                 WHERE user_id = :user_id AND kind = :kind AND target_type = :type AND target_id IN (%s)',
                implode(', ', $placeholders),
            ),
            $params,
        );

        return array_map(static fn (array $row): int => (int) $row['target_id'], $rows);
    }
}
