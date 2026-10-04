<?php

declare(strict_types=1);

namespace Mofu\Modules\Settings;

/**
 * 关于页「团队」名单：存于 system_settings（JSON），管理员在后台按行增删。
 *
 * 成员字段：name / role / bio / avatar（图片 URL）/ email / since（加入时间文本）。
 * avatar、email、since 由管理员手填，旧数据缺这些键时按空串处理。
 */
final class AboutTeam
{
    public const KEY = 'site.about_team';

    private const MAX_MEMBERS = 24;
    private const MAX_FIELD = 60;
    private const MAX_EMAIL = 120;
    private const MAX_SINCE = 32;
    private const MAX_AVATAR = 255;

    /**
     * 默认名单（未配置时使用）。
     *
     * @return list<array{name: string, role: string, bio: string, avatar: string, email: string, since: string}>
     */
    public static function defaults(): array
    {
        return [
            self::member('开发者', '开发者', '全栈开发与架构设计'),
            self::member('贡献者', '贡献者', '功能完善与体验优化'),
        ];
    }

    /**
     * @return list<array{name: string, role: string, bio: string, avatar: string, email: string, since: string}>
     */
    public static function decode(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return self::defaults();
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return self::defaults();
        }

        $members = [];

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $members[] = self::member(
                $name,
                (string) ($row['role'] ?? ''),
                (string) ($row['bio'] ?? ''),
                (string) ($row['avatar'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['since'] ?? ''),
            );
        }

        return $members === [] ? self::defaults() : $members;
    }

    /**
     * 从后台表单的并列数组构建名单（每行一个成员）。
     *
     * @param array<int|string, mixed> $names
     * @param array<int|string, mixed> $roles
     * @param array<int|string, mixed> $bios
     * @param array<int|string, mixed> $avatars
     * @param array<int|string, mixed> $emails
     * @param array<int|string, mixed> $sinces
     *
     * @return list<array{name: string, role: string, bio: string, avatar: string, email: string, since: string}>
     */
    public static function fromRows(
        array $names,
        array $roles,
        array $bios,
        array $avatars = [],
        array $emails = [],
        array $sinces = [],
    ): array {
        $members = [];

        foreach (array_values($names) as $i => $rawName) {
            $name = mb_substr(trim((string) $rawName), 0, self::MAX_FIELD);

            if ($name === '') {
                continue;
            }

            $members[] = self::member(
                $name,
                (string) ($roles[$i] ?? ''),
                (string) ($bios[$i] ?? ''),
                (string) ($avatars[$i] ?? ''),
                (string) ($emails[$i] ?? ''),
                (string) ($sinces[$i] ?? ''),
            );

            if (count($members) >= self::MAX_MEMBERS) {
                break;
            }
        }

        return $members;
    }

    /**
     * @param list<array{name: string, role: string, bio: string, avatar: string, email: string, since: string}> $members
     */
    public static function encode(array $members): string
    {
        return (string) json_encode($members, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 归一化单个成员（截断 + 去空白）。头像只接受站内 /uploads 或 http(s) 直链。
     *
     * @return array{name: string, role: string, bio: string, avatar: string, email: string, since: string}
     */
    private static function member(
        string $name,
        string $role = '',
        string $bio = '',
        string $avatar = '',
        string $email = '',
        string $since = '',
    ): array {
        $avatar = trim($avatar);
        $safeAvatar = $avatar !== '' && self::isSafeAvatar($avatar) ? mb_substr($avatar, 0, self::MAX_AVATAR) : '';

        return [
            'name' => mb_substr(trim($name), 0, self::MAX_FIELD),
            'role' => mb_substr(trim($role), 0, self::MAX_FIELD),
            'bio' => mb_substr(trim($bio), 0, self::MAX_FIELD * 2),
            'avatar' => $safeAvatar,
            'email' => mb_substr(trim($email), 0, self::MAX_EMAIL),
            'since' => mb_substr(trim($since), 0, self::MAX_SINCE),
        ];
    }

    /** 头像地址白名单：本站 /uploads 相对路径或 http(s) 直链，挡住 javascript: 等注入 */
    private static function isSafeAvatar(string $url): bool
    {
        if (str_starts_with($url, '/uploads/')) {
            return true;
        }

        return preg_match('#^https?://#i', $url) === 1;
    }
}
