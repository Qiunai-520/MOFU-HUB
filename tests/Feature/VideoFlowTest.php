<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;
use Slim\Psr7\UploadedFile;

/**
 * 阶段四 B：视频直传（类型校验、互斥规则、帖子类型与渲染）。
 */
final class VideoFlowTest extends HttpTestCase
{
    public function testVideoUploadMixGuardsAndRendering(): void
    {
        [$cookieA, $csrfA] = $this->registerUser('vd_a');

        // 视频上传成功
        $upload = $this->sendMultipart(
            '/api/v1/uploads',
            ['_csrf' => $csrfA, 'kind' => 'video'],
            ['file' => $this->mp4File()],
            $cookieA,
        );
        self::assertSame(201, $upload->getStatusCode(), (string) $upload->getBody());
        $uploadData = json_decode((string) $upload->getBody(), true)['data'] ?? [];
        self::assertSame('video', (string) ($uploadData['kind'] ?? ''));
        $videoId = (int) ($uploadData['id'] ?? 0);
        self::assertGreaterThan(0, $videoId);

        // 图片冒充视频 → 422
        $fake = $this->sendMultipart(
            '/api/v1/uploads',
            ['_csrf' => $csrfA, 'kind' => 'video'],
            ['file' => $this->pngFile()],
            $cookieA,
        );
        self::assertSame(422, $fake->getStatusCode());
        self::assertStringContainsString('MP4', (string) $fake->getBody());

        // 正常图片上传
        $image = $this->sendMultipart('/api/v1/uploads', ['_csrf' => $csrfA], ['file' => $this->pngFile()], $cookieA);
        self::assertSame(201, $image->getStatusCode(), (string) $image->getBody());
        $imageId = (int) (json_decode((string) $image->getBody(), true)['data']['id'] ?? 0);

        // 视频与图片混排 → 422
        $mix = $this->postJson('/api/v1/posts', [
            'content' => '',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [$videoId, $imageId],
        ], $cookieA, $csrfA);
        self::assertSame(422, $mix->getStatusCode());
        self::assertStringContainsString('视频不能与图片', (string) $mix->getBody());

        // 两个视频 → 422
        $upload2 = $this->sendMultipart(
            '/api/v1/uploads',
            ['_csrf' => $csrfA, 'kind' => 'video'],
            ['file' => $this->mp4File()],
            $cookieA,
        );
        $video2Id = (int) (json_decode((string) $upload2->getBody(), true)['data']['id'] ?? 0);
        $two = $this->postJson('/api/v1/posts', [
            'content' => '',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [$videoId, $video2Id],
        ], $cookieA, $csrfA);
        self::assertSame(422, $two->getStatusCode());
        self::assertStringContainsString('1 个视频', (string) $two->getBody());

        // 正常视频帖
        $post = $this->postJson('/api/v1/posts', [
            'content' => '视频测试帖',
            'visibility' => 'public',
            'topics' => [],
            'media_ids' => [$videoId],
        ], $cookieA, $csrfA);
        self::assertSame(201, $post->getStatusCode(), (string) $post->getBody());
        $postId = (int) (json_decode((string) $post->getBody(), true)['data']['id'] ?? 0);

        self::assertSame(
            'video',
            (string) $this->query()->value('SELECT type FROM posts WHERE id = :id', ['id' => $postId]),
        );

        $detail = (string) $this->get('/posts/' . $postId, 'text/html', $cookieA)->getBody();
        self::assertStringContainsString('post-detail__video', $detail);
        self::assertStringContainsString('<video', $detail);

        $feed = (string) $this->get('/', 'text/html', $cookieA)->getBody();
        self::assertStringContainsString('post-media__video', $feed);
    }

    private function mp4File(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mofu_mp4_') . '.mp4';
        $header = hex2bin('00000018667479706d703432000000006d70343269736f6d');
        self::assertNotFalse($header);
        file_put_contents($path, $header . str_repeat("\x00", 1024));

        return new UploadedFile($path, 'clip.mp4', 'video/mp4', (int) filesize($path), UPLOAD_ERR_OK);
    }

    private function pngFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mofu_png_') . '.png';
        $image = imagecreatetruecolor(32, 32);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 60, 60));
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'pic.png', 'image/png', (int) filesize($path), UPLOAD_ERR_OK);
    }
}
