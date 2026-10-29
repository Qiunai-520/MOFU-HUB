<?php

declare(strict_types=1);

namespace Mofu\Tests\Unit;

use Dotenv\Dotenv;
use Mofu\Install\EnvChecker;
use Mofu\Install\EnvFileWriter;
use Mofu\Install\InstallGate;
use Mofu\Install\Installer;
use PHPUnit\Framework\TestCase;

final class InstallEnvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mofu_install_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testWizardEnvValuesCoverCommentConfig(): void
    {
        // 回归：新增配置必须进安装向导的 .env 清单，否则部署到服务器会静默失效（Twikoo 就这么翻过车）
        $values = Installer::envValues([
            'site_name' => '测试站',
            'site_url' => 'https://example.com',
            'db_host' => '127.0.0.1',
            'db_name' => 'mofu',
            'db_user' => 'mofu',
            'db_pass' => 'secret',
            'twikoo_env' => 'https://twikoo.example.com',
            'debug' => '',
        ], 3307);

        self::assertSame('https://twikoo.example.com', $values['TWIKOO_ENV_ID']);
        self::assertSame('/vendor/twikoo.all.min.js', $values['TWIKOO_SCRIPT_URL']);
        self::assertSame('zh-CN', $values['TWIKOO_LANG']);
        self::assertSame('3307', $values['DB_PORT']);

        // 没填环境 ID 时写入空串（前端据此只用站内评论）
        $empty = Installer::envValues(['site_name' => 'x'], 3306);
        self::assertSame('', $empty['TWIKOO_ENV_ID']);
        self::assertArrayHasKey('TWIKOO_SCRIPT_URL', $empty);
    }

    public function testWizardEnvValuesCoverAllManagedKeys(): void
    {
        // 回归：向导生成的 .env 必须覆盖全部管理键，否则部署后会缺项（曾出现 MAIL_* 等缺失）
        $values = Installer::envValues(['site_name' => 'x'], 3306);

        foreach (\Mofu\Install\EnvFileWriter::MANAGED_KEYS as $key) {
            self::assertArrayHasKey($key, $values, '安装向导缺少 .env 键：' . $key);
        }
    }

    public function testEnvRoundtripWithSpecialCharacters(): void
    {
        $password = 'p@s s\'"$#\\=done';

        $content = EnvFileWriter::build([
            'APP_NAME' => 'MOFU社区',
            'DB_PASSWORD' => $password,
            'DB_PORT' => '3306',
        ]);

        $parsed = Dotenv::parse($content);

        self::assertSame($password, $parsed['DB_PASSWORD']);
        self::assertSame('MOFU社区', $parsed['APP_NAME']);
        self::assertSame('3306', (string) $parsed['DB_PORT']);
        self::assertStringContainsString('DB_PASSWORD="', $content);
    }

    public function testWriteCreatesBackupAndKeepsCustomLines(): void
    {
        file_put_contents($this->dir . '/.env', "APP_NAME='旧名称'\nCUSTOM_KEY='keep-me'\n");

        $result = EnvFileWriter::write($this->dir, [
            'APP_NAME' => '新名称',
            'DB_HOST' => '127.0.0.1',
        ]);

        self::assertTrue($result['ok']);
        self::assertNotNull($result['backup']);
        self::assertFileExists($result['backup']);
        self::assertStringContainsString('旧名称', (string) file_get_contents($result['backup']));

        $content = (string) file_get_contents($this->dir . '/.env');
        $parsed = Dotenv::parse($content);

        self::assertSame('新名称', $parsed['APP_NAME']);
        self::assertSame('127.0.0.1', $parsed['DB_HOST']);
        self::assertSame('keep-me', $parsed['CUSTOM_KEY']);
        self::assertSame(1, substr_count($content, 'APP_NAME='));
        self::assertFileDoesNotExist($this->dir . '/.env.tmp');
    }

    public function testCheckerRequiresRuntimeDirectories(): void
    {
        $checks = EnvChecker::check($this->dir);

        self::assertFalse(EnvChecker::allRequiredPassed($checks));

        $labels = array_column($checks, 'label');
        self::assertContains('PHP 扩展 PDO', $labels);
        self::assertContains('storage/ 目录可写', $labels);

        mkdir($this->dir . '/storage/logs', 0775, true);
        mkdir($this->dir . '/storage/cache', 0775, true);
        mkdir($this->dir . '/public/uploads', 0775, true);

        $checks = EnvChecker::check($this->dir);

        self::assertTrue(EnvChecker::allRequiredPassed($checks));

        $assets = array_values(array_filter(
            $checks,
            static fn (array $check): bool => str_contains($check['label'], 'manifest.json'),
        ));
        self::assertCount(1, $assets);
        self::assertSame('recommended', $assets[0]['level']);
        self::assertFalse($assets[0]['ok']);
    }

    public function testGateLockLifecycle(): void
    {
        mkdir($this->dir . '/storage', 0775, true);

        $gate = new InstallGate($this->dir);

        self::assertFalse($gate->isLocked());
        self::assertTrue($gate->writeLock(['installed_at' => 'now']));
        self::assertTrue($gate->isLocked());

        $lock = (string) file_get_contents(InstallGate::lockPath($this->dir));
        self::assertStringContainsString('"installed_at": "now"', $lock);
    }

    public function testClassifyTables(): void
    {
        self::assertSame('empty', Installer::classifyTables([]));
        self::assertSame('installed', Installer::classifyTables(['schema_migrations', 'users']));
        self::assertSame('partial', Installer::classifyTables(['Users', 'user_profiles']));
        self::assertSame('foreign', Installer::classifyTables(['orders', 'customers']));
    }

    public function testGateProbeWithoutEnvReturnsNull(): void
    {
        self::assertNull((new InstallGate($this->dir))->probe());
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
