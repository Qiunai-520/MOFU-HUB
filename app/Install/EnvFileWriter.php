<?php

declare(strict_types=1);

namespace Mofu\Install;

/**
 * 安装向导的 .env 生成器。
 *
 * - 管理键（以下白名单）统一重写；用户手写的其他行原样保留；
 * - 值一律双引号包裹：phpdotenv 仅支持 \\、\"、\$ 三种转义（单引号无转义能力），
 *   因此需要转义 \、" 与 $ 三个字符；
 * - 先写 .env.tmp 再 rename 原子替换，覆盖前生成 .env.bak.<时间戳> 备份。
 */
final class EnvFileWriter
{
    /** @var list<string> */
    public const MANAGED_KEYS = [
        'APP_NAME',
        'APP_ENV',
        'APP_DEBUG',
        'APP_URL',
        'APP_KEY',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'SESSION_COOKIE',
        'SESSION_LIFETIME',
        'VITE_DEV_SERVER',
        'UPLOAD_MAX_MB',
        'UPLOAD_VIDEO_MAX_MB',
        'THEME_DEFAULT_COLOR',
        'MUSIC_APP_URL',
        'TWIKOO_ENV_ID',
        'TWIKOO_SCRIPT_URL',
        'TWIKOO_LANG',
        'TWIKOO_EMOTION_CDN',
        'MAIL_MAILER',
        'MAIL_HOST',
        'MAIL_PORT',
        'MAIL_ENCRYPTION',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'MAIL_FROM',
    ];

    /**
     * 生成 .env 文本。
     *
     * @param array<string, string> $values   管理键 => 值（缺失的键不写入）
     * @param string                $existing 现有 .env 内容（可选）
     */
    public static function build(array $values, string $existing = ''): string
    {
        $kept = [];

        foreach (preg_split('/\r\n|\r|\n/', $existing) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $match) === 1
                && in_array($match[1], self::MANAGED_KEYS, true)) {
                continue;
            }

            $kept[] = $line;
        }

        while ($kept !== [] && end($kept) === '') {
            array_pop($kept);
        }

        $lines = [
            '# ===== MOFU社区 环境配置（由安装向导生成，可手动修改） =====',
            '',
        ];

        foreach (self::MANAGED_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $lines[] = $key . '=' . self::quote($values[$key]);
            }
        }

        if ($kept !== []) {
            $lines[] = '';
            $lines[] = '# --- 原有自定义配置 ---';
            $lines = array_merge($lines, $kept);
        }

        return implode("\n", $lines) . "\n";
    }

    public static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value) . '"';
    }

    /**
     * 写入 .env。
     *
     * @param array<string, string> $values
     * @return array{ok: bool, content: string, error: string, backup: string|null} 失败时 content 仍可用于手动粘贴
     */
    public static function write(string $root, array $values, bool $backup = true): array
    {
        $root = rtrim($root, '/');
        $path = $root . '/.env';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $content = self::build($values, $existing);

        if (!is_dir($root) || !is_writable($root)) {
            return ['ok' => false, 'content' => $content, 'error' => '项目根目录不可写，无法生成 .env', 'backup' => null];
        }

        $backupPath = null;

        if ($backup && is_file($path)) {
            $backupPath = $path . '.bak.' . date('YmdHis');

            if (!@copy($path, $backupPath)) {
                return ['ok' => false, 'content' => $content, 'error' => '备份现有 .env 失败', 'backup' => null];
            }
        }

        $tmp = $path . '.tmp';

        if (@file_put_contents($tmp, $content) === false) {
            return ['ok' => false, 'content' => $content, 'error' => '写入 .env.tmp 失败', 'backup' => $backupPath];
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return ['ok' => false, 'content' => $content, 'error' => '替换 .env 失败', 'backup' => $backupPath];
        }

        @chmod($path, 0600);

        return ['ok' => true, 'content' => $content, 'error' => '', 'backup' => $backupPath];
    }
}
