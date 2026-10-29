<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;
use Mofu\Modules\Users\ProfileDecorations;

/**
 * 将动态数据转换为模板/JSON 展示结构。
 */
final class PostPresenter
{
    /**
     * @param array<string, mixed>       $post
     * @param list<array<string, mixed>> $media
     * @param list<array<string, mixed>> $topics
     * @param array{liked?: bool, favorited?: bool, reposted?: bool, repost?: array<string, mixed>|null, viewerId?: int|null, canModerate?: bool, purchased?: bool, tags?: list<array<string, mixed>>} $options
     * @return array<string, mixed>
     */
    public static function present(array $post, array $media = [], array $topics = [], array $options = []): array
    {
        $viewerId = $options['viewerId'] ?? null;
        $canModerate = $options['canModerate'] ?? false;
        $liked = $options['liked'] ?? false;
        $favorited = $options['favorited'] ?? false;
        $isRepost = ($post['repost_of_id'] ?? null) !== null;
        /** @var array{level: int, is_formal: bool, is_flash?: bool, is_premium?: bool, title: array{name: string, icon: string}|null}|null $badge */
        $badge = $options['authorBadge'] ?? null;
        $detailTitle = isset($post['author_title_name']) && $post['author_title_name'] !== null
            ? ['name' => (string) $post['author_title_name'], 'icon' => (string) ($post['author_title_icon'] ?? 'military_tech')]
            : null;

        $authorId = (int) $post['user_id'];
        $isPaid = (int) ($post['is_paid'] ?? 0) === 1;
        $price = $isPaid ? (float) ($post['price'] ?? 0) : 0.0;
        // 未购买者只看摘要：作者本人与已购买者才能看到正文与媒体（管理员也需购买）
        $unlocked = !$isPaid
            || ($viewerId !== null && $viewerId === $authorId)
            || ($options['purchased'] ?? false) === true;
        $nickname = trim((string) ($post['nickname'] ?? '')) !== ''
            ? (string) $post['nickname']
            : (string) $post['username'];

        $createdTs = strtotime((string) $post['created_at']) ?: time();

        return [
            'id' => (int) $post['id'],
            'content_html' => $unlocked ? Text::renderContent((string) $post['content']) : '',
            'content_text' => $unlocked ? (string) $post['content'] : '',
            'excerpt' => Text::excerpt((string) $post['content']),
            'is_paid' => $isPaid,
            'price' => $price,
            'unlocked' => $unlocked,
            'visibility' => (string) $post['visibility'],
            'visibility_label' => (string) $post['visibility'] === 'private' ? '仅自己可见' : '公开',
            'status' => (string) $post['status'],
            'is_pinned' => (int) ($post['is_pinned'] ?? 0) === 1,
            'is_owner' => $viewerId !== null && $viewerId === $authorId,
            'can_edit' => !$isRepost && $viewerId !== null && ($viewerId === $authorId || $canModerate),
            // 转发帖不能编辑，但作者（或管理员）可以删掉自己的转发
            'can_delete' => $viewerId !== null && ($viewerId === $authorId || $canModerate),
            'author' => [
                'id' => $authorId,
                'username' => (string) $post['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($authorId),
                'url' => '/u/' . (string) $post['username'],
                'level' => $badge !== null ? (int) $badge['level'] : (isset($post['author_level']) ? (int) $post['author_level'] : null),
                'is_formal' => $badge !== null ? (bool) $badge['is_formal'] : (bool) ($post['author_is_formal'] ?? false),
                'is_flash' => $badge !== null
                    ? (bool) ($badge['is_flash'] ?? false)
                    : (bool) ($post['author_is_flash'] ?? false),
                'is_premium' => $badge !== null
                    ? (bool) ($badge['is_premium'] ?? false)
                    : (bool) ($post['author_is_premium'] ?? false),
                'title' => $badge !== null ? $badge['title'] : $detailTitle,
                'avatar_url' => Present::uploadUrl(
                    isset($post['avatar_thumb']) ? (string) $post['avatar_thumb'] : null,
                    isset($post['avatar_path']) ? (string) $post['avatar_path'] : null,
                ),
                ...ProfileDecorations::present($post),
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
            'media' => $unlocked ? array_map(static function (array $m): array {
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
            }, $media) : [],
            'topics' => array_map(static fn (array $t): array => [
                'name' => (string) $t['name'],
                'slug' => (string) $t['slug'],
                'url' => '/topics/' . rawurlencode((string) $t['slug']),
            ], $topics),
            'tags' => $options['tags'] ?? [],
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
