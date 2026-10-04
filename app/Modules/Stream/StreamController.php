<?php

declare(strict_types=1);

namespace Mofu\Modules\Stream;

use Mofu\Core\Http\Ctx;
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

        while ((time() - $started) < self::MAX_SECONDS) {
            usleep(self::POLL_SECONDS * 1_000_000);

            if (connection_aborted() !== 0) {
                break;
            }

            $n = $this->notifications->unreadCount($userId);
            $m = $this->messages->unreadCount($userId);

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
}
