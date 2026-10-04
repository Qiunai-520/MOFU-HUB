<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Core\Support\Backup\DbDumper;
use Mofu\Tests\Support\HttpTestCase;

/**
 * 阶段四 B：备份导出（结构完整性与转义正确性）。
 */
final class BackupDumpTest extends HttpTestCase
{
    public function testDumpStructureAndEscaping(): void
    {
        $query = $this->query();
        $query->run('CREATE TABLE IF NOT EXISTS dumper_probe (id INT NOT NULL, note VARCHAR(191) NOT NULL)');

        $file = tempnam(sys_get_temp_dir(), 'mofu_dump_') . '.sql';

        try {
            $note = "it's \"quoted\"\n换行\\反斜杠 😀";
            $query->run('INSERT INTO dumper_probe (id, note) VALUES (1, :n)', ['n' => $note]);

            $stats = (new DbDumper($this->pdo()))->dumpTo($file);
            $sql = (string) file_get_contents($file);

            // 结构与数据
            self::assertStringContainsString('CREATE TABLE `dumper_probe`', $sql);
            self::assertStringContainsString('CREATE TABLE `users`', $sql);
            self::assertStringContainsString('INSERT INTO `dumper_probe`', $sql);
            self::assertGreaterThanOrEqual(1, $stats['tables']);
            self::assertGreaterThanOrEqual(1, $stats['rows']);
            self::assertStringContainsString('SET FOREIGN_KEY_CHECKS = 1', $sql);

            // 转义：单引号、换行、反斜杠均被转义为合法 SQL 序列
            self::assertStringContainsString("it\\'s", $sql);
            self::assertStringContainsString('\\n换行', $sql);
            self::assertStringContainsString('\\\\反斜杠', $sql);

            // INSERT 语句完整（以分号结尾，未被裸换行截断）
            self::assertMatchesRegularExpression('/INSERT INTO `dumper_probe`[^;]+;/s', $sql);
        } finally {
            @unlink($file);
            $query->run('DROP TABLE IF EXISTS dumper_probe');
        }
    }
}
