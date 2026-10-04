<?php

declare(strict_types=1);

namespace Mofu\Modules\Media;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class MediaApiController extends ApiController
{
    public function __construct(
        private readonly MediaService $media,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function upload(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        if (!$this->limiter->attempt('upload:' . $user['id'], 30, 600)) {
            throw HttpException::tooManyRequests('上传太频繁，请稍后再试');
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            throw HttpException::badRequest('请选择要上传的文件');
        }

        $kind = ($request->getParsedBody()['kind'] ?? '') === 'video' ? 'video' : 'image';

        if ($kind === 'video') {
            $media = $this->media->storeVideo((int) $user['id'], $file);
            $disk = ltrim((string) $media['disk_path'], '/');

            return $this->ok($response, [
                'id' => (int) $media['id'],
                'kind' => 'video',
                'url' => '/uploads/' . $disk,
                'thumb' => '',
                'width' => null,
                'height' => null,
            ], [], 201);
        }

        $media = $this->media->storeImage((int) $user['id'], $file);
        $disk = ltrim((string) $media['disk_path'], '/');
        $thumb = (string) ($media['thumb_path'] ?? '');

        return $this->ok($response, [
            'id' => (int) $media['id'],
            'kind' => 'image',
            'url' => '/uploads/' . $disk,
            'thumb' => $thumb !== '' ? '/uploads/' . ltrim($thumb, '/') : '/uploads/' . $disk,
            'width' => $media['width'] !== null ? (int) $media['width'] : null,
            'height' => $media['height'] !== null ? (int) $media['height'] : null,
        ], [], 201);
    }
}
