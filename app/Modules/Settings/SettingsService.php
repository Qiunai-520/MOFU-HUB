<?php

declare(strict_types=1);

namespace Mofu\Modules\Settings;

use Mofu\Core\Database\Query;

/**
 * 系统设置（键值表，带请求内缓存）。
 */
final class SettingsService
{
    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(private readonly Query $query)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'on', 'yes'], true);
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $this->query->run(
            'INSERT INTO system_settings (setting_key, setting_value, updated_at, updated_by)
             VALUES (:k, :v, :now, :by)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            ['k' => $key, 'v' => $value, 'now' => date('Y-m-d H:i:s'), 'by' => $userId],
        );

        $this->cache = null;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = [];

            foreach ($this->query->all('SELECT setting_key, setting_value FROM system_settings') as $row) {
                $this->cache[(string) $row['setting_key']] = (string) $row['setting_value'];
            }
        }

        return $this->cache;
    }

    /** @return array<string, mixed> */
    public function defaultTheme(): array
    {
        $raw = $this->get('site.default_theme');
        $decoded = $raw !== null ? json_decode($raw, true) : null;

        $scheme = is_array($decoded) ? (string) ($decoded['scheme'] ?? 'auto') : 'auto';

        // The accent is a fixed brand value; only the appearance mode is
        // user-selectable. This also upgrades legacy stored theme JSON.
        return [
            'sourceColor' => '#FFCC38',
            'scheme' => in_array($scheme, ['auto', 'light', 'dark'], true) ? $scheme : 'auto',
            'variant' => 'tonalSpot',
            'contrast' => 'standard',
            'density' => 0,
            'motion' => 'standard',
        ];
    }
}
