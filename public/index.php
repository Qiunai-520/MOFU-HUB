<?php

declare(strict_types=1);

/*
 * MOFU社区 — 单入口。
 */

define('MOFU_ROOT', dirname(__DIR__));

if (!is_file(MOFU_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>缺少依赖</title>';
    echo '<h1>缺少 Composer 依赖</h1>';
    echo '<p>未找到 vendor/ 目录。请先执行 <code>composer install --no-dev</code>（或上传 vendor/ 目录）后刷新重试。</p>';
    exit;
}

require MOFU_ROOT . '/vendor/autoload.php';

// 安装门禁：站点未完成安装时接管请求（逻辑见 app/Install/InstallGate.php）。
// 门禁自身异常不阻断站点：记录后按原流程继续。
try {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

    if (Mofu\Install\InstallGate::intercept(MOFU_ROOT, $_SERVER['REQUEST_METHOD'] ?? 'GET', $requestPath, $_GET)) {
        exit;
    }
} catch (Throwable $e) {
    error_log('[mofu] install gate failed: ' . $e->getMessage());
}

try {
    $app = Mofu\Bootstrap\App::create(MOFU_ROOT);
    $app->run();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');

    $debug = (($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG')) === 'true');
    echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>500 - 服务不可用</title>';
    echo '<h1>500 — 服务暂时不可用</h1>';
    echo $debug
        ? '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</pre>'
        : '<p>请稍后再试。</p>';
}
