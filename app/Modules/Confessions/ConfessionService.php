<?php

declare(strict_types=1);

namespace Mofu\Modules\Confessions;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\Support\AliasGenerator;
use Mofu\Core\Support\Text;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Settings\SettingsService;

/**
 * 表白墙 / 树洞服务。
 *
 * 匿名隔离原则：
 * - creator_id 与 guest_key_hash 仅用于身份鉴别与审计，绝不出现在公开输出中；
 * - 公开展示使用随机化名；署名投稿才展示用户昵称。
 */
final class ConfessionService
{
    public const CONTENT_MAX = 1000;
    public const MAX_MEDIA = 4;

    public const BOARDS = [
        'wall' => '表白墙',
        'hole' => '树洞',
    ];

    public const CATEGORIES = [
        'wall' => [
            'confession' => '表白',
            'crush' => '暗恋',
            'mood' => '心情',
            'friendship' => '友情',
            'festival' => '节日',
        ],
        'hole' => [
            'stress' => '压力',
            'emotion' => '情感',
            'daily' => '日常',
            'help' => '求助',
        ],
    ];

    public const SORTS = [
        'latest' => '最新',
        'hot' => '热门',
        'featured' => '精选',
        'random' => '随机',
    ];

    public function __construct(
        private readonly Query $query,
        private readonly ConfessionRepository $confessions,
        private readonly SettingsService $settings,
        private readonly NotificationService $notifications,
    ) {
    }

    public static function boardLabel(string $board): string
    {
        return self::BOARDS[$board] ?? '表白墙';
    }

    public static function categoryLabel(string $board, string $category): string
    {
        return self::CATEGORIES[$board][$category] ?? '';
    }

    public static function boardUrl(string $board): string
    {
        return $board === 'hole' ? '/hole' : '/wall';
    }

    public function moderationEnabled(): bool
    {
        return $this->settings->get('site.confession_moderation', 'post') === 'pre';
    }

    /**
     * 投稿。
     *
     * @param array<string, mixed>      $input
     * @param array<string, mixed>|null $user
     * @return array{id: int, guestKey: ?string, status: string, board: string}
     */
    public function submit(array $input, ?array $user): array
    {
        $board = (string) ($input['board'] ?? 'wall');

        if (!isset(self::BOARDS[$board])) {
            throw HttpException::badRequest('板块无效');
        }

        $content = trim((string) ($input['content'] ?? ''));

        if ($content === '') {
            throw HttpException::badRequest('写点内容再投稿吧');
        }

        if (mb_strlen($content) > self::CONTENT_MAX) {
            throw HttpException::badRequest('内容太长啦，最多 ' . self::CONTENT_MAX . ' 字');
        }

        $category = (string) ($input['category'] ?? '');

        if ($category !== '' && !isset(self::CATEGORIES[$board][$category])) {
            throw HttpException::badRequest('分类无效');
        }

        $mode = (string) ($input['mode'] ?? 'anonymous');
        $mode = $mode === 'named' && $user !== null ? 'named' : 'anonymous';

        $mediaIds = array_values(array_unique(array_map('intval', (array) ($input['media_ids'] ?? []))));
        $mediaIds = array_values(array_filter($mediaIds, static fn (int $id): bool => $id > 0));

        if ($mediaIds !== []) {
            if ($user === null) {
                throw HttpException::badRequest('登录后才能在投稿中附加图片');
            }

            if (count($mediaIds) > self::MAX_MEDIA) {
                throw HttpException::badRequest('最多附加 ' . self::MAX_MEDIA . ' 张图片');
            }

            $this->assertMediaOwnership((int) $user['id'], $mediaIds);
        }

        $status = $this->moderationEnabled() ? 'pending' : 'approved';
        $alias = AliasGenerator::generate();
        $guestKey = $user === null ? bin2hex(random_bytes(16)) : null;
        $now = date('Y-m-d H:i:s');

        $confessionId = $this->query->transaction(function (Query $q) use ($board, $category, $content, $mode, $user, $guestKey, $alias, $status, $mediaIds, $now): int {
            $id = $q->insert('confessions', [
                'board' => $board,
                'category' => $category,
                'content' => $content,
                'mode' => $mode,
                'creator_id' => $user !== null ? (int) $user['id'] : null,
                'guest_key_hash' => $guestKey !== null ? hash('sha256', $guestKey) : null,
                'alias' => $alias,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($mediaIds as $index => $mediaId) {
                $q->insert('confession_media', [
                    'confession_id' => $id,
                    'media_id' => $mediaId,
                    'sort_order' => $index,
                ]);
            }

            return $id;
        });

        return ['id' => $confessionId, 'guestKey' => $guestKey, 'status' => $status, 'board' => $board];
    }

    /**
     * 删除投稿（作者 / 游客凭证 / 审核权限）。
     */
    public function delete(int $id, ?array $user, ?string $guestKey): void
    {
        $row = $this->confessions->find($id);

        if ($row === null || $row['deleted_at'] !== null) {
            throw HttpException::notFound('投稿不存在');
        }

        $allowed = false;

        if ($user !== null) {
            if ($row['creator_id'] !== null && (int) $row['creator_id'] === (int) $user['id']) {
                $allowed = true;
            }

            if (Guard::can($user, 'post.moderate')) {
                $allowed = true;
            }
        }

        if (!$allowed && $guestKey !== null && $row['guest_key_hash'] !== null) {
            $allowed = hash_equals((string) $row['guest_key_hash'], hash('sha256', $guestKey));
        }

        if (!$allowed) {
            throw HttpException::forbidden();
        }

        $this->confessions->softDelete($id);
    }

    /**
     * 判断当前访客能否查看该投稿。
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $user
     */
    public function viewable(array $row, ?array $user): bool
    {
        if ($row['status'] === 'approved' && $row['deleted_at'] === null) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if (Guard::can($user, 'post.moderate')) {
            return true;
        }

        return $row['creator_id'] !== null && (int) $row['creator_id'] === (int) $user['id'];
    }

    /**
     * 发表评论（需登录；评论一律以化名展示）。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> 新评论记录
     */
    public function createComment(int $confessionId, array $user, array $input): array
    {
        $row = $this->confessions->find($confessionId);

        if ($row === null || $row['status'] !== 'approved' || $row['deleted_at'] !== null) {
            throw HttpException::notFound('内容不存在或不可评论');
        }

        $content = trim((string) ($input['content'] ?? ''));

        if ($content === '') {
            throw HttpException::badRequest('评论不能为空');
        }

        if (mb_strlen($content) > 1000) {
            throw HttpException::badRequest('评论太长啦，最多 1000 字');
        }

        $parentId = isset($input['parent_id']) ? (int) $input['parent_id'] : null;
        $parentId = $parentId !== null && $parentId > 0 ? $parentId : null;
        $replyToUserId = null;

        if ($parentId !== null) {
            $parent = $this->confessions->findComment($parentId);

            if ($parent === null || (int) $parent['confession_id'] !== $confessionId) {
                throw HttpException::badRequest('回复的评论不存在');
            }

            $replyToUserId = $parent['user_id'] !== null ? (int) $parent['user_id'] : null;
            $parentId = $parent['parent_id'] !== null ? (int) $parent['parent_id'] : $parentId;
        }

        $alias = AliasGenerator::generate();
        $now = date('Y-m-d H:i:s');

        $commentId = $this->query->transaction(function (Query $q) use ($confessionId, $user, $parentId, $replyToUserId, $alias, $content, $now): int {
            $id = $q->insert('confession_comments', [
                'confession_id' => $confessionId,
                'user_id' => (int) $user['id'],
                'parent_id' => $parentId,
                'reply_to_user_id' => $replyToUserId,
                'alias' => $alias,
                'content' => $content,
                'status' => 'published',
                'created_at' => $now,
            ]);

            $q->run('UPDATE confessions SET comment_count = comment_count + 1 WHERE id = :id', ['id' => $confessionId]);

            return $id;
        });

        // 通知投稿者（若为登录用户；通知不暴露评论者身份，仅显示化名）
        if ($row['creator_id'] !== null && (int) $row['creator_id'] !== (int) $user['id']) {
            $this->notifications->push(
                (int) $row['creator_id'],
                (int) $user['id'],
                'confession_commented',
                'confession',
                $confessionId,
                [
                    'url' => self::boardUrl((string) $row['board']) . '/' . $confessionId,
                    'excerpt' => Text::excerpt($content, 60),
                    'alias' => $alias,
                ],
            );
        }

        return $this->confessions->findComment($commentId) ?? [];
    }

    public function removeComment(int $commentId, array $user, bool $canModerate): void
    {
        $comment = $this->confessions->findComment($commentId);

        if ($comment === null) {
            throw HttpException::notFound('评论不存在');
        }

        $isOwner = $comment['user_id'] !== null && (int) $comment['user_id'] === (int) $user['id'];

        if (!$isOwner && !$canModerate) {
            throw HttpException::forbidden();
        }

        $this->query->transaction(function (Query $q) use ($comment, $commentId): void {
            $affected = $q->update('confession_comments', ['status' => 'removed'], "id = :id AND status <> 'removed'", ['id' => $commentId]);

            if ($affected > 0) {
                $q->run('UPDATE confessions SET comment_count = GREATEST(0, comment_count - 1) WHERE id = :id', [
                    'id' => (int) $comment['confession_id'],
                ]);
            }
        });
    }

    /**
     * 板块列表（含展示转换与当前用户的互动状态）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listBoard(string $board, string $sort, string $category, int $page, ?int $viewerId, bool $canModerate): array
    {
        $result = $this->confessions->feed($board, $sort, $category, $page, 12);

        $result['items'] = $this->presentRows($result['items'], $viewerId, $canModerate);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function presentRows(array $rows, ?int $viewerId, bool $canModerate): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $mediaMap = $this->confessions->mediaForMany($ids);
        $likedSet = array_flip($this->reactionIds($ids, $viewerId, 'like'));
        $favoritedSet = array_flip($this->reactionIds($ids, $viewerId, 'favorite'));

        return array_map(
            fn (array $row): array => ConfessionPresenter::present(
                $row,
                $mediaMap[(int) $row['id']] ?? [],
                $viewerId,
                $canModerate,
                isset($likedSet[(int) $row['id']]),
                isset($favoritedSet[(int) $row['id']]),
            ),
            $rows,
        );
    }

    /**
     * 单条投稿（含展示转换）。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function presentOne(array $row, ?int $viewerId, bool $canModerate): array
    {
        $id = (int) $row['id'];
        $liked = in_array($id, $this->reactionIds([$id], $viewerId, 'like'), true);
        $favorited = in_array($id, $this->reactionIds([$id], $viewerId, 'favorite'), true);

        return ConfessionPresenter::present(
            $row,
            $this->confessions->mediaFor($id),
            $viewerId,
            $canModerate,
            $liked,
            $favorited,
        );
    }

    /**
     * 评论列表（含展示转换）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listComments(int $confessionId, ?int $viewerId, bool $canModerate, int $page): array
    {
        $result = $this->confessions->commentPage($confessionId, $page, 20);

        $allIds = [];
        foreach ($result['items'] as $item) {
            $allIds[] = (int) $item['id'];
            foreach ($item['replies'] ?? [] as $reply) {
                $allIds[] = (int) $reply['id'];
            }
        }

        $likedSet = array_flip($this->reactionIds($allIds, $viewerId, 'like', 'confession_comment'));

        $result['items'] = array_map(
            fn (array $item): array => ConfessionPresenter::presentComment($item, $viewerId, $canModerate, isset($likedSet[(int) $item['id']])) + [
                'replies' => array_map(
                    fn (array $reply): array => ConfessionPresenter::presentComment($reply, $viewerId, $canModerate, isset($likedSet[(int) $reply['id']])),
                    $item['replies'] ?? [],
                ),
            ],
            $result['items'],
        );

        return $result;
    }

    /**
     * 我的投稿（登录用户 + 游客凭证）。
     *
     * @param array<int, string> $guestKeys confession_id => guest key
     * @return list<array<string, mixed>>
     */
    public function mySubmissions(?array $user, array $guestKeys): array
    {
        $rows = [];

        if ($user !== null) {
            $rows = $this->confessions->byUser((int) $user['id']);
        }

        if ($guestKeys !== []) {
            foreach ($this->confessions->byGuestIds(array_keys($guestKeys)) as $row) {
                $key = $guestKeys[(int) $row['id']] ?? null;

                if ($key !== null && $row['guest_key_hash'] !== null && hash_equals((string) $row['guest_key_hash'], hash('sha256', $key))) {
                    $rows[] = $row;
                }
            }
        }

        usort($rows, static fn (array $a, array $b): int => (int) $b['id'] <=> (int) $a['id']);

        return $this->presentRows($rows, $user !== null ? (int) $user['id'] : null, false);
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function reactionIds(array $ids, ?int $viewerId, string $kind, string $targetType = 'confession'): array
    {
        if ($viewerId === null || $ids === []) {
            return [];
        }

        $placeholders = [];
        $params = ['user_id' => $viewerId, 'kind' => $kind, 'type' => $targetType];

        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':r_' . $i;
            $params['r_' . $i] = (int) $id;
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

    private function assertMediaOwnership(int $userId, array $mediaIds): void
    {
        $placeholders = [];
        $params = ['user_id' => $userId];

        foreach ($mediaIds as $i => $id) {
            $placeholders[] = ':m_' . $i;
            $params['m_' . $i] = $id;
        }

        $count = (int) $this->query->value(
            sprintf('SELECT COUNT(*) FROM media WHERE user_id = :user_id AND id IN (%s)', implode(', ', $placeholders)),
            $params,
        );

        if ($count !== count($mediaIds)) {
            throw HttpException::forbidden('包含无效的媒体文件');
        }
    }
}
