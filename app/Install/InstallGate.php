<?php

declare(strict_types=1);

namespace Mofu\Install;

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Connection;
use Throwable;

/**
 * 安装门禁：public/index.php 在构建 Slim 应用前调用 intercept()。
 *
 * - 已加锁（storage/install.lock）：放行，仅保留“安装完成页”的只读访问；
 * - 未加锁但探测到已初始化：自动补写锁文件（自愈）后放行；
 * - 未加锁且全新部署（无 .env）或半装（能连库但无迁移记录）：请求重定向到 /install；
 * - 未加锁且无法判断（数据库连不上）：不劫持，交回原流程（避免故障期暴露向导）。
 */
final class InstallGate
{
    private const LOCK_FILE = 'storage/install.lock';

    public function __construct(private readonly string $root)
    {
    }

    public static function lockPath(string $root): string
    {
        return rtrim($root, '/') . '/' . self::LOCK_FILE;
    }

    public function isLocked(): bool
    {
        return is_file(self::lockPath($this->root));
    }

    /**
     * 三态探测：true=已初始化 / false=明确未初始化 / null=无法判断。
     */
    public function probe(): ?bool
    {
        if (!is_file($this->root . '/.env')) {
            return null;
        }

        try {
            $pdo = Connection::connect(Config::fromEnvironment($this->root));
        } catch (Throwable) {
            return null;
        }

        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        } catch (Throwable) {
            return false;
        }

        // 必须全部迁移都已应用才算已安装：否则半装站点会被误锁在向导之外
        $available = glob($this->root . '/migrations/*.sql') ?: [];

        return $available !== [] && $applied >= count($available);
    }

    /** @param array<string, mixed> $meta */
    public function writeLock(array $meta): bool
    {
        $path = self::lockPath($this->root);
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if ($json === false || !is_dir(dirname($path)) || !is_writable(dirname($path))) {
            return false;
        }

        $content = $json . "\n";

        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            return @file_put_contents($path, $content) !== false;
        }

        return true;
    }

    public function autoLock(): void
    {
        $this->writeLock([
            'installed_at' => date('c'),
            'app_name' => (string) Config::fromEnvironment($this->root)->get('app.name', ''),
            'note' => 'auto-locked on first request',
        ]);
    }

    public static function isInstallerPath(string $path): bool
    {
        $path = rtrim($path, '/');

        return $path === '/install' || str_starts_with($path, '/install/');
    }

    /**
     * @param array<string, mixed> $query
     * @return bool true = 已接管（响应已输出）；false = 继续正常应用
     */
    public static function intercept(string $root, string $method, string $path, array $query): bool
    {
        $gate = new self($root);

        if ($gate->isLocked()) {
            if ($method === 'GET' && self::isInstallerPath($path) && isset($query['done'])) {
                (new Installer($root))->renderDone();

                return true;
            }

            return false;
        }

        $probe = $gate->probe();

        if ($probe === true) {
            $gate->autoLock();

            if ($gate->isLocked()) {
                return false;
            }
        }

        if (self::isInstallerPath($path)) {
            (new Installer($root))->handle($method, $path, $query);

            return true;
        }

        if ($probe === false || !is_file($root . '/.env')) {
            header('Location: /install', true, 303);

            return true;
        }

        return false;
    }
}
