<?php

declare(strict_types=1);

namespace Mofu\Modules\Comments;

use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;
use Mofu\Modules\Users\ProfileDecorations;

final class CommentPresenter
{
    /** @param array<string, mixed> $comment */
    public static function present(array $comment, ?int $viewerId, bool $canModerate, bool $liked): array
    {
        $authorId = (int) $comment['user_id'];
        $nickname = trim((string) ($comment['nickname'] ?? '')) !== ''
            ? (string) $comment['nickname']
            : (string) $comment['username'];

        $replyTo = null;
        if (($comment['reply_to_username'] ?? null) !== null) {
            $replyNickname = trim((string) ($comment['reply_to_nickname'] ?? '')) !== ''
                ? (string) $comment['reply_to_nickname']
                : (string) $comment['reply_to_username'];
            $replyTo = [
                'username' => (string) $comment['reply_to_username'],
                'nickname' => $replyNickname,
            ];
        }

        return [
            'id' => (int) $comment['id'],
            'content_html' => Text::renderContent((string) $comment['content']),
            'content_text' => (string) $comment['content'],
            'author' => [
                'id' => $authorId,
                'username' => (string) $comment['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($authorId),
                'url' => '/u/' . (string) $comment['username'],
                'avatar_url' => Present::uploadUrl(
                    isset($comment['avatar_thumb']) ? (string) $comment['avatar_thumb'] : null,
                    isset($comment['avatar_path']) ? (string) $comment['avatar_path'] : null,
                ),
                ...ProfileDecorations::present($comment),
            ],
            'reply_to' => $replyTo,
            'time_ago' => Text::relativeTime((string) $comment['created_at']),
            'created_at' => date('c', strtotime((string) $comment['created_at']) ?: time()),
            'like_count' => (int) $comment['like_count'],
            'liked' => $liked,
            'is_owner' => $viewerId !== null && $viewerId === $authorId,
            'can_remove' => $viewerId !== null && ($viewerId === $authorId || $canModerate),
        ];
    }
}
