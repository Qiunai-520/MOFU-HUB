<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 阶段四 B：快捷转发（计数、通知、引用卡、取消、各类拒绝条件）。
 */
final class RepostFlowTest extends HttpTestCase
{
    public function testRepostFlowNotifyQuoteAndUndo(): void
    {
        [$cookieA, $csrfA, $nameA] = $this->registerUser('rp_a');
        [$cookieB, $csrfB] = $this->registerUser('rp_b');

        $content = '被转发的原帖主题 ' . bin2hex(random_bytes(3));
        $post = $this->postJson('/api/v1/posts', [
            'content' => $content,
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        self::assertSame(201, $post->getStatusCode(), (string) $post->getBody());
        $postId = (int) (json_decode((string) $post->getBody(), true)['data']['id'] ?? 0);
        self::assertGreaterThan(0, $postId);

        // B 转发
        $repost = $this->postJson('/api/v1/posts/' . $postId . '/repost', [], $cookieB, $csrfB);
        self::assertSame(201, $repost->getStatusCode(), (string) $repost->getBody());
        $data = json_decode((string) $repost->getBody(), true)['data'] ?? [];
        self::assertTrue((bool) ($data['active'] ?? false));
        self::assertSame(1, (int) ($data['repost_count'] ?? 0));
        $repostId = (int) ($data['repost_id'] ?? 0);
        self::assertGreaterThan(0, $repostId);

        // 转发行 type=repost 且指向原帖
        $row = $this->query()->one(
            'SELECT type, repost_of_id, user_id FROM posts WHERE id = :id',
            ['id' => $repostId],
        );
        self::assertSame('repost', (string) $row['type']);
        self::assertSame($postId, (int) $row['repost_of_id']);

        // 原作者收到通知
        self::assertGreaterThanOrEqual(1, (int) $this->query()->value(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :id AND type = 'post_reposted'",
            ['id' => $this->userId($nameA)],
        ));

        // 详情页：转发计数、我的转发态、引用卡
        $detailB = (string) $this->get('/posts/' . $postId, 'text/html', $cookieB)->getBody();
        self::assertStringContainsString('toggle-repost', $detailB);
        self::assertStringContainsString('data-repost-count>1', $detailB);
        self::assertStringContainsString('is-active', $detailB);

        // 原作者看自己帖子：无转发按钮
        $detailA = (string) $this->get('/posts/' . $postId, 'text/html', $cookieA)->getBody();
        self::assertStringNotContainsString('toggle-repost', $detailA);

        // B 的首页流出现引用卡
        $feed = (string) $this->get('/', 'text/html', $cookieB)->getBody();
        self::assertStringContainsString('repost-quote', $feed);
        self::assertStringContainsString($content, $feed);

        // 重复转发被拒
        $again = $this->postJson('/api/v1/posts/' . $postId . '/repost', [], $cookieB, $csrfB);
        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('已转发', (string) $again->getBody());

        // 不能转发自己的动态
        $self = $this->postJson('/api/v1/posts/' . $postId . '/repost', [], $cookieA, $csrfA);
        self::assertSame(422, $self->getStatusCode());
        self::assertStringContainsString('自己', (string) $self->getBody());

        // 转发的动态不能再转发（对转发行转发）
        $nested = $this->postJson('/api/v1/posts/' . $repostId . '/repost', [], $cookieA, $csrfA);
        self::assertSame(422, $nested->getStatusCode());
        self::assertStringContainsString('不能再转发', (string) $nested->getBody());

        // 取消转发 → 计数回退；随后可再次转发
        $undo = $this->deleteJson('/api/v1/posts/' . $postId . '/repost', $cookieB, $csrfB);
        self::assertSame(200, $undo->getStatusCode(), (string) $undo->getBody());
        $undoData = json_decode((string) $undo->getBody(), true)['data'] ?? [];
        self::assertFalse((bool) ($undoData['active'] ?? true));
        self::assertSame(0, (int) ($undoData['repost_count'] ?? -1));

        // 再次取消 → 422
        self::assertSame(422, $this->deleteJson('/api/v1/posts/' . $postId . '/repost', $cookieB, $csrfB)->getStatusCode());

        // 再次转发成功，计数回到 1
        $redo = $this->postJson('/api/v1/posts/' . $postId . '/repost', [], $cookieB, $csrfB);
        self::assertSame(201, $redo->getStatusCode(), (string) $redo->getBody());
        self::assertSame(1, (int) (json_decode((string) $redo->getBody(), true)['data']['repost_count'] ?? 0));
    }

    public function testRepostRejectsPrivateAndCirclePostsAndDeletedQuote(): void
    {
        [$cookieA, $csrfA, $nameA] = $this->registerUser('rp2_a');
        [$cookieB, $csrfB] = $this->registerUser('rp2_b');

        // 私密动态不能转发
        $private = $this->postJson('/api/v1/posts', [
            'content' => '私密内容',
            'visibility' => 'private',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $privateId = (int) (json_decode((string) $private->getBody(), true)['data']['id'] ?? 0);
        $privateRepost = $this->postJson('/api/v1/posts/' . $privateId . '/repost', [], $cookieB, $csrfB);
        self::assertSame(422, $privateRepost->getStatusCode());

        // 圈内动态不能转发：直接给帖子挂 community_id
        $circlePost = $this->postJson('/api/v1/posts', [
            'content' => '圈子内容',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $circlePostId = (int) (json_decode((string) $circlePost->getBody(), true)['data']['id'] ?? 0);

        $communityId = $this->query()->insert('communities', [
            'slug' => 'rp-circle-' . bin2hex(random_bytes(3)),
            'name' => '转发测试圈',
            'description' => '',
            'icon' => 'groups',
            'visibility' => 'public',
            'owner_id' => $this->userId($nameA),
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->query()->update('posts', ['community_id' => $communityId], 'id = :id', ['id' => $circlePostId]);

        $circleRepost = $this->postJson('/api/v1/posts/' . $circlePostId . '/repost', [], $cookieB, $csrfB);
        self::assertSame(422, $circleRepost->getStatusCode());
        self::assertStringContainsString('圈内', (string) $circleRepost->getBody());

        // 原帖删除后：引用卡显示占位、转发行仍可见
        $normal = $this->postJson('/api/v1/posts', [
            'content' => '将被删除的原帖',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [],
        ], $cookieA, $csrfA);
        $normalId = (int) (json_decode((string) $normal->getBody(), true)['data']['id'] ?? 0);
        self::assertSame(201, $this->postJson('/api/v1/posts/' . $normalId . '/repost', [], $cookieB, $csrfB)->getStatusCode());

        $delete = $this->deleteJson('/api/v1/posts/' . $normalId, $cookieA, $csrfA);
        self::assertSame(200, $delete->getStatusCode(), (string) $delete->getBody());

        $feed = (string) $this->get('/', 'text/html', $cookieB)->getBody();
        self::assertStringContainsString('原动态已删除或不可见', $feed);
    }
}
