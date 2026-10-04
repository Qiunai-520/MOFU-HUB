<?php

declare(strict_types=1);

/*
 * MOFU 备份工具：数据库（纯 PHP 导出）+ 上传目录归档。
 *
 * 用法：
 *   php bin/backup.php [--out=DIR] [--skip-uploads] [--keep=N] [--help]
 *
 * 默认输出到 storage/backups/<Ymd-His>/：
 *   database.sql   数据库结构与数据（SET FOREIGN_KEY_CHECKS=0 包裹）
 *   uploads.zip    上传的图片与视频（有 ZipArchive 时；否则回退为 uploads/ 目录副本）
 *   manifest.json  备份元信息（时间 / 表数 / 行数 / 文件）
 *
 * 恢复步骤见 docs/tutorial.md「备份与恢复」：
 *   mysql -u <用户> -p <库名> < database.sql
 *   解压 uploads.zip 内容放回 public/uploads/
 */

require __DIR__ . '/../vendor/autoload.php';

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Connection;
use Mofu\Core\Support\Backup\DbDumper;

$root = dirname(__DIR__);

$options = getopt('', ['out:', 'skip-uploads', 'keep:', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
MOFU 备份工具

用法：php bin/backup.php [选项]

选项：
  --out=DIR        输出目录（默认 storage/backups/<Ymd-His>）
  --skip-uploads   仅备份数据库，不打包上传文件
  --keep=N         在输出目录的父目录中保留最近 N 份备份（默认 5）
  --help           显示本帮助

TXT;
    exit(0);
}

$keep = max(1, (int) ($options['keep'] ?? 5));
$skipUploads = isset($options['skip-uploads']);
$outDir = (string) ($options['out'] ?? $root . '/storage/backups/' . date('Ymd-His'));

if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "无法创建输出目录：{$outDir}\n");
    exit(1);
}

$config = Config::fromEnvironment($root);
$pdo = Connection::connect($config);

echo "[backup] 导出数据库 ...\n";
$stats = (new DbDumper($pdo))->dumpTo($outDir . '/database.sql');
echo "[backup] 数据库完成：{$stats['tables']} 张表 / {$stats['rows']} 行\n";

$files = ['database.sql' => (int) filesize($outDir . '/database.sql')];

if ($skipUploads) {
    echo "[backup] 已跳过上传目录\n";
} else {
    $uploads = $root . '/public/uploads';
    echo "[backup] 归档上传目录 ...\n";

    if (class_exists(ZipArchive::class)) {
        $zipPath = $outDir . '/uploads.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fwrite(STDERR, "无法创建上传归档：{$zipPath}\n");
            exit(1);
        }

        $count = 0;

        if (is_dir($uploads)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($uploads) + 1);

                if ($relative === false || $relative === '') {
                    continue;
                }

                $zip->addFile($file->getPathname(), $relative);
                $count++;
            }
        }

        $zip->close();

        $files['uploads.zip'] = (int) filesize($zipPath);
        echo "[backup] 上传文件 {$count} 个已打包为 uploads.zip\n";
    } else {
        $target = $outDir . '/uploads';
        $count = copyTree($uploads, $target);
        echo "[backup] 上传目录已复制（服务器无 Zip 扩展）：{$count} 个文件\n";
    }
}

file_put_contents(
    $outDir . '/manifest.json',
    json_encode([
        'app' => (string) $config->get('app.name', 'MOFU社区'),
        'time' => date('c'),
        'database' => $stats,
        'files' => $files,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n",
);

// 保留策略：只清理「备份目录」——同级目录中包含 manifest.json 的兄弟目录
// （避免在自定义 --out 时误删父目录中的普通目录）
pruneBackups($outDir, $keep);

$totalBytes = array_sum($files);
echo "[backup] 完成：{$outDir}（合计 " . number_format($totalBytes / 1048576, 2) . " MB）\n";
echo "[backup] 恢复：mysql -u <用户> -p <库名> < {$outDir}/database.sql\n";

/** @return int 复制的文件数 */
function copyTree(string $source, string $target): int
{
    if (!is_dir($source)) {
        return 0;
    }

    $count = 0;

    if (!is_dir($target) && !mkdir($target, 0775, true)) {
        fwrite(STDERR, "无法创建目录：{$target}\n");
        exit(1);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);

        if ($relative === false || $relative === '') {
            continue;
        }

        $destination = $target . '/' . $relative;

        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0775, true);
            }

            continue;
        }

        if ($item->isFile() && copy($item->getPathname(), $destination)) {
            $count++;
        }
    }

    return $count;
}

function pruneBackups(string $currentDir, int $keep): void
{
    $parent = dirname($currentDir);

    if (!is_dir($parent)) {
        return;
    }

    $entries = [];

    foreach (scandir($parent) ?: [] as $name) {
        $path = $parent . '/' . $name;

        if ($name === '.' || $name === '..' || !is_dir($path)) {
            continue;
        }

        // 仅以「包含 manifest.json」作为备份目录标识，避免误删无关目录
        if (!is_file($path . '/manifest.json')) {
            continue;
        }

        $entries[] = ['path' => $path, 'mtime' => (int) filemtime($path)];
    }

    usort($entries, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    foreach (array_slice($entries, $keep) as $entry) {
        if ($entry['path'] === rtrim($currentDir, '/')) {
            continue;
        }

        removeTree($entry['path']);
        echo '[backup] 已清理旧备份：' . $entry['path'] . "\n";
    }
}

function removeTree(string $dir): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}
