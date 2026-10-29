<?php

declare(strict_types=1);

namespace Mofu\Modules\Settings;

use Mofu\Core\Database\Query;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 用户偏好（user_preferences 键值表）：通知偏好与隐私偏好。
 */
final class PreferenceService
{
    private const NOTIFICATIONS_KEY = 'notifications';
    private const PRIVACY_KEY = 'privacy';
    private const APPEARANCE_KEY = 'appearance';

    private const DND_DEFAULT_START = 23;
    private const DND_DEFAULT_END = 7;

    public const DM_POLICIES = ['all', 'mutual', 'none'];
    public const CONNECTION_VISIBILITIES = ['public', 'self'];

    public function __construct(private readonly Query $query)
    {
    }

    /**
     * 通知偏好：分组 => 是否接收（默认开启）+ dnd 免打扰时段。
     *
     * @return array<string, mixed>
     */
    public function notificationPrefs(int $userId): array
    {
        $stored = $this->read($userId, self::NOTIFICATIONS_KEY);

        $prefs = [];

        foreach (array_keys(NotificationService::PREF_GROUPS) as $group) {
            $prefs[$group] = !array_key_exists($group, $stored) ? true : (bool) $stored[$group];
        }

        $dnd = $stored['dnd'] ?? null;

        $prefs['dnd'] = [
            'enabled' => is_array($dnd) && (bool) ($dnd['enabled'] ?? false),
            'start' => is_array($dnd) ? max(0, min(23, (int) ($dnd['start'] ?? self::DND_DEFAULT_START))) : self::DND_DEFAULT_START,
            'end' => is_array($dnd) ? max(0, min(23, (int) ($dnd['end'] ?? self::DND_DEFAULT_END))) : self::DND_DEFAULT_END,
        ];

        return $prefs;
    }

    /**
     * @param array<string, mixed> $prefs
     */
    public function saveNotificationPrefs(int $userId, array $prefs): void
    {
        $normalized = [];

        foreach (array_keys(NotificationService::PREF_GROUPS) as $group) {
            $normalized[$group] = (bool) ($prefs[$group] ?? false);
        }

        $dnd = is_array($prefs['dnd'] ?? null) ? $prefs['dnd'] : [];

        $normalized['dnd'] = [
            'enabled' => (bool) ($dnd['enabled'] ?? false),
            'start' => max(0, min(23, (int) ($dnd['start'] ?? self::DND_DEFAULT_START))),
            'end' => max(0, min(23, (int) ($dnd['end'] ?? self::DND_DEFAULT_END))),
        ];

        $this->write($userId, self::NOTIFICATIONS_KEY, $normalized);
    }

    /**
     * 隐私偏好：私信权限与关注列表可见性。
     *
     * @return array{dm_policy: string, connections_visibility: string}
     */
    public function privacyPrefs(int $userId): array
    {
        $stored = $this->read($userId, self::PRIVACY_KEY);

        $dmPolicy = (string) ($stored['dm_policy'] ?? 'all');
        $visibility = (string) ($stored['connections_visibility'] ?? 'public');

        return [
            'dm_policy' => in_array($dmPolicy, self::DM_POLICIES, true) ? $dmPolicy : 'all',
            'connections_visibility' => in_array($visibility, self::CONNECTION_VISIBILITIES, true) ? $visibility : 'public',
            'read_receipt' => (bool) ($stored['read_receipt'] ?? true),
        ];
    }

    /**
     * @param array{dm_policy?: string, connections_visibility?: string} $prefs
     */
    public function savePrivacyPrefs(int $userId, array $prefs): void
    {
        $dmPolicy = (string) ($prefs['dm_policy'] ?? 'all');
        $visibility = (string) ($prefs['connections_visibility'] ?? 'public');
        $readReceipt = (bool) ($prefs['read_receipt'] ?? true);

        $this->write($userId, self::PRIVACY_KEY, [
            'dm_policy' => in_array($dmPolicy, self::DM_POLICIES, true) ? $dmPolicy : 'all',
            'connections_visibility' => in_array($visibility, self::CONNECTION_VISIBILITIES, true) ? $visibility : 'public',
            'read_receipt' => $readReceipt,
        ]);
    }

    /**
     * 外观偏好：背景图（本地媒体 / 外链 URL）与透明度。
     *
     * @return array{background: array{media_id: int, url: string, opacity: int}}
     */
    public function appearancePrefs(int $userId): array
    {
        $stored = $this->read($userId, self::APPEARANCE_KEY);
        $bg = is_array($stored['background'] ?? null) ? $stored['background'] : [];

        $url = filter_var((string) ($bg['url'] ?? ''), FILTER_VALIDATE_URL);

        return [
            'background' => [
                'media_id' => (int) ($bg['media_id'] ?? 0),
                'url' => $url !== false ? (string) $url : '',
                'opacity' => max(0, min(100, (int) ($bg['opacity'] ?? 45))),
            ],
        ];
    }

    /**
     * @param array{background?: array{media_id?: int, url?: string, opacity?: int}} $prefs
     */
    public function saveAppearancePrefs(int $userId, array $prefs): void
    {
        $bg = is_array($prefs['background'] ?? null) ? $prefs['background'] : [];
        $url = filter_var((string) ($bg['url'] ?? ''), FILTER_VALIDATE_URL);

        $this->write($userId, self::APPEARANCE_KEY, [
            'background' => [
                'media_id' => (int) ($bg['media_id'] ?? 0),
                'url' => $url !== false ? (string) $url : '',
                'opacity' => max(0, min(100, (int) ($bg['opacity'] ?? 45))),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function read(int $userId, string $key): array
    {
        $row = $this->query->one(
            'SELECT pref_value FROM user_preferences WHERE user_id = :id AND pref_key = :key',
            ['id' => $userId, 'key' => $key],
        );

        $decoded = $row !== null ? json_decode((string) $row['pref_value'], true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $value */
    private function write(int $userId, string $key, array $value): void
    {
        $this->query->run(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value, updated_at)
             VALUES (:id, :key, :value, :now)
             ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value), updated_at = VALUES(updated_at)',
            [
                'id' => $userId,
                'key' => $key,
                'value' => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
                'now' => date('Y-m-d H:i:s'),
            ],
        );
    }
}
