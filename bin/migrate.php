<?php

declare(strict_types=1);

/*
 * 数据库迁移命令行入口。
 *
 * 用法：
 *   php bin/migrate.php migrate           执行所有未应用的迁移
 *   php bin/migrate.php status            查看迁移状态
 *   php bin/migrate.php rollback [N]      回退最近 N 个迁移（默认 1）
 */

define('MOFU_ROOT', dirname(__DIR__));

require MOFU_ROOT . '/vendor/autoload.php';

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Connection;
use Mofu\Core\Database\Migrator;
use Mofu\Core\Database\Query;
use Mofu\Core\Support\Logger;

$config = Config::fromEnvironment(MOFU_ROOT);
$logger = new Logger(MOFU_ROOT . '/storage/logs/migrate.log', true);

try {
    $pdo = Connection::connect($config);
    $migrator = new Migrator(new Query($pdo), MOFU_ROOT . '/migrations', $logger);
    $command = $argv[1] ?? 'migrate';

    switch ($command) {
        case 'migrate':
            $executed = $migrator->migrate();
            echo $executed === []
                ? "Nothing to migrate.\n"
                : 'Migrated: ' . implode(', ', $executed) . "\n";
            break;

        case 'rollback':
            $steps = (int) ($argv[2] ?? 1);
            $executed = $migrator->rollback($steps);
            echo $executed === []
                ? "Nothing to roll back.\n"
                : 'Rolled back: ' . implode(', ', $executed) . "\n";
            break;

        case 'status':
            $applied = $migrator->applied();
            foreach ($migrator->available() as $version => $file) {
                printf("[%s] %s\n", in_array($version, $applied, true) ? 'x' : ' ', $version);
            }
            break;

        default:
            fwrite(STDERR, "Usage: php bin/migrate.php [migrate|status|rollback [N]]\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
