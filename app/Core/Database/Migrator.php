<?php

declare(strict_types=1);

namespace Mofu\Core\Database;

use Mofu\Core\Support\Logger;
use RuntimeException;

/**
 * 轻量数据库迁移器。
 *
 * 迁移文件为普通 .sql 文件，命名如 "001_create_users.sql"，按文件名升序执行。
 * UP 段 = 文件开头到 "-- @@DOWN" 标记之前；DOWN 段 = 标记之后。
 * 无标记时整个文件视为 UP（不可回退）。
 */
final class Migrator
{
    public function __construct(
        private readonly Query $query,
        private readonly string $path,
        private readonly Logger $logger,
    ) {
    }

    public function ensureRegistry(): void
    {
        $this->query->run(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->ensureRegistry();

        return array_map(
            static fn (array $row): string => (string) $row['version'],
            $this->query->all('SELECT version FROM schema_migrations ORDER BY version ASC'),
        );
    }

    /** @return array<string, string> 版本号 => 文件路径（升序） */
    public function available(): array
    {
        $files = glob(rtrim($this->path, '/') . '/*.sql') ?: [];
        $map = [];

        foreach ($files as $file) {
            $map[basename($file, '.sql')] = $file;
        }
        ksort($map);

        return $map;
    }

    /** @return list<string> 本次执行的版本 */
    public function migrate(): array
    {
        return $this->withLock(function (): array {
            $applied = $this->applied();
            $executed = [];

            foreach ($this->available() as $version => $file) {
                if (in_array($version, $applied, true)) {
                    continue;
                }

                $this->logger->info('migrate: ' . $version);
                $parts = self::parseFile($file);

                foreach ($parts['up'] as $statement) {
                    $this->query->run($statement);
                }

                $this->query->insert('schema_migrations', [
                    'version' => $version,
                    'applied_at' => date('Y-m-d H:i:s'),
                ]);
                $executed[] = $version;
            }

            return $executed;
        });
    }

    /** @return list<string> 回退的版本（按回退顺序） */
    public function rollback(int $steps = 1): array
    {
        return $this->withLock(function () use ($steps): array {
            $applied = $this->applied();
            $targets = array_slice(array_reverse($applied), 0, max(0, $steps));
            $executed = [];

            foreach ($targets as $version) {
                $file = rtrim($this->path, '/') . '/' . $version . '.sql';
                if (!is_file($file)) {
                    throw new RuntimeException(sprintf('Migration file not found for version: %s', $version));
                }

                $parts = self::parseFile($file);
                if ($parts['down'] === []) {
                    throw new RuntimeException(sprintf('Migration %s has no DOWN section.', $version));
                }

                $this->logger->info('rollback: ' . $version);

                foreach ($parts['down'] as $statement) {
                    $this->query->run($statement);
                }

                $this->query->delete('schema_migrations', 'version = :version', ['version' => $version]);
                $executed[] = $version;
            }

            return $executed;
        });
    }

    /**
     * 数据库级互斥锁：避免网页按钮、CLI 或并发请求同时执行迁移。
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $name = 'mofu:migrate:' . substr(hash('sha256', $this->path), 0, 40);
        $acquired = $this->query->value('SELECT GET_LOCK(:name, 10)', ['name' => $name]);

        if ((int) $acquired !== 1) {
            throw new RuntimeException('另一个数据库升级正在执行，请稍后重试。');
        }

        try {
            return $callback();
        } finally {
            $this->query->value('SELECT RELEASE_LOCK(:name)', ['name' => $name]);
        }
    }

    /**
     * 解析迁移文件，切分为 UP / DOWN 语句列表。
     *
     * @return array{up: list<string>, down: list<string>}
     */
    public static function parseFile(string $file): array
    {
        $content = (string) file_get_contents($file);
        $segments = preg_split('/^[ \t]*--[ \t]*@@DOWN[ \t]*$/mi', $content, 2);
        $upSql = $segments[0] ?? '';
        $downSql = $segments[1] ?? '';

        return [
            'up' => self::splitStatements($upSql),
            'down' => self::splitStatements($downSql),
        ];
    }

    /**
     * 将 SQL 脚本切分为语句列表。忽略字符串字面量与注释中的分号。
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            $prev = $i > 0 ? $sql[$i - 1] : '';

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    while ($i < $length && $sql[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $end = strpos($sql, '*/', $i + 2);
                    $i = $end === false ? $length : $end + 2;
                    continue;
                }
            }

            if ($char === "'" && !$inDouble && !$inBacktick && $prev !== '\\') {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick && $prev !== '\\') {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble) {
                $inBacktick = !$inBacktick;
            } elseif ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $trimmed = trim($buffer);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
