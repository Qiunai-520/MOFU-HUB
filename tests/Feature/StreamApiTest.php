<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 阶段四 B：SSE 实时通知接口（once 快照与鉴权）。
 */
final class StreamApiTest extends HttpTestCase
{
    public function testOnceSnapshotAndGuards(): void
    {
        // 未登录 → 401
        self::assertSame(401, $this->get('/api/v1/stream', 'application/json')->getStatusCode());

        [$cookieA, , $nameA] = $this->registerUser('st_a');

        $response = $this->get('/api/v1/stream?once=1', 'text/event-stream', $cookieA);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('retry: 3000', $body);
        self::assertStringContainsString('event: unread', $body);
        self::assertStringContainsString('"notifications":0', $body);
        self::assertStringContainsString('"messages":0', $body);

        // 产生一条通知后 → 快照计数变化
        $this->query()->insert('notifications', [
            'user_id' => $this->userId($nameA),
            'actor_id' => null,
            'type' => 'system',
            'target_type' => '',
            'target_id' => null,
            'data' => json_encode(['message' => '测试通知']),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $again = (string) $this->get('/api/v1/stream?once=1', 'text/event-stream', $cookieA)->getBody();
        self::assertStringContainsString('"notifications":1', $again);
    }
}
