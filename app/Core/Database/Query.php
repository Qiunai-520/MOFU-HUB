<?php

declare(strict_types=1);

namespace Mofu\Core\Database;

use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;

/**
 * PDO 薄封装：参数化查询、便捷 CRUD 与分页。
 * 表名/列名仅接受安全标识符，值一律通过参数绑定。
 */
final class Query
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string|int, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /** @param array<string|int, mixed> $params */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->identifier($table),
            implode(', ', array_map($this->identifier(...), $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
        );

        $this->run($sql, $data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, mixed>  $params
     */
    public function update(string $table, array $data, string $where, array $params = []): int
    {
        $assignments = array_map(
            fn (string $c): string => $this->identifier($c) . ' = :d_' . $c,
            array_keys($data),
        );
        $bound = [];
        foreach ($data as $column => $value) {
            $bound['d_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->identifier($table),
            implode(', ', $assignments),
            $where,
        );

        return $this->run($sql, $bound + $params)->rowCount();
    }

    /** @param array<string, mixed> $params */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', $this->identifier($table), $where);

        return $this->run($sql, $params)->rowCount();
    }

    /**
     * 分页查询。
     *
     * @param array<string, mixed> $params
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function paginate(string $sql, string $countSql, array $params, int $page, int $perPage): array
    {
        $perPage = max(1, min($perPage, 100));
        $total = (int) $this->value($countSql, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => $this->all($sql . sprintf(' LIMIT %d OFFSET %d', $perPage, $offset), $params),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    public function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(sprintf('Invalid SQL identifier: %s', $name));
        }

        return '`' . $name . '`';
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
