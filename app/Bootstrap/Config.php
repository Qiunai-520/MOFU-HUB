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
                'key' => $env('APP_KEY', ''),
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
                'default_color' => $env('THEME_DEFAULT_COLOR', '#FFCC38'),
            ],
            'music' => [
                // 音乐播放器（独立部署在 Cloudflare Pages）的地址；空则音乐页显示占位说明
                'app_url' => rtrim($env('MUSIC_APP_URL', ''), '/'),
            ],
            'twikoo' => [
                'env_id' => $env('TWIKOO_ENV_ID', ''),
                // 默认用站内自托管脚本（public/vendor），避免依赖外部 CDN；可换为自建 CDN 地址
                'script_url' => $env('TWIKOO_SCRIPT_URL', '/vendor/twikoo.all.min.js'),
                // 语言：zh-CN 已内嵌在主包里，不需要额外请求；其他语言由 Twikoo 自行按需拉取
                'lang' => $env('TWIKOO_LANG', 'zh-CN'),
                // 表情包（OwO JSON）地址。默认用站内自带的 Apple 表情包；
                // 受 Twikoo 后端 EMOTION_CDN 为服务端配置所限，前端通过覆写 GET_CONFIG 响应生效。
                'emotion_cdn' => $env('TWIKOO_EMOTION_CDN', '/vendor/emoji-apple.json'),
            ],
            'mail' => [
                // smtp = 真发邮件；log = 写入 storage/logs/mail.log（本地开发无 SMTP 时用）
                'driver' => $env('MAIL_MAILER', 'log'),
                'host' => $env('MAIL_HOST', ''),
                'port' => (int) $env('MAIL_PORT', '465'),
                'encryption' => $env('MAIL_ENCRYPTION', 'ssl'), // ssl | tls | 空
                'username' => $env('MAIL_USERNAME', ''),
                'password' => $env('MAIL_PASSWORD', ''),
                'from' => $env('MAIL_FROM', ''),
            ],
            'payment' => [
                // 易支付（V1，MD5 签名）：接口地址、商户 ID、商户密钥（密钥只放环境变量，不入库/不入代码）
                'api' => rtrim($env('EZFP_API', 'https://www.ezfp.cn/'), '/') . '/',
                'pid' => $env('EZFP_PID', ''),
                'key' => $env('EZFP_KEY', ''),
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
