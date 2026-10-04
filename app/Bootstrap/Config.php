<?php

declare(strict_types=1);

namespace Mofu\Bootstrap;

use Dotenv\Dotenv;

/**
 * 应用配置：.env + 默认值，支持 "a.b.c" 点路径读取。
 */
final class Config
{
    /** @param array<string, mixed> $items */
    private function __construct(private readonly array $items)
    {
    }

    public static function fromEnvironment(string $rootPath): self
    {
        if (is_file($rootPath . '/.env')) {
            Dotenv::createImmutable($rootPath)->safeLoad();
        }

        $env = static function (string $key, string $default = ''): string {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

            return is_string($value) && $value !== '' ? $value : $default;
        };

        $bool = static function (string $key, bool $default) use ($env): bool {
            $raw = $env($key, $default ? 'true' : 'false');

            return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
        };

        return new self([
            'root' => $rootPath,
            'app' => [
                'name' => $env('APP_NAME', 'MOFU社区'),
                'env' => $env('APP_ENV', 'local'),
                'debug' => $bool('APP_DEBUG', false),
                'url' => $env('APP_URL', ''),
                'vite_dev_server' => $env('VITE_DEV_SERVER', ''),
            ],
            'db' => [
                'host' => $env('DB_HOST', '127.0.0.1'),
                'port' => (int) $env('DB_PORT', '3306'),
                'database' => $env('DB_DATABASE', 'mofu'),
                'username' => $env('DB_USERNAME', 'root'),
                'password' => $env('DB_PASSWORD', ''),
            ],
            'session' => [
                'cookie' => $env('SESSION_COOKIE', 'mofu_session'),
                'lifetime' => (int) $env('SESSION_LIFETIME', '2592000'),
            ],
            'upload' => [
                'max_mb' => (int) $env('UPLOAD_MAX_MB', '8'),
                'video_max_mb' => (int) $env('UPLOAD_VIDEO_MAX_MB', '60'),
            ],
            'theme' => [
                'default_color' => $env('THEME_DEFAULT_COLOR', '#775A0D'),
            ],
            'twikoo' => [
                'env_id' => $env('TWIKOO_ENV_ID', ''),
                // 默认用站内自托管脚本（public/vendor），避免依赖外部 CDN；可换为自建 CDN 地址
                'script_url' => $env('TWIKOO_SCRIPT_URL', '/vendor/twikoo.all.min.js'),
                // 语言：zh-CN 已内嵌在主包里，不需要额外请求；其他语言由 Twikoo 自行按需拉取
                'lang' => $env('TWIKOO_LANG', 'zh-CN'),
            ],
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function isLocal(): bool
    {
        return $this->get('app.env') === 'local';
    }

    public function rootPath(string $append = ''): string
    {
        $root = (string) $this->get('root');

        return $append === '' ? $root : $root . '/' . ltrim($append, '/');
    }
}
