<?php

declare(strict_types=1);

namespace Mofu\Core\View;

/**
 * 通用展示辅助。
 */
final class Present
{
    /**
     * 根据用户 id 派生文字头像色相（黄金角分布，稳定且均匀）。
     */
    public static function avatarHue(int $userId): int
    {
        return (int) fmod($userId * 137.508, 360.0);
    }

    /**
     * 根据媒体记录的缩略图/原图路径生成可访问 URL（无媒体时返回空串）。
     */
    public static function uploadUrl(?string $thumbPath, ?string $diskPath): string
    {
        $path = trim((string) $thumbPath) !== '' ? (string) $thumbPath : (string) $diskPath;

        return trim($path) === '' ? '' : '/uploads/' . ltrim($path, '/');
    }
}
