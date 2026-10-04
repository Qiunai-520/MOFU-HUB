#!/usr/bin/env bash
# MOFU社区 — 本地开发启动（数据库 + PHP 开发服务器）
# 用法：bash bin/dev-start.sh
set -e
cd "$(dirname "$0")/.."

echo "[mofu] 检查 MariaDB ..."
if ! mysqladmin ping --silent 2>/dev/null; then
    echo "[mofu] 启动 MariaDB ..."
    mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
    (mysqld_safe --user=mysql >/dev/null 2>&1 &)
    sleep 4
fi

echo "[mofu] MariaDB 状态：$(mysqladmin ping 2>/dev/null || echo '未就绪')"

# SSE 长连接需要多 worker，否则单进程开发服务器会被实时通知连接阻塞（每个连接占住一个 worker 约 26 秒）
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"

echo "[mofu] 启动 PHP 开发服务器：http://0.0.0.0:8000（workers=$PHP_CLI_SERVER_WORKERS）"
exec php -S 0.0.0.0:8000 -t public public/router.php
