<?php

declare(strict_types=1);

namespace Mofu\Core\Support\Backup;

use PDO;
use RuntimeException;

/**
 * 纯 PHP 数据库导出（不依赖 mysqldump / shell）：
 * 结构（SHOW CREATE TABLE）+ 分批数据（INSERT，PDO::quote 转义）。
 */
final class DbDumper
{
    private const BATCH_SIZE = 500;
    private const ROWS_PER_INSERT = 100;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{tables: int, rows: int}
     */
    public function dumpTo(string $filePath): array
    {
        $out = fopen($filePath, 'wb');

        if ($out === false) {
            throw new RuntimeException('无法写入备份文件：' . $filePath);
        }

        $tables = 0;
        $rows = 0;

        try {
            fwrite($out, "-- MOFU 数据库备份\n-- 生成时间：" . date('Y-m-d H:i:s') . "\n\n");
            fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

            foreach ($this->tables() as $table) {
                $tables++;
                $rows += $this->dumpTable($out, $table);
            }

            fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            fclose($out);
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    /** @return list<string> */
    private function tables(): array
    {
        $names = [];

        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $names[] = (string) $name;
        }

        return $names;
    }

    /** @param resource $out */
    private function dumpTable($out, string $table): int
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';

        fwrite($out, "-- ----------------------------\n-- 表结构：{$quoted}\n-- ----------------------------\n");
        fwrite($out, "DROP TABLE IF EXISTS {$quoted};\n");

        $create = $this->pdo->query("SHOW CREATE TABLE {$quoted}");
        $ddl = (string) ($create?->fetch(PDO::FETCH_NUM)[1] ?? '');
        $create?->closeCursor();
        fwrite($out, $ddl . ";\n\n");

        $rows = 0;
        $offset = 0;
        $columns = null;

        while (true) {
            $statement = $this->pdo->query(
                "SELECT * FROM {$quoted} LIMIT " . self::BATCH_SIZE . " OFFSET {$offset}",
            );
            $batch = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();

            if ($batch === []) {
                break;
            }

            if ($columns === null) {
                $escaped = array_map(
                    static fn (string $c): string => str_replace('`', '``', $c),
                    array_keys($batch[0]),
                );
                $columns = '`' . implode('`, `', $escaped) . '`';
            }

            foreach (array_chunk($batch, self::ROWS_PER_INSERT) as $chunk) {
                $values = [];

                foreach ($chunk as $row) {
                    $parts = [];

                    foreach ($row as $value) {
                        $parts[] = $value === null ? 'NULL' : $this->pdo->quote((string) $value);
                    }

                    $values[] = '(' . implode(', ', $parts) . ')';
                }

                fwrite($out, "INSERT INTO {$quoted} ({$columns}) VALUES\n  " . implode(",\n  ", $values) . ";\n");
                $rows += count($chunk);
            }

            $offset += self::BATCH_SIZE;
        }

        fwrite($out, "\n");

        return $rows;
    }
}
