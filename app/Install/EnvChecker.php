<?php

declare(strict_types=1);

namespace Mofu\Install;

/**
 * 安装向导的环境自检。
 */
final class EnvChecker
{
    /**
     * @return list<array{label: string, ok: bool, level: string, hint: string}>
     */
    public static function check(string $root): array
    {
        $root = rtrim($root, '/');
        $checks = [];

        $add = static function (string $label, bool $ok, string $level, string $hint = '') use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'level' => $level, 'hint' => $hint];
        };

        $add('PHP 版本 ≥ 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), 'required', '当前 ' . PHP_VERSION);

        foreach (['pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL 驱动', 'mbstring' => 'mbstring', 'json' => 'JSON', 'fileinfo' => 'fileinfo'] as $ext => $name) {
            $add('PHP 扩展 ' . $name, extension_loaded($ext), 'required');
        }

        $add('PHP 扩展 gd（图片缩略图）', extension_loaded('gd'), 'recommended', '缺少时图片上传与头像处理不可用');
        $add('PHP 扩展 zip（备份打包）', class_exists(\ZipArchive::class), 'recommended', '缺少时备份工具将退化为目录拷贝');

        $add('根目录可写（生成 .env）', is_writable($root), 'required', '不支持时可在本页复制配置内容手动创建');

        foreach (['storage', 'storage/logs', 'storage/cache', 'public/uploads'] as $dir) {
            $path = $root . '/' . $dir;
            $add($dir . '/ 目录可写', is_dir($path) && is_writable($path), 'required', '需要 mkdir -p 并 chmod 0775');
        }

        $add(
            '前端构建产物 public/assets/manifest.json',
            is_file($root . '/public/assets/manifest.json'),
            'recommended',
            '缺失时页面样式与脚本不可用，请上传构建产物或运行 npm run build',
        );

        return $checks;
    }

    public static function allRequiredPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['level'] === 'required' && !$check['ok']) {
                return false;
            }
        }

        return true;
    }
}
