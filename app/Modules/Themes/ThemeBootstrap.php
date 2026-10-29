<?php

declare(strict_types=1);

namespace Mofu\Modules\Themes;

use Mofu\Core\Database\Query;
use Mofu\Core\View\Present;
use Mofu\Modules\Settings\PreferenceService;
use Mofu\Modules\Settings\SettingsService;

/**
 * 页面外观数据：
 * - appearance：SSR 首帧使用的 auto / light / dark；
 * - themeConfig：客户端外观状态（品牌色固定，不再生成 HCT 调色板）；
 * - background：用户背景图偏好（url + opacity），未设置时为 null。
 */
final class ThemeBootstrap
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ThemeService $themes,
        private readonly PreferenceService $preferences,
        private readonly Query $query,
    ) {
    }

    /** @return array{appearance: string, themeCss: string, themeConfig: array<string, mixed>, background: array<string, mixed>|null, brandCss: string} */
    public function chrome(?int $userId): array
    {
        $userTheme = $userId !== null ? $this->themes->activeFor($userId) : null;

        if ($userTheme !== null) {
            $scheme = (string) $userTheme['scheme'];
            $name = (string) $userTheme['name'];
            $isCustom = true;
        } else {
            $default = $this->settings->defaultTheme();
            $scheme = (string) ($default['scheme'] ?? 'auto');
            $name = '默认外观';
            $isCustom = false;
        }

        if (!in_array($scheme, ['auto', 'light', 'dark'], true)) {
            $scheme = 'auto';
        }

        return [
            'appearance' => $scheme,
            // No generated CSS is injected any more: all colours live in the
            // design-token stylesheet and follow color-scheme.
            'themeCss' => '',
            'themeConfig' => [
                'name' => $name,
                'sourceColor' => '#FFCC38',
                'scheme' => $scheme,
                'contrast' => 'standard',
                'variant' => 'tonalSpot',
                'density' => 0,
                'motion' => 'standard',
                'isCustom' => $isCustom,
            ],
            'background' => $this->backgroundFor($userId),
            'brandCss' => '',
        ];
    }

    /**
     * 当前用户的背景图：返回 { url, opacity }，未设置时 null。
     *
     * @return array{url: string, opacity: int}|null
     */
    private function backgroundFor(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $bg = $this->preferences->appearancePrefs($userId)['background'];
        $url = (string) $bg['url'];

        if ($url === '' && (int) $bg['media_id'] > 0) {
            $row = $this->query->one(
                'SELECT disk_path, thumb_path FROM media WHERE id = :id AND user_id = :uid',
                ['id' => (int) $bg['media_id'], 'uid' => $userId],
            );

            if ($row !== null) {
                $url = Present::uploadUrl(
                    isset($row['thumb_path']) ? (string) $row['thumb_path'] : null,
                    isset($row['disk_path']) ? (string) $row['disk_path'] : null,
                );
            }
        }

        if ($url === '') {
            return null;
        }

        return ['url' => $url, 'opacity' => (int) $bg['opacity']];
    }
}
