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
     * @return bool true = 允许；false = 超出限制
     */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $file = $this->path($key);
        $now = time();
        $data = ['start' => $now, 'count' => 0];

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = $raw !== false ? json_decode($raw, true) : null;

            if (is_array($decoded) && isset($decoded['start'], $decoded['count'])) {
                $data = ['start' => (int) $decoded['start'], 'count' => (int) $decoded['count']];
            }
        }

        if ($now - $data['start'] >= $windowSeconds) {
            $data = ['start' => $now, 'count' => 0];
        }

        $data['count']++;

        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // 部分文件系统（Android FUSE / NFS 等）不支持 flock，失败时降级为无锁写入
        $json = (string) json_encode($data);

        if (@file_put_contents($file, $json, LOCK_EX) === false) {
            @file_put_contents($file, $json);
        }

        return $data['count'] <= $maxAttempts;
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
