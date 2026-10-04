<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Support\Text;

/**
 * 信息流组装：为一批动态行批量补充媒体、话题、点赞/转发状态与转发引用卡，
 * 并转换为展示结构。
 */
final class FeedService
{
    public function __construct(private readonly PostRepository $posts)
    {
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

        $postIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $mediaMap = $this->posts->mediaForPosts($postIds);
        $topicMap = $this->posts->topicsForPosts($postIds);
        $likedSet = array_flip($this->posts->likedPostIds($postIds, $viewerId));
        $favoritedSet = array_flip($this->posts->favoritedPostIds($postIds, $viewerId));
        $reposts = $this->posts->myReposts($postIds, $viewerId);

        [$originals, $originMedia] = $this->quoteMaps($rows);

        return array_map(
            fn (array $post): array => PostPresenter::present(
                $post,
                $mediaMap[(int) $post['id']] ?? [],
                $topicMap[(int) $post['id']] ?? [],
                [
                    'viewerId' => $viewerId,
                    'canModerate' => $canModerate,
                    'liked' => isset($likedSet[(int) $post['id']]),
                    'favorited' => isset($favoritedSet[(int) $post['id']]),
                    'reposted' => isset($reposts[(int) $post['id']]),
                    'repost' => $this->buildQuote($post, $originals, $originMedia),
                ],
            ),
            $rows,
        );
    }

    /**
     * 单条动态（含关联数据）。
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function presentOne(array $post, ?int $viewerId, bool $canModerate): array
    {
        $postId = (int) $post['id'];
        $liked = $this->posts->likedPostIds([$postId], $viewerId);
        $favorited = $this->posts->favoritedPostIds([$postId], $viewerId);
        $reposts = $this->posts->myReposts([$postId], $viewerId);

        [$originals, $originMedia] = $this->quoteMaps([$post]);

        return PostPresenter::present(
            $post,
            $this->posts->mediaForPost($postId),
            $this->posts->topicsForPost($postId),
            [
                'viewerId' => $viewerId,
                'canModerate' => $canModerate,
                'liked' => in_array($postId, $liked, true),
                'favorited' => in_array($postId, $favorited, true),
                'reposted' => isset($reposts[$postId]),
                'repost' => $this->buildQuote($post, $originals, $originMedia),
            ],
        );
    }

    /**
     * 批量取转发目标原帖与其媒体（仅计转发行）。
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, list<array<string, mixed>>>}
     */
    private function quoteMaps(array $rows): array
    {
        $targets = [];

        foreach ($rows as $row) {
            if (($row['repost_of_id'] ?? null) !== null) {
                $targets[] = (int) $row['repost_of_id'];
            }
        }

        $originals = $this->posts->repostOriginals(array_values(array_unique($targets)));

        return [$originals, $this->posts->mediaForPosts(array_keys($originals))];
    }

    /**
     * 构建转发引用卡数据（原帖不可见时返回占位）。
     *
     * @param array<string, mixed>                              $post
     * @param array<int, array<string, mixed>>                  $originals
     * @param array<int, list<array<string, mixed>>>            $originMedia
     * @return array<string, mixed>|null
     */
    private function buildQuote(array $post, array $originals, array $originMedia): ?array
    {
        $targetId = ($post['repost_of_id'] ?? null) !== null ? (int) $post['repost_of_id'] : null;

        if ($targetId === null) {
            return null;
        }

        $origin = $originals[$targetId] ?? null;

        if ($origin === null
            || $origin['deleted_at'] !== null
            || (string) $origin['status'] !== 'published'
            || (string) $origin['visibility'] !== 'public'
            || $origin['community_id'] !== null) {
            return [
                'available' => false,
                'id' => $targetId,
                'url' => '/posts/' . $targetId,
                'excerpt' => '原动态已删除或不可见',
                'author' => null,
                'thumb' => '',
            ];
        }

        $thumb = '';

        foreach ($originMedia[$targetId] ?? [] as $m) {
            if ((string) ($m['kind'] ?? 'image') === 'image') {
                $disk = ltrim((string) $m['disk_path'], '/');
                $t = (string) ($m['thumb_path'] ?? '');
                $thumb = '/uploads/' . ($t !== '' ? ltrim($t, '/') : $disk);
                break;
            }
        }

        $nickname = trim((string) ($origin['nickname'] ?? '')) !== ''
            ? (string) $origin['nickname']
            : (string) $origin['username'];

        return [
            'available' => true,
            'id' => $targetId,
            'url' => '/posts/' . $targetId,
            'excerpt' => Text::excerpt((string) $origin['content']),
            'author' => [
                'nickname' => $nickname,
                'url' => '/u/' . (string) $origin['username'],
            ],
            'thumb' => $thumb,
        ];
    }
}
