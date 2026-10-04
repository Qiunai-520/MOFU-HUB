<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Modules\Communities\CommunityService;
use Mofu\Modules\Notifications\NotificationService;

final class PostService
{
    public const CONTENT_MAX = 2000;
    public const MAX_MEDIA = 9;
    public const MAX_TOPICS = 5;
    public const TOPIC_NAME_MAX = 24;

    public function __construct(
        private readonly Query $query,
        private readonly PostRepository $posts,
        private readonly CommunityService $communities,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * 创建动态（文本 + 图片 + 话题 + 可见范围）。
     *
     * @param array<string, mixed> $input
     * @return int 新动态 id
     */
    public function create(int $userId, array $input): int
    {
        [$content, $visibility, $mediaIds, $topics] = $this->validate($input, requireMediaOrContent: true);
        $this->assertMediaOwnership($userId, $mediaIds);

        [$videoCount, $imageCount] = $this->mediaKindCounts($userId, $mediaIds);

        if ($videoCount > 1) {
            throw HttpException::badRequest('一条动态只能包含 1 个视频');
        }

        if ($videoCount === 1 && ($imageCount > 0 || count($mediaIds) > 1)) {
            throw HttpException::badRequest('视频不能与图片同时发布');
        }

        $type = $videoCount === 1 ? 'video' : ($mediaIds === [] ? 'text' : 'image');

        $communityId = $this->resolveCommunity($userId, $input);

        $now = date('Y-m-d H:i:s');

        return $this->query->transaction(function (Query $q) use ($userId, $content, $visibility, $mediaIds, $topics, $communityId, $type, $now): int {
            $postId = $q->insert('posts', [
                'user_id' => $userId,
                'community_id' => $communityId,
                'type' => $type,
                'title' => '',
                'content' => $content,
                'visibility' => $visibility,
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($mediaIds as $i => $mediaId) {
                $q->insert('post_media', [
                    'post_id' => $postId,
                    'media_id' => $mediaId,
                    'sort_order' => $i,
                ]);
            }

            $this->syncTopics($q, $postId, [], $topics, $now);

            return $postId;
        });
    }

    /**
     * 编辑动态（文本、可见范围与话题；图片编辑留待后续）。
     *
     * @param array<string, mixed> $input
     */
    public function update(int $postId, array $input, int $userId, bool $canModerate): void
    {
        $post = $this->findOrFail($postId);
        $this->assertCanEdit($post, $userId, $canModerate);

        [$content, $visibility, , $topics] = $this->validate($input, requireMediaOrContent: false);

        $hasMedia = (int) $this->query->value(
            'SELECT COUNT(*) FROM post_media WHERE post_id = :id',
            ['id' => $postId],
        ) > 0;

        if ($content === '' && !$hasMedia) {
            throw HttpException::badRequest('写点什么或配张图吧');
        }

        $now = date('Y-m-d H:i:s');

        $this->query->transaction(function (Query $q) use ($postId, $content, $visibility, $topics, $now): void {
            $q->update('posts', [
                'content' => $content,
                'visibility' => $visibility,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $postId]);

            $current = $q->all(
                'SELECT t.id, t.name FROM post_topics pt JOIN topics t ON t.id = pt.topic_id WHERE pt.post_id = :id',
                ['id' => $postId],
            );

            $this->syncTopics($q, $postId, $current, $topics, $now);
        });
    }

    public function delete(int $postId, int $userId, bool $canModerate): void
    {
        $post = $this->findOrFail($postId);
        $this->assertCanEdit($post, $userId, $canModerate);
        $this->posts->softDelete($postId);
    }

    /**
     * 快捷转发（转发到自己的动态流，可取消）。
     */
    public function repost(int $userId, int $postId): int
    {
        $target = $this->findOrFail($postId);

        if ((int) $target['user_id'] === $userId) {
            throw HttpException::badRequest('不能转发自己的动态');
        }

        if ((string) $target['status'] !== 'published') {
            throw HttpException::notFound('动态不存在或不可转发');
        }

        if ((string) $target['visibility'] !== 'public') {
            throw HttpException::badRequest('不能转发私密动态');
        }

        if ($target['community_id'] !== null) {
            throw HttpException::badRequest('圈内动态不能转发');
        }

        if ($target['repost_of_id'] !== null) {
            throw HttpException::badRequest('转发的动态不能再转发');
        }

        if ($this->posts->myRepostOf($postId, $userId) !== null) {
            throw HttpException::badRequest('你已转发过这条动态');
        }

        $now = date('Y-m-d H:i:s');

        $repostId = $this->query->transaction(function (Query $q) use ($userId, $postId, $now): int {
            $id = $q->insert('posts', [
                'user_id' => $userId,
                'repost_of_id' => $postId,
                'type' => 'repost',
                'title' => '',
                'content' => '',
                'visibility' => 'public',
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $q->run('UPDATE posts SET repost_count = repost_count + 1 WHERE id = :id', ['id' => $postId]);

            return $id;
        });

        $this->notifications->push((int) $target['user_id'], $userId, 'post_reposted', 'post', $postId, [
            'excerpt' => Text::excerpt((string) $target['content']),
            'url' => '/posts/' . $postId,
        ]);

        return $repostId;
    }

    /**
     * 取消转发。
     */
    public function undoRepost(int $userId, int $postId): void
    {
        $this->findOrFail($postId);

        $repostId = $this->posts->myRepostOf($postId, $userId);

        if ($repostId === null) {
            throw HttpException::badRequest('你还没有转发这条动态');
        }

        $this->query->transaction(function (Query $q) use ($repostId, $postId): void {
            $q->update('posts', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $repostId]);
            $q->run('UPDATE posts SET repost_count = GREATEST(0, repost_count - 1) WHERE id = :id', ['id' => $postId]);
        });
    }

    /**
     * 判断访客能否查看该动态。
     *
     * 圈子帖：仅圈子成员可见（圈内私密帖仅作者），其余场景沿用公开/私密规则。
     * 私密帖（仅自己可见）只有作者本人可见，管理员也不行；审核走后台列表。
     *
     * @param array<string, mixed> $post
     */
    public function canView(array $post, ?int $viewerId, bool $canModerate): bool
    {
        $isPublished = (string) $post['status'] === 'published';
        $notDeleted = $post['deleted_at'] === null;

        if ($viewerId !== null && (int) $post['user_id'] === $viewerId) {
            return true;
        }

        if ((string) $post['visibility'] !== 'public') {
            return false;
        }

        // 已隐藏/已删除的公开帖：仅管理员可查看，便于审核
        if (!$isPublished || !$notDeleted) {
            return $canModerate;
        }

        $communityId = $post['community_id'] !== null ? (int) $post['community_id'] : null;

        if ($communityId !== null) {
            return $this->communities->isMember($communityId, $viewerId);
        }

        return true;
    }

    /**
     * 解析目标圈子：非空时必须是活跃圈子且发布者为成员。
     *
     * @param array<string, mixed> $input
     */
    private function resolveCommunity(int $userId, array $input): ?int
    {
        $communityId = (int) ($input['community_id'] ?? 0);

        if ($communityId <= 0) {
            return null;
        }

        if ($this->communities->findActiveById($communityId) === null
            || !$this->communities->isMember($communityId, $userId)) {
            throw HttpException::forbidden('加入圈子后才能发布内容');
        }

        return $communityId;
    }

    /** @return array<string, mixed> */
    private function findOrFail(int $postId): array
    {
        $post = $this->posts->find($postId);

        if ($post === null || $post['deleted_at'] !== null) {
            throw HttpException::notFound('动态不存在或已删除');
        }

        return $post;
    }

    /** @param array<string, mixed> $post */
    private function assertCanEdit(array $post, int $userId, bool $canModerate): void
    {
        if ((int) $post['user_id'] !== $userId && !$canModerate) {
            throw HttpException::forbidden();
        }
    }

    /**
     * 校验并规范化输入。
     *
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string, 2: list<int>, 3: list<string>}
     */
    private function validate(array $input, bool $requireMediaOrContent): array
    {
        $content = trim((string) ($input['content'] ?? ''));
        $visibility = (string) ($input['visibility'] ?? 'public');
        $mediaIds = array_values(array_unique(array_map('intval', (array) ($input['media_ids'] ?? []))));
        $mediaIds = array_values(array_filter($mediaIds, static fn (int $id): bool => $id > 0));
        $topics = $this->normalizeTopics((array) ($input['topics'] ?? []));

        if (!in_array($visibility, ['public', 'private'], true)) {
            throw HttpException::badRequest('可见范围无效');
        }

        if (mb_strlen($content) > self::CONTENT_MAX) {
            throw HttpException::badRequest('内容太长啦，最多 ' . self::CONTENT_MAX . ' 字');
        }

        if (count($mediaIds) > self::MAX_MEDIA) {
            throw HttpException::badRequest('最多上传 ' . self::MAX_MEDIA . ' 张图片');
        }

        if ($requireMediaOrContent && $content === '' && $mediaIds === []) {
            throw HttpException::badRequest('写点什么或配张图吧');
        }

        return [$content, $visibility, $mediaIds, $topics];
    }

    /** @return list<string> */
    private function normalizeTopics(array $topics): array
    {
        $out = [];

        foreach ($topics as $topic) {
            if (!is_string($topic)) {
                continue;
            }

            $name = Text::normalizeTopicName($topic);

            if ($name === '' || mb_strlen($name) > self::TOPIC_NAME_MAX) {
                continue;
            }

            $key = mb_strtolower($name);
            if (!isset($out[$key])) {
                $out[$key] = $name;
            }

            if (count($out) >= self::MAX_TOPICS) {
                break;
            }
        }

        return array_values($out);
    }

    private function assertMediaOwnership(int $userId, array $mediaIds): void
    {
        if ($mediaIds === []) {
            return;
        }

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

    /**
     * @param list<int> $mediaIds
     * @return array{0: int, 1: int} [视频数, 图片数]
     */
    private function mediaKindCounts(int $userId, array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [0, 0];
        }

        $placeholders = [];
        $params = ['user_id' => $userId];

        foreach ($mediaIds as $i => $id) {
            $placeholders[] = ':m_' . $i;
            $params['m_' . $i] = $id;
        }

        $rows = $this->query->all(
            sprintf(
                'SELECT kind, COUNT(*) AS total FROM media WHERE user_id = :user_id AND id IN (%s) GROUP BY kind',
                implode(', ', $placeholders),
            ),
            $params,
        );

        $video = 0;
        $image = 0;

        foreach ($rows as $row) {
            if ((string) $row['kind'] === 'video') {
                $video = (int) $row['total'];
            } elseif ((string) $row['kind'] === 'image') {
                $image = (int) $row['total'];
            }
        }

        return [$video, $image];
    }

    /**
     * 同步动态的话题关联（先移除不再使用的，再添加新的）。
     *
     * @param list<array<string, mixed>> $current 现有关联（含 name）
     * @param list<string>               $target  目标话题名
     */
    private function syncTopics(Query $q, int $postId, array $current, array $target, string $now): void
    {
        $currentNames = [];
        foreach ($current as $row) {
            $currentNames[(string) $row['id']] = (string) $row['name'];
        }

        $targetKeys = array_map(static fn (string $name): string => mb_strtolower($name), $target);

        foreach ($currentNames as $topicId => $name) {
            if (!in_array(mb_strtolower($name), $targetKeys, true)) {
                $q->run('DELETE FROM post_topics WHERE post_id = :post_id AND topic_id = :topic_id', [
                    'post_id' => $postId,
                    'topic_id' => (int) $topicId,
                ]);
                $q->run('UPDATE topics SET use_count = GREATEST(0, use_count - 1) WHERE id = :id', [
                    'id' => (int) $topicId,
                ]);
            }
        }

        foreach ($target as $name) {
            $slug = Text::slugifyTopic($name);

            $q->run(
                'INSERT INTO topics (name, slug, use_count, created_at) VALUES (:name, :slug, 1, :now)
                 ON DUPLICATE KEY UPDATE use_count = use_count + 1',
                ['name' => $name, 'slug' => $slug, 'now' => $now],
            );

            $topic = $q->one('SELECT id FROM topics WHERE slug = :slug', ['slug' => $slug]);

            if ($topic !== null) {
                $inserted = $q->run(
                    'INSERT IGNORE INTO post_topics (post_id, topic_id) VALUES (:post_id, :topic_id)',
                    ['post_id' => $postId, 'topic_id' => (int) $topic['id']],
                )->rowCount();

                if ($inserted === 0) {
                    // 已存在关联：回滚刚才多加的计数
                    $q->run('UPDATE topics SET use_count = GREATEST(0, use_count - 1) WHERE id = :id', [
                        'id' => (int) $topic['id'],
                    ]);
                }
            }
        }
    }
}
