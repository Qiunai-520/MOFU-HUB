<?php

declare(strict_types=1);

/*
 * 管理员授予命令行入口。
 *
 * 安装向导只在空库时创建超级管理员；已部署实例（storage/install.lock 已存在）
 * 无法重跑向导，用本脚本把已有账号提升为管理员。
 *
 * 用法：
 *   php bin/make-admin.php                        查看现有管理员与角色列表
 *   php bin/make-admin.php <用户名|邮箱>           授予超级管理员
 *   php bin/make-admin.php <用户名|邮箱> <角色slug> 授予指定角色
 *   php bin/make-admin.php <用户名|邮箱> --revoke   撤销该账号的全部角色
 */

define('MOFU_ROOT', dirname(__DIR__));

require MOFU_ROOT . '/vendor/autoload.php';

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Connection;
use Mofu\Core\Database\Query;

try {
    $query = new Query(Connection::connect(Config::fromEnvironment(MOFU_ROOT)));
} catch (Throwable $e) {
    fwrite(STDERR, '数据库连接失败：' . $e->getMessage() . "\n");
    exit(1);
}

$needle = (string) ($argv[1] ?? '');
$target = (string) ($argv[2] ?? 'super_admin');

if ($needle === '') {
    echo "现有管理员：\n";

    $admins = $query->all(
        "SELECT u.id, u.username, GROUP_CONCAT(r.slug ORDER BY r.id) AS roles
           FROM users u
           JOIN user_roles ur ON ur.user_id = u.id
           JOIN roles r ON r.id = ur.role_id
           JOIN role_permissions rp ON rp.role_id = r.id
           JOIN permissions p ON p.id = rp.permission_id
          WHERE p.slug = 'admin.access'
          GROUP BY u.id, u.username",
    );

    echo $admins === [] ? "  （无，当前无人能进入后台）\n" : '';

    foreach ($admins as $row) {
        echo "  #{$row['id']} {$row['username']}（{$row['roles']}）\n";
    }

    echo "\n可用角色：\n";

    foreach ($query->all('SELECT slug, name FROM roles ORDER BY id') as $row) {
        echo "  {$row['slug']}（{$row['name']}）\n";
    }

    echo "\n用法：php bin/make-admin.php <用户名|邮箱> [角色slug|--revoke]\n";
    exit(0);
}

$user = $query->one(
    'SELECT id, username FROM users WHERE username = :username OR email = :email LIMIT 1',
    ['username' => $needle, 'email' => $needle],
);

if ($user === null) {
    fwrite(STDERR, "未找到用户：{$needle}\n");
    exit(1);
}

$userId = (int) $user['id'];

if ($target === '--revoke') {
    $removed = $query->delete('user_roles', 'user_id = :id', ['id' => $userId]);

    echo "已撤销 {$user['username']} 的 {$removed} 个角色\n";
    exit(0);
}

$role = $query->one('SELECT id, name FROM roles WHERE slug = :slug', ['slug' => $target]);

if ($role === null) {
    fwrite(STDERR, "未知角色：{$target}（用不带参数的命令查看可选角色）\n");
    exit(1);
}

$existing = $query->value(
    'SELECT COUNT(*) FROM user_roles WHERE user_id = :id AND role_id = :role',
    ['id' => $userId, 'role' => (int) $role['id']],
);

if ((int) $existing > 0) {
    echo "{$user['username']} 已经是「{$role['name']}」，无需改动\n";
    exit(0);
}

$query->insert('user_roles', [
    'user_id' => $userId,
    'role_id' => (int) $role['id'],
    'assigned_at' => date('Y-m-d H:i:s'),
]);

echo "已把 {$user['username']} 设为「{$role['name']}」，重新登录后可见后台入口\n";
