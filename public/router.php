<?php

declare(strict_types=1);

/*
 * php -S 内置开发服务器的路由脚本：
 * 真实存在的静态文件直接返回，其余请求交给单入口 index.php。
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
