<?php

declare(strict_types=1);

namespace Mofu\Modules\Media;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/**
 * 图片上传处理：
 * - 真实 MIME 与图片结构双重校验；
 * - 非 GIF 图片统一重编码为 WebP（剥离潜在恶意载荷）；
 * - 生成缩略图；随机文件名 + 日期目录。
 */
final class MediaService
{
    private const ALLOWED_MIME = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
        'image/gif' => true,
    ];

    /** 允许直传的视频 MIME → 扩展名 */
    private const VIDEO_MIME = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    private const MAX_DIMENSION = 2560;
    private const THUMB_DIMENSION = 640;

    public function __construct(
        private readonly Query $query,
        private readonly string $uploadsPath,
        private readonly int $maxBytes,
        private readonly int $videoMaxBytes,
    ) {
    }

    /**
     * 保存一张上传图片，返回 media 记录。
     *
     * @return array<string, mixed>
     */
    public function storeImage(int $userId, UploadedFileInterface $file): array
    {
        $error = $file->getError();

        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest($this->uploadErrorMessage($error));
        }

        $size = $file->getSize();

        if ($size === null || $size <= 0 || $size > $this->maxBytes) {
            throw HttpException::badRequest(sprintf('图片大小超出限制（最大 %d MB）', intdiv($this->maxBytes, 1048576)));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mofu_up_');

        if ($tmp === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        try {
            $stream = $file->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            file_put_contents($tmp, (string) $stream);

            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);

            if (!isset(self::ALLOWED_MIME[$mime])) {
                throw HttpException::badRequest('仅支持 JPG / PNG / WebP / GIF 图片');
            }

            $info = @getimagesize($tmp);
            if ($info === false) {
                throw HttpException::badRequest('图片文件已损坏或格式不受支持');
            }

            $width = (int) $info[0];
            $height = (int) $info[1];

            $relDir = date('Y/m');
            $absDir = rtrim($this->uploadsPath, '/') . '/' . $relDir;

            if (!is_dir($absDir) && !mkdir($absDir, 0775, true)) {
                throw new RuntimeException('无法创建上传目录');
            }

            $basename = bin2hex(random_bytes(8));
            $result = $this->process($tmp, $absDir, $relDir, $basename, $mime, $width, $height);

            $id = $this->query->insert('media', [
                'user_id' => $userId,
                'kind' => 'image',
                'disk_path' => $result['path'],
                'thumb_path' => $result['thumb'],
                'original_name' => mb_substr((string) ($file->getClientFilename() ?? ''), 0, 190),
                'mime_type' => $result['mime'],
                'size_bytes' => $result['size'],
                'width' => $result['width'],
                'height' => $result['height'],
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->query->one('SELECT * FROM media WHERE id = :id', ['id' => $id]) ?? [];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * 保存一个视频（不转码、不抽帧），返回 media 记录。
     *
     * @return array<string, mixed>
     */
    public function storeVideo(int $userId, UploadedFileInterface $file): array
    {
        $error = $file->getError();

        if ($error !== UPLOAD_ERR_OK) {
            throw HttpException::badRequest($this->uploadErrorMessage($error));
        }

        $size = $file->getSize();

        if ($size === null || $size <= 0 || $size > $this->videoMaxBytes) {
            throw HttpException::badRequest(sprintf('视频大小超出限制（最大 %d MB）', intdiv($this->videoMaxBytes, 1048576)));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mofu_vid_');

        if ($tmp === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        try {
            $stream = $file->getStream();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            file_put_contents($tmp, (string) $stream);

            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);

            if (!isset(self::VIDEO_MIME[$mime])) {
                throw HttpException::badRequest('仅支持 MP4 / WebM 视频');
            }

            $relDir = date('Y/m');
            $absDir = rtrim($this->uploadsPath, '/') . '/' . $relDir;

            if (!is_dir($absDir) && !mkdir($absDir, 0775, true)) {
                throw new RuntimeException('无法创建上传目录');
            }

            $basename = bin2hex(random_bytes(8));
            $rel = $relDir . '/' . $basename . '.' . self::VIDEO_MIME[$mime];
            $abs = $absDir . '/' . $basename . '.' . self::VIDEO_MIME[$mime];

            if (!@rename($tmp, $abs) && !copy($tmp, $abs)) {
                throw new RuntimeException('存储文件失败');
            }

            $id = $this->query->insert('media', [
                'user_id' => $userId,
                'kind' => 'video',
                'disk_path' => $rel,
                'thumb_path' => '',
                'original_name' => mb_substr((string) ($file->getClientFilename() ?? ''), 0, 190),
                'mime_type' => $mime,
                'size_bytes' => (int) filesize($abs),
                'width' => null,
                'height' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->query->one('SELECT * FROM media WHERE id = :id', ['id' => $id]) ?? [];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array{path: string, thumb: string, mime: string, size: int, width: int, height: int}
     */
    private function process(string $tmp, string $absDir, string $relDir, string $basename, string $mime, int $width, int $height): array
    {
        // GIF 保留动图原样，仅生成首帧缩略图
        if ($mime === 'image/gif') {
            $rel = $relDir . '/' . $basename . '.gif';
            $abs = $absDir . '/' . $basename . '.gif';

            if (!copy($tmp, $abs)) {
                throw new RuntimeException('存储文件失败');
            }

            $thumb = '';
            $image = @imagecreatefromgif($abs);
            if ($image !== false) {
                $thumb = $this->writeThumb($image, $absDir, $relDir, $basename);
                imagedestroy($image);
            }

            return [
                'path' => $rel,
                'thumb' => $thumb,
                'mime' => 'image/gif',
                'size' => (int) filesize($abs),
                'width' => $width,
                'height' => $height,
            ];
        }

        $image = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($image === false) {
            throw HttpException::badRequest('图片解析失败');
        }

        if (max($width, $height) > self::MAX_DIMENSION) {
            $scale = self::MAX_DIMENSION / max($width, $height);
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagescale($image, $newWidth, $newHeight, IMG_BICUBIC);

            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
                $width = $newWidth;
                $height = $newHeight;
            }
        }

        $rel = $relDir . '/' . $basename . '.webp';
        $abs = $absDir . '/' . $basename . '.webp';
        $mimeOut = 'image/webp';

        $ok = function_exists('imagewebp') && imagewebp($image, $abs, 84);

        if (!$ok) {
            $rel = $relDir . '/' . $basename . '.jpg';
            $abs = $absDir . '/' . $basename . '.jpg';
            $mimeOut = 'image/jpeg';
            imagejpeg($image, $abs, 86);
        }

        $thumb = $this->writeThumb($image, $absDir, $relDir, $basename);
        imagedestroy($image);

        return [
            'path' => $rel,
            'thumb' => $thumb,
            'mime' => $mimeOut,
            'size' => (int) filesize($abs),
            'width' => $width,
            'height' => $height,
        ];
    }

    private function writeThumb(\GdImage $image, string $absDir, string $relDir, string $basename): string
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $thumb = $image;
        if (max($width, $height) > self::THUMB_DIMENSION) {
            $scale = self::THUMB_DIMENSION / max($width, $height);
            $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
            if ($resized !== false) {
                $thumb = $resized;
            }
        }

        $rel = $relDir . '/' . $basename . '_thumb.webp';
        $abs = $absDir . '/' . $basename . '_thumb.webp';
        $ok = function_exists('imagewebp') && imagewebp($thumb, $abs, 78);

        if (!$ok) {
            $rel = $relDir . '/' . $basename . '_thumb.jpg';
            imagejpeg($thumb, $absDir . '/' . $basename . '_thumb.jpg', 80);
        }

        if ($thumb !== $image) {
            imagedestroy($thumb);
        }

        return $rel;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '图片大小超出服务器限制',
            UPLOAD_ERR_PARTIAL => '图片上传不完整，请重试',
            UPLOAD_ERR_NO_FILE => '没有收到文件',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '服务器存储异常，请稍后再试',
            default => '上传失败，请重试',
        };
    }
}
