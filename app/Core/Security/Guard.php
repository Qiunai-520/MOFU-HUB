<?php

declare(strict_types=1);

namespace Mofu\Core\Security;

use Mofu\Core\Http\HttpException;

/**
 * 权限判定。用户数组需包含 permissions 键（由 UserRepository::findForSession 提供）。
 */
final class Guard
{
    /** @param array<string, mixed>|null $user */
    public static function can(?array $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        /** @var list<string> $permissions */
        $permissions = $user['permissions'] ?? [];

        return in_array($permission, $permissions, true);
    }

    /**
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    public static function requireLogin(?array $user): array
    {
        if ($user === null) {
            throw HttpException::redirect(self::loginUrl());
        }

        return $user;
    }

    /** 登录页地址（带站内回跳） */
    private static function loginUrl(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $query = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_QUERY);

        if ($path === '/login' || str_starts_with($path, '/login/')) {
            return '/login';
        }

        $target = is_string($query) && $query !== '' ? $path . '?' . $query : $path;

        return '/login?redirect=' . rawurlencode($target);
    }

    /** @param array<string, mixed>|null $user */
    public static function require(?array $user, string $permission): void
    {
        if ($user === null) {
            throw HttpException::redirect(self::loginUrl());
        }

        if (!self::can($user, $permission)) {
            throw HttpException::forbidden();
        }
    }
}
