<?php

declare(strict_types=1);

namespace Mofu\Core\Security;

/**
 * 基于文件的轻量限流（滑动窗口近似）。适用于单机部署，无需 Redis。
 */
final class RateLimiter
{
    public function __construct(private readonly string $cachePath)
    {
    }

    /**
     * 尝试执行一次操作。
     *
     * 采用「追加时间戳 + 扫描窗口」而不是「读-改-写」：
     * 后者在并发或锁不可用的文件系统上会互相覆盖、把计数改小（限流被绕过）。
     *
     * @return bool true = 允许；false = 超出限制
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $file = $this->path($key);
        $dir = dirname($file);

        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $now = time();
        $line = $now . "\n";

        // 部分文件系统（Android FUSE / NFS 等）不支持 flock，失败时降级为普通追加
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            @file_put_contents($file, $line, FILE_APPEND);
        }

        $raw = @file_get_contents($file);

        if ($raw === false || $raw === '') {
            return true;
        }

        $hits = 0;
        $kept = [];
        $total = 0;

        foreach (explode("\n", $raw) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $total++;
            $timestamp = (int) $entry;

            if ($timestamp > $now - $windowSeconds) {
                $hits++;
                $kept[] = $timestamp;
            }
        }

        // 顺手压缩过期记录（并发下偶尔互相覆盖无伤：最多少清掉几行旧记录）
        if (count($kept) !== $total) {
            @file_put_contents($file, $kept === [] ? '' : implode("\n", $kept) . "\n");
        }

        return $hits <= $maxAttempts;
    }

    public function clear(string $key): void
    {
        $file = $this->path($key);

        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->cachePath, '/') . '/ratelimit/' . hash('sha256', $key) . '.json';
    }
}
