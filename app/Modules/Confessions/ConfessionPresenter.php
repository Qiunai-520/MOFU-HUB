<?php

declare(strict_types=1);

namespace Mofu\Modules\Confessions;

use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;

/**
 * 表白墙 / 树洞展示转换。
 *
 * 安全约束：绝不输出 creator_id、guest_key_hash 等身份隔离字段。
 */
final class ConfessionPresenter
{
    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $media
     * @return array<string, mixed>
     */
    public static function present(
        array $row,
        array $media,
        ?int $viewerId,
        bool $canModerate,
        bool $liked,
        bool $favorited,
    ): array {
        $board = (string) $row['board'];
        $mode = (string) $row['mode'];
        $creatorId = $row['creator_id'] !== null ? (int) $row['creator_id'] : null;
        $isOwner = $viewerId !== null && $creatorId !== null && $viewerId === $creatorId;
        $createdTs = strtotime((string) $row['created_at']) ?: time();

        // 匿名化名或署名作者（公开字段，不含任何身份标识）
        if ($mode === 'named' && $row['author_username'] !== null && $row['author_username'] !== '') {
            $nickname = trim((string) ($row['author_nickname'] ?? '')) !== ''
                ? (string) $row['author_nickname']
                : (string) $row['author_username'];

            $author = [
                'kind' => 'named',
                'display' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => $creatorId !== null ? Present::avatarHue($creatorId) : 210,
                'url' => '/u/' . (string) $row['author_username'],
            ];
        } else {
            $alias = (string) $row['alias'];

            $author = [
                'kind' => 'anonymous',
                'display' => $alias,
                'initial' => mb_substr($alias, 0, 1),
                'hue' => crc32($alias) % 360,
                'url' => '',
            ];
        }

        return [
            'id' => (int) $row['id'],
            'board' => $board,
            'board_label' => ConfessionService::boardLabel($board),
            'category' => (string) $row['category'],
            'category_label' => ConfessionService::categoryLabel($board, (string) $row['category']),
            'content_html' => Text::renderContent((string) $row['content']),
            'content_text' => (string) $row['content'],
            'excerpt' => Text::excerpt((string) $row['content'], 120),
            'mode' => $mode,
            'author' => $author,
            'is_owner' => $isOwner,
            'can_delete' => $viewerId !== null && ($isOwner || $canModerate),
            'status' => (string) $row['status'],
            'status_label' => self::statusLabel((string) $row['status']),
            'is_featured' => (int) $row['is_featured'] === 1,
            'like_count' => (int) $row['like_count'],
            'comment_count' => (int) $row['comment_count'],
            'favorite_count' => (int) $row['favorite_count'],
            'liked' => $liked,
            'favorited' => $favorited,
            'media' => array_map(static function (array $m): array {
                $disk = ltrim((string) $m['disk_path'], '/');
                $thumb = (string) ($m['thumb_path'] ?? '');

                return [
                    'id' => (int) $m['id'],
                    'url' => '/uploads/' . $disk,
                    'thumb' => $thumb !== '' ? '/uploads/' . ltrim($thumb, '/') : '/uploads/' . $disk,
                ];
            }, $media),
            'time_ago' => Text::relativeTime((string) $row['created_at']),
            'created_at' => date('c', $createdTs),
            'url' => ConfessionService::boardUrl($board) . '/' . (int) $row['id'],
        ];
    }

    /** @param array<string, mixed> $row */
    public static function presentComment(array $row, ?int $viewerId, bool $canModerate, bool $liked): array
    {
        $alias = (string) $row['alias'];
        $isOwner = $viewerId !== null && $row['user_id'] !== null && (int) $row['user_id'] === $viewerId;

        return [
            'id' => (int) $row['id'],
            'content_html' => Text::renderContent((string) $row['content']),
            'content_text' => (string) $row['content'],
            'author' => [
                'kind' => 'anonymous',
                'display' => $alias,
                'initial' => mb_substr($alias, 0, 1),
                'hue' => crc32($alias) % 360,
            ],
            'reply_to' => null,
            'time_ago' => Text::relativeTime((string) $row['created_at']),
            'created_at' => date('c', strtotime((string) $row['created_at']) ?: time()),
            'like_count' => (int) $row['like_count'],
            'liked' => $liked,
            'is_owner' => $isOwner,
            'can_remove' => $viewerId !== null && ($isOwner || $canModerate),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '审核中',
            'approved' => '',
            'rejected' => '未通过审核',
            'hidden' => '已下架',
            'removed' => '已删除',
            default => '',
        };
    }
}
