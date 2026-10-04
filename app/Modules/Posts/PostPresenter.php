<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;

/**
 * 将动态数据转换为模板/JSON 展示结构。
 */
final class PostPresenter
{
    /**
     * @param array<string, mixed>       $post
     * @param list<array<string, mixed>> $media
     * @param list<array<string, mixed>> $topics
     * @param array{liked?: bool, favorited?: bool, reposted?: bool, repost?: array<string, mixed>|null, viewerId?: int|null, canModerate?: bool} $options
     * @return array<string, mixed>
     */
    public static function present(array $post, array $media = [], array $topics = [], array $options = []): array
    {
        $viewerId = $options['viewerId'] ?? null;
        $canModerate = $options['canModerate'] ?? false;
        $liked = $options['liked'] ?? false;
        $favorited = $options['favorited'] ?? false;
        $isRepost = ($post['repost_of_id'] ?? null) !== null;

        $authorId = (int) $post['user_id'];
        $nickname = trim((string) ($post['nickname'] ?? '')) !== ''
            ? (string) $post['nickname']
            : (string) $post['username'];

        $createdTs = strtotime((string) $post['created_at']) ?: time();

        return [
            'id' => (int) $post['id'],
            'content_html' => Text::renderContent((string) $post['content']),
            'content_text' => (string) $post['content'],
            'excerpt' => Text::excerpt((string) $post['content']),
            'visibility' => (string) $post['visibility'],
            'visibility_label' => (string) $post['visibility'] === 'private' ? '仅自己可见' : '公开',
            'status' => (string) $post['status'],
            'is_pinned' => (int) ($post['is_pinned'] ?? 0) === 1,
            'is_owner' => $viewerId !== null && $viewerId === $authorId,
            'can_edit' => !$isRepost && $viewerId !== null && ($viewerId === $authorId || $canModerate),
            'author' => [
                'id' => $authorId,
                'username' => (string) $post['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($authorId),
                'url' => '/u/' . (string) $post['username'],
                'avatar_url' => Present::uploadUrl(
                    isset($post['avatar_thumb']) ? (string) $post['avatar_thumb'] : null,
                    isset($post['avatar_path']) ? (string) $post['avatar_path'] : null,
                ),
            ],
            'time_ago' => Text::relativeTime((string) $post['created_at']),
            'created_at' => date('c', $createdTs),
            'like_count' => (int) $post['like_count'],
            'comment_count' => (int) $post['comment_count'],
            'favorite_count' => (int) ($post['favorite_count'] ?? 0),
            'repost_count' => (int) ($post['repost_count'] ?? 0),
            'liked' => (bool) $liked,
            'favorited' => (bool) $favorited,
            'reposted' => (bool) ($options['reposted'] ?? false),
            'is_repost' => $isRepost,
            'repost' => $options['repost'] ?? null,
            'media' => array_map(static function (array $m): array {
                $disk = ltrim((string) $m['disk_path'], '/');
                $thumb = (string) ($m['thumb_path'] ?? '');

                return [
                    'id' => (int) $m['id'],
                    'kind' => (string) ($m['kind'] ?? 'image'),
                    'url' => '/uploads/' . $disk,
                    'thumb' => $thumb !== '' ? '/uploads/' . ltrim($thumb, '/') : '/uploads/' . $disk,
                    'width' => $m['width'] !== null ? (int) $m['width'] : null,
                    'height' => $m['height'] !== null ? (int) $m['height'] : null,
                ];
            }, $media),
            'topics' => array_map(static fn (array $t): array => [
                'name' => (string) $t['name'],
                'slug' => (string) $t['slug'],
                'url' => '/topics/' . rawurlencode((string) $t['slug']),
            ], $topics),
            'community' => ($post['community_id'] ?? null) !== null && isset($post['community_slug'])
                ? [
                    'id' => (int) $post['community_id'],
                    'name' => (string) ($post['community_name'] ?? ''),
                    'url' => '/circles/' . rawurlencode((string) $post['community_slug']),
                ]
                : null,
        ];
    }
}
