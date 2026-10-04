<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 阶段四 B：浏览记录（记录去重、页面回看、清空、可见性过滤）。
 */
final class HistoryFlowTest extends HttpTestCase
{
    public function testViewRecordUpsertPageAndClear(): void
    {
        [$cookieA, $csrfA] = $this->registerUser('hs_a');
        [$cookieB] = $this->registerUser('hs_b');

        $content = '浏览记录主题 ' . bin2hex(random_bytes(3));
        $post = $this->postJson('/api/v1/posts', [
            'content' => $content,
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $postId = (int) (json_decode((string) $post->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        $bId = $this->userId($this->usernameOf('hs_b'));

        // 访客浏览不记录
        $this->get('/posts/' . $postId);
        self::assertSame(0, $this->viewCount($postId, $bId));

        // B 浏览两次 → 仅一条记录
        $this->get('/posts/' . $postId, 'text/html', $cookieB);
        $this->get('/posts/' . $postId, 'text/html', $cookieB);
        self::assertSame(1, $this->viewCount($postId, $bId));

        // 时间戳回退后再浏览 → 更新为最新时间
        $this->query()->update(
            'post_views',
            ['viewed_at' => '2020-01-01 00:00:00'],
            'user_id = :u AND post_id = :p',
            ['u' => $bId, 'p' => $postId],
        );
        $this->get('/posts/' . $postId, 'text/html', $cookieB);
        self::assertNotSame(
            '2020-01-01 00:00:00',
            (string) $this->query()->value(
                'SELECT viewed_at FROM post_views WHERE user_id = :u AND post_id = :p',
                ['u' => $bId, 'p' => $postId],
            ),
        );

        // 浏览记录页
        $page = $this->get('/history', 'text/html', $cookieB);
        self::assertSame(200, $page->getStatusCode());
        $body = (string) $page->getBody();
        self::assertStringContainsString('浏览于', $body);
        self::assertStringContainsString($content, $body);

        // 清空记录
        $csrf = $this->csrfToken($body);
        self::assertNotNull($csrf);
        $clear = $this->send('POST', '/history/clear', [
            'form' => ['_csrf' => $csrf],
            'cookie' => $cookieB,
        ]);
        self::assertSame(302, $clear->getStatusCode(), (string) $clear->getBody());
        self::assertSame(0, $this->viewCount($postId, $bId));
        self::assertStringContainsString('还没有浏览记录', (string) $this->get('/history', 'text/html', $cookieB)->getBody());

        // 未登录访问 → 跳登录并带回跳
        $guestHistory = $this->get('/history');
        self::assertSame(302, $guestHistory->getStatusCode());
        self::assertStringContainsString('/login?redirect=%2Fhistory', $guestHistory->getHeaderLine('Location'));
    }

    public function testHistoryFiltersVisibility(): void
    {
        [$cookieA, $csrfA, $nameA] = $this->registerUser('hs2_a');

        // 公开帖：A 浏览自己帖子 → 出现在历史
        $public = $this->postJson('/api/v1/posts', [
            'content' => '公开历史帖',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $publicId = (int) (json_decode((string) $public->getBody(), true)['data']['id'] ?? 0);
        $this->get('/posts/' . $publicId, 'text/html', $cookieA);
        self::assertStringContainsString('公开历史帖', (string) $this->get('/history', 'text/html', $cookieA)->getBody());

        // 自己的私密帖 → 也在自己的历史里
        $private = $this->postJson('/api/v1/posts', [
            'content' => '私密历史帖',
            'visibility' => 'private',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $privateId = (int) (json_decode((string) $private->getBody(), true)['data']['id'] ?? 0);
        $this->get('/posts/' . $privateId, 'text/html', $cookieA);
        self::assertStringContainsString('私密历史帖', (string) $this->get('/history', 'text/html', $cookieA)->getBody());

        // 删除公开帖后 → 历史不再展示
        self::assertSame(200, $this->deleteJson('/api/v1/posts/' . $publicId, $cookieA, $csrfA)->getStatusCode());
        self::assertStringNotContainsString('公开历史帖', (string) $this->get('/history', 'text/html', $cookieA)->getBody());

        // 圈内帖不进入历史：给帖子挂 community_id 后浏览
        $circlePost = $this->postJson('/api/v1/posts', [
            'content' => '圈内历史帖',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $circlePostId = (int) (json_decode((string) $circlePost->getBody(), true)['data']['id'] ?? 0);
        $communityId = $this->query()->insert('communities', [
            'slug' => 'hs-circle-' . bin2hex(random_bytes(3)),
            'name' => '历史测试圈',
            'description' => '',
            'icon' => 'groups',
            'visibility' => 'public',
            'owner_id' => $this->userId($nameA),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->query()->update('posts', ['community_id' => $communityId], 'id = :id', ['id' => $circlePostId]);

        $this->get('/posts/' . $circlePostId, 'text/html', $cookieA);
        self::assertStringNotContainsString('圈内历史帖', (string) $this->get('/history', 'text/html', $cookieA)->getBody());
    }

    private function viewCount(int $postId, int $userId): int
    {
        return (int) $this->query()->value(
            'SELECT COUNT(*) FROM post_views WHERE user_id = :u AND post_id = :p',
            ['u' => $userId, 'p' => $postId],
        );
    }
}
