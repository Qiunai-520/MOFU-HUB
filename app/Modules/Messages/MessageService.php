<?php

declare(strict_types=1);

namespace Mofu\Modules\Messages;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;
use Mofu\Modules\Settings\PreferenceService;
use Mofu\Modules\Users\BlockService;

/**
 * 一对一私信：会话、消息、未读与发送策略。
 * 消息通过轮询获取（非实时推送），由前端模块按固定间隔拉取。
 */
final class MessageService
{
    public const MESSAGE_MAX = 1000;

    public function __construct(
        private readonly Query $query,
        private readonly BlockService $blocks,
        private readonly PreferenceService $preferences,
    ) {
    }

    /**
     * 发送校验：拉黑关系与接收方私信策略。
     *
     * @return array{allowed: bool, reason: string}
     */
    public function canMessage(int $senderId, int $recipientId): array
    {
        if ($senderId === $recipientId) {
            return ['allowed' => false, 'reason' => '不能给自己发私信'];
        }

        if ($this->blocks->isBlockedEither($senderId, $recipientId)) {
            return ['allowed' => false, 'reason' => '你们之间存在拉黑关系，暂时无法私信'];
        }

        $policy = $this->preferences->privacyPrefs($recipientId)['dm_policy'];

        if ($policy === 'none') {
            return ['allowed' => false, 'reason' => '对方暂不接收私信'];
        }

        if ($policy === 'mutual') {
            $senderFollows = $this->query->value(
                'SELECT 1 FROM follows WHERE follower_id = :sender AND followee_id = :recipient',
                ['sender' => $senderId, 'recipient' => $recipientId],
            ) !== null;
            $recipientFollows = $this->query->value(
                'SELECT 1 FROM follows WHERE follower_id = :sender2 AND followee_id = :recipient2',
                ['sender2' => $recipientId, 'recipient2' => $senderId],
            ) !== null;

            if (!$senderFollows || !$recipientFollows) {
                return ['allowed' => false, 'reason' => '对方仅接收互相关注者的私信'];
            }
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * 查找或创建两人会话（user_a_id < user_b_id 归一化）。
     *
     * @return array<string, mixed>
     */
    public function findOrCreate(int $viewerId, int $otherId): array
    {
        [$a, $b] = $viewerId < $otherId ? [$viewerId, $otherId] : [$otherId, $viewerId];

        $conversation = $this->findByPair($a, $b);

        if ($conversation !== null) {
            return $conversation;
        }

        try {
            $this->query->insert('conversations', [
                'user_a_id' => $a,
                'user_b_id' => $b,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }

        return $this->findByPair($a, $b) ?? [];
    }

    /**
     * 会话（带参与者校验）。
     *
     * @return array<string, mixed>|null
     */
    public function find(int $conversationId, int $viewerId): ?array
    {
        $row = $this->query->one('SELECT * FROM conversations WHERE id = :id', ['id' => $conversationId]);

        if ($row === null) {
            return null;
        }

        $a = (int) $row['user_a_id'];
        $b = (int) $row['user_b_id'];

        if ($viewerId !== $a && $viewerId !== $b) {
            return null;
        }

        return $row;
    }

    /** @param array<string, mixed> $conversation */
    public function otherUserId(array $conversation, int $viewerId): int
    {
        $a = (int) $conversation['user_a_id'];
        $b = (int) $conversation['user_b_id'];

        return $viewerId === $a ? $b : $a;
    }

    /**
     * 发送消息。
     *
     * @return array<string, mixed> 新消息行
     */
    public function send(int $senderId, int $conversationId, string $content): array
    {
        $conversation = $this->find($conversationId, $senderId);

        if ($conversation === null) {
            throw HttpException::notFound('会话不存在');
        }

        $recipientId = $this->otherUserId($conversation, $senderId);
        $check = $this->canMessage($senderId, $recipientId);

        if (!$check['allowed']) {
            throw HttpException::forbidden($check['reason']);
        }

        $content = trim($content);

        if ($content === '') {
            throw HttpException::badRequest('写点内容再发送吧');
        }

        if (mb_strlen($content) > self::MESSAGE_MAX) {
            throw HttpException::badRequest('单条消息最多 ' . self::MESSAGE_MAX . ' 字');
        }

        $now = date('Y-m-d H:i:s');

        $messageId = $this->query->transaction(function (Query $q) use ($conversationId, $senderId, $content, $now): int {
            $id = $q->insert('messages', [
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'content' => $content,
                'created_at' => $now,
            ]);

            $q->run(
                'UPDATE conversations SET last_message_id = :message_id, last_message_at = :now WHERE id = :id',
                ['message_id' => $id, 'now' => $now, 'id' => $conversationId],
            );

            return $id;
        });

        return $this->query->one('SELECT * FROM messages WHERE id = :id', ['id' => $messageId]) ?? [];
    }

    public function markRead(int $userId, int $conversationId): int
    {
        if ($this->find($conversationId, $userId) === null) {
            throw HttpException::notFound('会话不存在');
        }

        return $this->query->run(
            'UPDATE messages SET read_at = :now
             WHERE conversation_id = :conversation AND sender_id != :user AND read_at IS NULL',
            ['now' => date('Y-m-d H:i:s'), 'conversation' => $conversationId, 'user' => $userId],
        )->rowCount();
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->query->value(
            'SELECT COUNT(*) FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE (c.user_a_id = :user_a OR c.user_b_id = :user_b)
               AND m.sender_id != :user_sender AND m.read_at IS NULL',
            ['user_a' => $userId, 'user_b' => $userId, 'user_sender' => $userId],
        );
    }

    /**
     * 会话列表（仅有消息的会话）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listConversations(int $userId, int $page, int $perPage = 20): array
    {
        $total = (int) $this->query->value(
            'SELECT COUNT(*) FROM conversations c
             WHERE (c.user_a_id = :user_a OR c.user_b_id = :user_b) AND c.last_message_id IS NOT NULL',
            ['user_a' => $userId, 'user_b' => $userId],
        );

        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        $rows = $total === 0 ? [] : $this->query->all(
            'SELECT c.*,
                    CASE WHEN c.user_a_id = :user_viewer THEN c.user_b_id ELSE c.user_a_id END AS other_id,
                    m.content AS last_content, m.created_at AS last_message_time, m.sender_id AS last_sender_id,
                    (SELECT COUNT(*) FROM messages mu
                      WHERE mu.conversation_id = c.id AND mu.sender_id != :user_unread AND mu.read_at IS NULL) AS unread
             FROM conversations c
             LEFT JOIN messages m ON m.id = c.last_message_id
             WHERE (c.user_a_id = :user_a2 OR c.user_b_id = :user_b2) AND c.last_message_id IS NOT NULL
             ORDER BY c.last_message_at DESC, c.id DESC
             LIMIT ' . max(1, min($perPage, 50)) . ' OFFSET ' . $offset,
            ['user_viewer' => $userId, 'user_unread' => $userId, 'user_a2' => $userId, 'user_b2' => $userId],
        );

        return [
            'items' => $this->presentConversations($rows, $userId),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * 会话消息（默认返回最新一批，支持 after_id 轮询与 before_id 回溯）。
     *
     * @return list<array<string, mixed>>
     */
    public function listMessages(int $conversationId, int $viewerId, ?int $afterId, ?int $beforeId, int $limit = 30): array
    {
        if ($this->find($conversationId, $viewerId) === null) {
            throw HttpException::notFound('会话不存在');
        }

        $limit = max(1, min($limit, 100));

        if ($afterId !== null && $afterId > 0) {
            $rows = $this->query->all(
                'SELECT * FROM messages WHERE conversation_id = :conversation AND id > :after ORDER BY id ASC LIMIT ' . $limit,
                ['conversation' => $conversationId, 'after' => $afterId],
            );

            return $this->presentMessages($rows, $viewerId);
        }

        if ($beforeId !== null && $beforeId > 0) {
            $rows = $this->query->all(
                'SELECT * FROM messages WHERE conversation_id = :conversation AND id < :before ORDER BY id DESC LIMIT ' . $limit,
                ['conversation' => $conversationId, 'before' => $beforeId],
            );

            return $this->presentMessages(array_reverse($rows), $viewerId);
        }

        $rows = $this->query->all(
            'SELECT * FROM messages WHERE conversation_id = :conversation ORDER BY id DESC LIMIT ' . $limit,
            ['conversation' => $conversationId],
        );

        return $this->presentMessages(array_reverse($rows), $viewerId);
    }

    /**
     * 会话行的展示结构。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentConversations(array $rows, int $viewerId): array
    {
        if ($rows === []) {
            return [];
        }

        $otherIds = array_map(static fn (array $row): int => (int) $row['other_id'], $rows);
        $users = $this->usersByIds($otherIds);

        return array_map(function (array $row) use ($viewerId, $users): array {
            $otherId = (int) $row['other_id'];

            return [
                'id' => (int) $row['id'],
                'other' => $users[$otherId] ?? null,
                'last_content' => Text::excerpt((string) ($row['last_content'] ?? ''), 80),
                'last_mine' => (int) ($row['last_sender_id'] ?? 0) === $viewerId,
                'time' => self::messageTime((string) ($row['last_message_time'] ?? $row['created_at'])),
                'unread' => (int) $row['unread'],
            ];
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function presentMessages(array $rows, int $viewerId): array
    {
        return array_map(static function (array $row) use ($viewerId): array {
            return [
                'id' => (int) $row['id'],
                'mine' => (int) $row['sender_id'] === $viewerId,
                'content' => (string) $row['content'],
                'read' => $row['read_at'] !== null,
                'time' => self::messageTime((string) $row['created_at']),
                'created_at' => date('c', strtotime((string) $row['created_at']) ?: time()),
            ];
        }, $rows);
    }

    /**
     * 单条消息展示（用于发送接口响应）。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function presentMessage(array $row, int $viewerId): array
    {
        $presented = $this->presentMessages([$row], $viewerId);

        return $presented[0] ?? [];
    }

    public static function messageTime(string $datetime): string
    {
        $ts = strtotime($datetime) ?: time();

        return date('Y-m-d', $ts) === date('Y-m-d') ? date('H:i', $ts) : date('m-d H:i', $ts);
    }

    /** @return array<string, mixed>|null */
    private function findByPair(int $a, int $b): ?array
    {
        return $this->query->one(
            'SELECT * FROM conversations WHERE user_a_id = :a AND user_b_id = :b',
            ['a' => $a, 'b' => $b],
        );
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id => 用户展示结构
     */
    private function usersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach (array_values(array_unique($ids)) as $i => $id) {
            $placeholders[] = ':u_' . $i;
            $params['u_' . $i] = (int) $id;
        }

        $rows = $this->query->all(
            sprintf(
                'SELECT u.id, u.username, p.nickname, p.bio,
                        am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                 FROM users u
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 LEFT JOIN media am ON am.id = p.avatar_media_id
                 WHERE u.id IN (%s)',
                implode(', ', $placeholders),
            ),
            $params,
        );

        $map = [];

        foreach ($rows as $row) {
            $nickname = trim((string) ($row['nickname'] ?? '')) !== '' ? (string) $row['nickname'] : (string) $row['username'];
            $map[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue((int) $row['id']),
                'url' => '/u/' . (string) $row['username'],
                'avatar_url' => Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                ),
            ];
        }

        return $map;
    }
}
