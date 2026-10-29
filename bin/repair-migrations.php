<?php

declare(strict_types=1);

/*
 * 修复「库里有 MOFU 数据表、却缺少迁移记录表 schema_migrations」的数据库。
 *
 * 典型场景：站点数据来自旧库/备份，或 schema_migrations 被误删，安装向导因此
 * 判定为半装并停在冲突页。本工具按每个迁移的代表性表/列/索引做只读检测，
 * 推断出该库实际已应用到哪一版，再补写记录并执行剩余迁移。
 *
 * 用法：
 *   php bin/repair-migrations.php          只读检测（不写库）
 *   php bin/repair-migrations.php --apply  补写迁移记录 + 执行剩余迁移 + 写安装锁
 *
 * 安全性：只有当「已应用的迁移」构成一个干净前缀时才允许写入；一旦发现结构
 * 不连续（后面的迁移已应用、更早的却没有）就拒绝写入，避免把库标记成错误的版本。
 * --apply 之前请先备份：php bin/backup.php
 *
 * 没有命令行权限时，可改用安装向导冲突页上的「检测并补写迁移记录」按钮。
 */

define('MOFU_ROOT', dirname(__DIR__));

require MOFU_ROOT . '/vendor/autoload.php';

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Connection;
use Mofu\Core\Database\Migrator;
use Mofu\Core\Database\Query;
use Mofu\Core\Support\Logger;
use Mofu\Install\InstallGate;
use Mofu\Install\MigrationRepair;

$apply = in_array('--apply', array_slice($argv, 1), true);

try {
    $config = Config::fromEnvironment(MOFU_ROOT);
    $pdo = Connection::connect($config);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: 数据库连接失败：' . $e->getMessage() . "\n");
    exit(1);
}

$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$repair = new MigrationRepair($pdo);
$logger = new Logger(MOFU_ROOT . '/storage/logs/migrate.log', true);
$migrator = new Migrator(new Query($pdo), MOFU_ROOT . '/migrations', $logger);
$versions = array_keys($migrator->available());

$registryExists = $repair->tableExists('schema_migrations');
$recorded = $registryExists
    ? array_map('strval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) ?: [])
    : [];

echo "数据库：{$database}\n";
echo '迁移记录表 schema_migrations：' . ($registryExists ? '存在（' . count($recorded) . ' 条记录）' : '不存在') . "\n\n";

$analysis = $repair->analyze($versions);

foreach ($versions as $version) {
    $status = $analysis['statuses'][$version];
    $mark = match ($status) {
        true => '[x]',
        false => '[ ]',
        null => '[?]',
    };
    $suffix = match ($status) {
        true => in_array($version, $recorded, true) ? '已应用（有记录）' : '已应用（缺记录）',
        false => '未应用',
        null => '无结构校验，按前缀推断',
    };
    printf("%s %-36s %s\n", $mark, $version, $suffix);
}

echo "\n";

if (!$analysis['consistent']) {
    fwrite(STDERR, $analysis['reason'] . "\n");
    fwrite(STDERR, "这种库不能自动补记录（可能被手工改动过），请人工核对，或备份后改用空库重装。\n");
    exit(2);
}

$appliedVersions = $analysis['applied'];
$pendingVersions = $analysis['pending'];

echo $appliedVersions === []
    ? "推断：该库与任何迁移都不匹配（可能是空库或非 MOFU 库）。\n"
    : '推断：已应用到 ' . end($appliedVersions) . '（共 ' . count($appliedVersions) . ' 个迁移）。' . "\n";
echo $pendingVersions === []
    ? "没有待执行的迁移。\n"
    : '待执行迁移 ' . count($pendingVersions) . ' 个：' . implode(', ', $pendingVersions) . "\n";

if (!$apply) {
    echo "\n这是只读检测。确认无误后执行（请先备份：php bin/backup.php）：\n";
    echo "  php bin/repair-migrations.php --apply\n";
    exit(0);
}

if ($appliedVersions === []) {
    echo "\n未写入任何记录：库结构与迁移不匹配，若这是空库请直接用安装向导。\n";
    exit(1);
}

try {
    $migrator->ensureRegistry();

    $inserted = [];
    $insert = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
    foreach ($appliedVersions as $version) {
        if (!in_array($version, $recorded, true)) {
            $insert->execute([$version, date('Y-m-d H:i:s')]);
            $inserted[] = $version;
        }
    }

    echo "\n已补写迁移记录：" . count($inserted) . " 条\n";

    $executed = $migrator->migrate();
    echo $executed === []
        ? "剩余迁移无需执行。\n"
        : '已执行剩余迁移：' . implode(', ', $executed) . "\n";

    $gate = new InstallGate(MOFU_ROOT);
    $locked = $gate->writeLock([
        'installed_at' => date('c'),
        'app_name' => (string) $config->get('app.name', ''),
        'note' => 'repaired by bin/repair-migrations.php',
    ]);

    echo $locked
        ? "已写入 storage/install.lock，站点会直接进入运行状态。\n"
        : "警告：写入 install.lock 失败，请确认 storage/ 可写（未写锁时访问站点会自动补锁）。\n";
    echo "下一步：访问站点首页确认正常。\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n修复中断：" . $e->getMessage() . "\n");
    fwrite(STDERR, "已补写的记录不会回滚；请根据报错人工处理后重新运行本工具（只读模式）复核。\n");
    exit(1);
}
