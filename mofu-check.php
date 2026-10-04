<?php

declare(strict_types=1);

/**
 * MOFU社区 — 部署自检（临时诊断工具）
 *
 * 用法：
 *   1. 上传到站点 public/ 目录（与 index.php 同级）
 *   2. 浏览器访问 https://你的域名/mofu-check.php
 *   3. 把页面内容整段复制发回；诊断完成后请删除本文件
 *
 * 说明：只输出环境与错误信息，不输出数据库密码等凭据。
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$root = dirname(__DIR__);
$rows = [];

$add = static function (string $label, bool $ok, string $detail = '') use (&$rows): void {
    $rows[] = sprintf('[%s] %s%s', $ok ? 'OK  ' : 'FAIL', $label, $detail !== '' ? ' — ' . $detail : '');
};

$note = static function (string $label, string $detail = '') use (&$rows): void {
    $rows[] = sprintf('[INFO] %s%s', $label, $detail !== '' ? ' — ' . $detail : '');
};

echo "MOFU社区 部署自检\n";
echo '时间：' . date('Y-m-d H:i:s') . "\n";
echo '站点根：' . $root . "\n";

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

echo '访问协议：' . ($isHttps ? 'HTTPS' : 'HTTP') . "\n";
echo str_repeat('-', 60) . "\n";

// ---------- 基础环境
$add('PHP 版本 ≥ 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION);

foreach ([
    'pdo' => 'PDO',
    'pdo_mysql' => 'PDO MySQL',
    'mbstring' => 'mbstring',
    'json' => 'JSON',
    'fileinfo' => 'fileinfo',
] as $ext => $name) {
    $add('PHP 扩展 ' . $name, extension_loaded($ext));
}

$add('PHP 扩展 gd（图片处理）', extension_loaded('gd'), '缺少时图片上传/头像不可用');
$add('PHP 扩展 zip（备份打包）', class_exists(ZipArchive::class), '可选');

$add('根目录可写（生成 .env）', is_writable($root));

foreach (['storage', 'storage/logs', 'storage/cache', 'public/uploads'] as $dir) {
    $path = $root . '/' . $dir;
    $add($dir . '/ 存在且可写', is_dir($path) && is_writable($path), is_dir($path) ? (is_writable($path) ? '' : '存在但不可写') : '目录不存在');
}

$note(
    'storage/install.lock（安装锁）',
    is_file($root . '/storage/install.lock') ? '存在（安装向导已锁定，访问 /install 会 404）' : '不存在（未锁定，访问站点会进入安装向导）',
);

// ---------- 依赖与配置
$vendor = $root . '/vendor/autoload.php';
$add('vendor/autoload.php 存在', is_file($vendor));

if (is_file($vendor)) {
    require $vendor;
}

$add('.env 存在', is_file($root . '/.env'));

$manifest = $root . '/public/assets/manifest.json';
$add('前端构建产物 manifest.json 存在', is_file($manifest));

if (is_file($manifest)) {
    $decoded = json_decode((string) file_get_contents($manifest), true);
    $missing = [];

    foreach (is_array($decoded) ? $decoded : [] as $item) {
        if (!is_array($item)) {
            continue;
        }

        $files = array_merge([$item['file'] ?? null], (array) ($item['css'] ?? []), (array) ($item['assets'] ?? []));

        foreach ($files as $rel) {
            if (is_string($rel) && $rel !== '' && !is_file($root . '/public/assets/' . $rel)) {
                $missing[] = $rel;
            }
        }
    }

    $add(
        '构建产物文件齐全',
        $missing === [],
        $missing === [] ? '' : '缺少 ' . count($missing) . ' 个，例如 ' . $missing[0] . '（上传时可能漏传 public/assets/）',
    );
}

// ---------- 数据库
$pdo = null;

if (is_file($vendor) && is_file($root . '/.env')) {
    try {
        $config = Mofu\Bootstrap\Config::fromEnvironment($root);
        $add(
            '配置读取',
            true,
            'APP_ENV=' . (string) $config->get('app.env') . ' / APP_DEBUG=' . ($config->get('app.debug') ? 'true' : 'false'),
        );
        $note('APP_URL', (string) $config->get('app.url', ''));

        if (!(bool) $config->get('app.debug') && !$isHttps && str_starts_with(strtolower((string) $config->get('app.url', '')), 'https://')) {
            $note('提示', 'APP_URL 是 https 但当前用 HTTP 访问：建议统一使用 https（旧版本包在纯 HTTP 下会话 cookie 会失效，新包已修复）');
        }

        $pdo = Mofu\Core\Database\Connection::connect($config);
        $add('数据库连接', true, '库=' . (string) $config->get('db.database'));

        try {
            $note('数据库版本', (string) $pdo->query('SELECT VERSION()')->fetchColumn());
        } catch (Throwable) {
            // 忽略
        }

        $tables = array_map(
            static fn (mixed $name): string => strtolower((string) $name),
            $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),
        );
        $add('数据表数量 ≥ 38', count($tables) >= 38, count($tables) . ' 张');

        if (in_array('schema_migrations', $tables, true)) {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            $files = glob($root . '/migrations/*.sql') ?: [];
            $add('迁移全部应用', $applied >= count($files), $applied . ' / ' . count($files));
        } else {
            $add('迁移记录表 schema_migrations 存在', false, '缺失：数据库未初始化或未迁移');
        }

        try {
            $pdo->exec('CREATE TEMPORARY TABLE mofu_json_probe (v JSON)');
            $pdo->exec('DROP TEMPORARY TABLE mofu_json_probe');
            $note('数据库支持 JSON 列类型', '支持（本项目不依赖，仅记录）');
        } catch (Throwable) {
            $note('数据库支持 JSON 列类型', '不支持（旧版本；当前安装包已不依赖 JSON 类型，无需处理）');
        }

        try {
            $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $add('users 表可读', true, $users . ' 个用户');
        } catch (Throwable $e) {
            $add('users 表可读', false, $e->getMessage());
        }
    } catch (Throwable $e) {
        $add('数据库连接/读取', false, get_class($e) . ': ' . $e->getMessage());
    }
}

// ---------- 真实页面模板渲染（用应用自身的错误页模板，含布局与资源引用）
if (is_file($vendor) && is_file($manifest)) {
    try {
        $twig = new Mofu\Core\View\TwigRenderer(
            $root . '/app/Views',
            $root . '/storage/cache/twig',
            false,
            new Mofu\Core\View\ViteAssets($manifest),
        );

        $html = $twig->render('pages/error.twig', [
            'app' => ['name' => 'MOFU社区'],
            'upload' => ['maxMb' => 8, 'videoMaxMb' => 60],
            'currentUser' => null,
            'csrfToken' => str_repeat('0', 64),
            'currentPath' => '/',
            'status' => 500,
            'message' => '自检',
            'detail' => null,
        ]);

        $add('页面模板渲染（含布局与静态资源）', true, strlen($html) . ' 字节');
    } catch (Throwable $e) {
        $add('页面模板渲染（含布局与静态资源）', false, get_class($e) . ': ' . $e->getMessage());
    }
}

// ---------- 日志尾部
foreach (['storage/logs/app.log', 'storage/logs/install.log'] as $rel) {
    $path = $root . '/' . $rel;

    if (!is_file($path)) {
        $note($rel, '文件不存在（尚未写过日志）');

        continue;
    }

    $note($rel, filesize($path) . ' 字节');
}

echo implode("\n", $rows) . "\n";

foreach (['storage/logs/app.log', 'storage/logs/install.log'] as $rel) {
    $path = $root . '/' . $rel;

    if (!is_file($path)) {
        continue;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
    $tail = array_slice($lines, -8);

    if ($tail === []) {
        continue;
    }

    echo str_repeat('-', 60) . "\n";
    echo $rel . " 最后 " . count($tail) . " 行：\n";
    echo implode("\n", $tail) . "\n";
}

echo str_repeat('-', 60) . "\n";
echo "提示：本文件仅用于临时诊断，处理完成后请从服务器删除。\n";
