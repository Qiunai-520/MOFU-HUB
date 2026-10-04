<?php

declare(strict_types=1);

namespace Mofu\Tests\Unit;

use Mofu\Core\Database\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorParseTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mofu_migrator_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testParseSplitsUpAndDown(): void
    {
        $file = $this->dir . '/001_demo.sql';
        file_put_contents($file, "CREATE TABLE a (id INT);\n\n-- @@DOWN\n\nDROP TABLE a;\n");

        $parts = Migrator::parseFile($file);

        self::assertSame(['CREATE TABLE a (id INT)'], $parts['up']);
        self::assertSame(['DROP TABLE a'], $parts['down']);
    }

    public function testSemicolonInsideStringIsNotSplit(): void
    {
        $file = $this->dir . '/002_demo.sql';
        file_put_contents($file, "INSERT INTO t VALUES ('a;b', 'it''s ok');\n");

        $parts = Migrator::parseFile($file);

        self::assertCount(1, $parts['up']);
        self::assertStringContainsString("'a;b'", $parts['up'][0]);
    }

    public function testCommentsAreIgnored(): void
    {
        $file = $this->dir . '/003_demo.sql';
        file_put_contents($file, "-- 注释里有分号;\n/* 块注释; */\nSELECT 1; -- 行尾注释\nSELECT 2;\n");

        $parts = Migrator::parseFile($file);

        self::assertSame(['SELECT 1', 'SELECT 2'], $parts['up']);
    }
}
