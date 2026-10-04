<?php

declare(strict_types=1);

namespace Mofu\Core\View;

use RuntimeException;

/**
 * Vite 资源桥接：
 * - 开发模式（配置了 dev server 地址）：输出指向 Vite dev server 的模块脚本（含 HMR）。
 * - 生产模式：读取构建 manifest，输出带哈希的静态资源标签。
 */
final class ViteAssets
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $devServerBase = '',
    ) {
    }

    public function isDev(): bool
    {
        return $this->devServerBase !== '';
    }

    /**
     * 返回入口的资源标签（HTML 片段）。
     */
    public function entry(string $name): string
    {
        if ($this->isDev()) {
            $base = rtrim($this->devServerBase, '/');
            $tag = static fn (string $path): string => htmlspecialchars($base . '/' . ltrim($path, '/'), ENT_QUOTES);

            return sprintf(
                "<script type=\"module\" src=\"%s\"></script>\n<script type=\"module\" src=\"%s\"></script>",
                $tag('@vite/client'),
                $tag($name),
            );
        }

        $manifest = $this->manifest();
        $item = $manifest[$name] ?? null;

        if ($item === null) {
            throw new RuntimeException(sprintf('Vite manifest has no entry "%s". Run "npm run build" first.', $name));
        }

        $tags = [];

        foreach ((array) ($item['css'] ?? []) as $css) {
            $tags[] = sprintf('<link rel="stylesheet" href="/assets/%s">', htmlspecialchars((string) $css, ENT_QUOTES));
        }

        $tags[] = sprintf('<script type="module" src="/assets/%s"></script>', htmlspecialchars((string) $item['file'], ENT_QUOTES));

        return implode("\n", $tags);
    }

    /** @return array<string, array<string, mixed>> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (!is_file($this->manifestPath)) {
            throw new RuntimeException('Vite manifest not found. Run "npm run build" first.');
        }

        $decoded = json_decode((string) file_get_contents($this->manifestPath), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Vite manifest is invalid.');
        }

        /** @var array<string, array<string, mixed>> $decoded */
        return $this->manifest = $decoded;
    }
}
