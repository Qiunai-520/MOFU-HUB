<?php

declare(strict_types=1);

namespace Mofu\Core\Support;

/**
 * 文件日志。上下文以 JSON 附加，按行追加。
 */
final class Logger
{
    public function __construct(
        private readonly string $filePath,
        private readonly bool $debugEnabled = false,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        if ($this->debugEnabled) {
            $this->write('DEBUG', $message, $context);
        }
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $directory = dirname($this->filePath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $suffix = $context === []
            ? ''
            : ' ' . (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $line = sprintf("[%s] %s: %s%s\n", date('Y-m-d H:i:s'), $level, $message, $suffix);

        // 超过 5MB 轮转一次，避免 app.log 无限增长
        if (is_file($this->filePath) && filesize($this->filePath) > 5 * 1024 * 1024) {
            @rename($this->filePath, $this->filePath . '.1');
        }

        // 部分文件系统（Android FUSE / NFS 等）不支持 flock，失败时降级为无锁追加
        if (@file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX) === false) {
            @file_put_contents($this->filePath, $line, FILE_APPEND);
        }
    }
}
