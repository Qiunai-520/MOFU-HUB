<?php

declare(strict_types=1);

namespace Mofu\Modules\Stream;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Database\Query;
use Mofu\Core\Security\Guard;
use Mofu\Modules\Messages\MessageService;
use Mofu\Modules\Notifications\NotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * 实时通知（SSE）：推送未读计数（通知 + 私信）。
 *
 * - `?once=1`：返回单次快照后结束（测试与能力探测用）；
 * - 常规模式：最多保持 25 秒，每 2 秒检查一次未读变化，变化时推送 `unread` 事件，
 *   15 秒心跳；客户端 EventSource 断线后自动重连（retry: 3000）。
 *
 * 说明：长连接阶段直接使用原生输出——Slim 的 ResponseEmitter 检测到
 * headers_sent() 为真时会跳过响应头与空响应体，不会重复发头。
 */
final class StreamController
{
    private const POLL_SECONDS = 2;
    private const HEARTBEAT_SECONDS = 15;
    private const MAX_SECONDS = 25;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly MessageService $messages,
        private readonly Query $query,
    ) {
    }

    public function stream(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];
        $once = (string) ($request->getQueryParams()['once'] ?? '') === '1';

        $notifications = $this->notifications->unreadCount($userId);
        $messages = $this->messages->unreadCount($userId);

        if ($once) {
            $response = $response
                ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
                ->withHeader('Cache-Control', 'no-cache')
                ->withHeader('X-Accel-Buffering', 'no');

            $response->getBody()->write(
                "retry: 3000\n\n"
                . self::snapshot($notifications, $messages),
            );

            return $response;
        }

        // 长连接：禁用缓冲，直接流式输出
        ignore_user_abort(false);

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        echo "retry: 3000\n\n";
        echo self::snapshot($notifications, $messages);
        flush();

        $started = time();
        $lastHeartbeat = time();
        $lastN = $notifications;
        $lastM = $messages;
        $lastMessageId = $this->messages->maxVisibleMessageId($userId);
        $lastPostId = (int) $this->query->value(
            "SELECT COALESCE(MAX(id), 0) FROM posts
             WHERE status = 'published' AND visibility = 'public'
               AND community_id IS NULL AND deleted_at IS NULL",
        );

        while ((time() - $started) < self::MAX_SECONDS) {
            usleep(self::POLL_SECONDS * 1_000_000);

            if (connection_aborted() !== 0) {
                break;
            }

            $n = $this->notifications->unreadCount($userId);
            $m = $this->messages->unreadCount($userId);

            // 私信正文：增量推送（客户端据此直接追加气泡，无需再等轮询）
            $incoming = $this->messages->incomingSince($userId, $lastMessageId);

            if ($incoming !== []) {
                $lastMessageId = max(array_map(static fn (array $row): int => (int) $row['id'], $incoming));
                echo self::messageEvent($incoming);
                flush();
                $lastHeartbeat = time();
            }

            // 全站新动态：只推 id，客户端按需拉取完整卡片，避免长连接携带大 JSON。
            $newPosts = $this->query->all(
                "SELECT id FROM posts
                 WHERE id > :last_id AND status = 'published' AND visibility = 'public'
                   AND community_id IS NULL AND deleted_at IS NULL
                 ORDER BY id ASC LIMIT 8",
                ['last_id' => $lastPostId],
            );

            if ($newPosts !== []) {
                $ids = array_map(static fn (array $row): int => (int) $row['id'], $newPosts);
                $lastPostId = max($ids);
                echo self::postEvent($ids);
                flush();
                $lastHeartbeat = time();
            }

            if ($n !== $lastN || $m !== $lastM) {
                echo self::snapshot($n, $m);
                flush();
                $lastN = $n;
                $lastM = $m;
                $lastHeartbeat = time();

                continue;
            }

            if ((time() - $lastHeartbeat) >= self::HEARTBEAT_SECONDS) {
                echo ": ping\n\n";
                flush();
                $lastHeartbeat = time();
            }
        }

        return $response
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache');
    }

    private static function snapshot(int $notifications, int $messages): string
    {
        return "event: unread\n"
            . 'data: ' . json_encode(['notifications' => $notifications, 'messages' => $messages]) . "\n\n";
    }

    /** @param list<array<string, mixed>> $messages */
    private static function messageEvent(array $messages): string
    {
        return "event: dm\n"
            . 'data: ' . json_encode(['messages' => $messages]) . "\n\n";
    }

    /** @param list<int> $ids */
    private static function postEvent(array $ids): string
    {
        return "event: post\n"
            . 'data: ' . json_encode(['ids' => $ids]) . "\n\n";
    }
}
