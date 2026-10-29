<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 编辑动态时增删图片：带 media_ids 时整体替换，不带时保留原媒体。
 */
final class PostMediaEditTest extends HttpTestCase
{
    public function testEditReplacesOrKeepsMedia(): void
    {
        [$cookie, $csrf, $name] = $this->registerUser('media_edit');
        $userId = $this->userId($name);

        $mediaA = $this->makeMedia($userId);
        $mediaB = $this->makeMedia($userId);

        $create = $this->postJson('/api/v1/posts', [
            'content' => '带图动态',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [$mediaA],
        ], $cookie, $csrf);
        self::assertSame(201, $create->getStatusCode(), (string) $create->getBody());
        $postId = (int) (json_decode((string) $create->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        self::assertSame([$mediaA], $this->mediaIdsOf($postId));

        // 带 media_ids：整体替换为 B
        $replace = $this->send('PATCH', '/api/v1/posts/' . $postId, [
            'accept' => 'application/json',
            'json' => ['content' => '带图动态', 'visibility' => 'public', 'topics' => [], 'media_ids' => [$mediaB]],
            'cookie' => $cookie,
            'csrf' => $csrf,
        ]);
        self::assertSame(200, $replace->getStatusCode(), (string) $replace->getBody());
        self::assertSame([$mediaB], $this->mediaIdsOf($postId));

        // 不带 media_ids：保留现有媒体
        $keep = $this->send('PATCH', '/api/v1/posts/' . $postId, [
            'accept' => 'application/json',
            'json' => ['content' => '只改文字', 'visibility' => 'public', 'topics' => []],
            'cookie' => $cookie,
            'csrf' => $csrf,
        ]);
        self::assertSame(200, $keep->getStatusCode(), (string) $keep->getBody());
        self::assertSame([$mediaB], $this->mediaIdsOf($postId));

        // 清空媒体（同时保留文字，避免「没内容也没图」）
        $clear = $this->send('PATCH', '/api/v1/posts/' . $postId, [
            'accept' => 'application/json',
            'json' => ['content' => '只留文字', 'visibility' => 'public', 'topics' => [], 'media_ids' => []],
            'cookie' => $cookie,
            'csrf' => $csrf,
        ]);
        self::assertSame(200, $clear->getStatusCode(), (string) $clear->getBody());
        self::assertSame([], $this->mediaIdsOf($postId));
    }

    private function makeMedia(int $userId): int
    {
        return $this->query()->insert('media', [
            'user_id' => $userId,
            'kind' => 'image',
            'disk_path' => '2026/10/' . bin2hex(random_bytes(8)) . '.webp',
            'thumb_path' => '',
            'original_name' => 'test.png',
            'mime_type' => 'image/webp',
            'size_bytes' => 100,
            'width' => 100,
            'height' => 100,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<int> */
    private function mediaIdsOf(int $postId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['media_id'],
            $this->query()->all('SELECT media_id FROM post_media WHERE post_id = :id ORDER BY sort_order ASC', ['id' => $postId]),
        );
    }
}
