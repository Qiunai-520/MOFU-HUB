<?php

declare(strict_types=1);

namespace Mofu\Modules\Comments;

use Mofu\Bootstrap\Config;

/**
 * 聚合 Twikoo 评论数，避免前端为了几个数字下载完整评论客户端。
 */
final class TwikooCountService
{
    private const CACHE_TTL = 300;
    private const MAX_PATHS = 50;

    private readonly string $envId;
    private readonly string $cacheFile;

    public function __construct(Config $config)
    {
        $this->envId = rtrim((string) $config->get('twikoo.env_id', ''), '/') . '/';
        $this->cacheFile = rtrim((string) $config->get('root', ''), '/') . '/storage/cache/twikoo-counts.json';
    }

    /**
     * @param list<string> $paths
     * @return array<string, int>
     */
    public function counts(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            $paths,
            static fn (string $path): bool => preg_match('#^/posts/[1-9][0-9]*$#', $path) === 1,
        )));
        $paths = array_slice($paths, 0, self::MAX_PATHS);

        if ($paths === [] || $this->envId === '/') {
            return [];
        }

        $cache = $this->readCache();
        $now = time();
        $missing = array_values(array_filter(
            $paths,
            static fn (string $path): bool => !isset($cache[$path]) || (int) ($cache[$path]['expires'] ?? 0) <= $now,
        ));

        if ($missing !== []) {
            $remote = $this->fetchRemote($missing);

            if ($remote !== []) {
                foreach ($remote as $path => $count) {
                    $cache[$path] = ['count' => $count, 'expires' => $now + self::CACHE_TTL];
                }

                $this->writeCache($cache);
            }
        }

        $result = [];

        foreach ($paths as $path) {
            if (isset($cache[$path]['count'])) {
                // 上游暂时不可用时保留最后一次已知值，页面已有 SSR 值兜底。
                $result[$path] = max(0, (int) $cache[$path]['count']);
            }
        }

        return $result;
    }

    /**
     * @param list<string> $paths
     * @return array<string, int>
     */
    private function fetchRemote(array $paths): array
    {
        if (!filter_var($this->envId, FILTER_VALIDATE_URL)) {
            return [];
        }

        $payload = json_encode([
            'accessToken' => null,
            'event' => 'GET_COMMENTS_COUNT',
            'envId' => $this->envId,
            'urls' => $paths,
            'includeReply' => true,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return [];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: MOFU-Count-Proxy/1.0\r\n",
                'content' => $payload,
                'timeout' => 3.5,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($this->envId, false, $context);

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        $rows = is_array($decoded) ? ($decoded['data'] ?? null) : null;

        if (!is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $path = (string) ($row['url'] ?? '');
            $count = $row['count'] ?? null;

            if (in_array($path, $paths, true) && is_numeric($count)) {
                $result[$path] = max(0, (int) $count);
            }
        }

        return $result;
    }

    /** @return array<string, array{count:int,expires:int}> */
    private function readCache(): array
    {
        if (!is_file($this->cacheFile)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($this->cacheFile), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, array{count:int,expires:int}> $cache */
    private function writeCache(array $cache): void
    {
        // 控制缓存规模；评论数只是一段时间内的去抖优化。
        if (count($cache) > 5000) {
            uasort($cache, static fn (array $a, array $b): int => (int) $b['expires'] <=> (int) $a['expires']);
            $cache = array_slice($cache, 0, 5000, true);
        }

        $json = json_encode($cache, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        $directory = dirname($this->cacheFile);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $temporary = $this->cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return;
        }

        @rename($temporary, $this->cacheFile);
    }
}
