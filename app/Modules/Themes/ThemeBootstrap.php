<?php

declare(strict_types=1);

namespace Mofu\Modules\Themes;

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Query;
use Mofu\Core\View\Present;
use Mofu\Modules\Settings\PreferenceService;
use Mofu\Modules\Settings\SettingsService;

/**
 * 组装页面所需的主题数据：
 * - themeCss：首帧内联样式（登录用户主题 > 全站默认主题 > 空）；
 * - themeConfig：客户端主题引擎初始化参数；
 * - background：用户背景图偏好（url + opacity），未设置时为 null。
 */
final class ThemeBootstrap
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ThemeService $themes,
        private readonly PreferenceService $preferences,
        private readonly Query $query,
        private readonly Config $config,
    ) {
    }

    /** @return array{themeCss: string, themeConfig: array<string, mixed>} */
    public function chrome(?int $userId): array
    {
        $scheme = 'auto';
        $density = 0;
        $vars = [];

        $userTheme = $userId !== null ? $this->themes->activeFor($userId) : null;

        if ($userTheme !== null) {
            $decoded = json_decode((string) $userTheme['css_vars'], true);
            $vars = is_array($decoded) ? $decoded : [];
            $scheme = (string) $userTheme['scheme'];
            $density = (int) $userTheme['density'];
            $configData = [
                'name' => (string) $userTheme['name'],
                'sourceColor' => (string) $userTheme['source_color'],
                'scheme' => $scheme,
                'contrast' => (string) $userTheme['contrast'],
                'variant' => (string) $userTheme['variant'],
                'density' => $density,
                'motion' => (string) $userTheme['motion'],
                'isCustom' => true,
            ];
        } else {
            $default = $this->settings->defaultTheme();
            $scheme = 'auto';
            $density = (int) ($default['density'] ?? 0);

            $stored = $this->settings->get('site.default_theme_vars');
            if ($stored !== null) {
                $decoded = json_decode($stored, true);
                $vars = is_array($decoded) ? $decoded : [];
            }

            if ($vars === []) {
                $file = $this->config->rootPath('public/assets/theme-default.json');
                if (is_file($file)) {
                    $decoded = json_decode((string) file_get_contents($file), true);
                    $vars = is_array($decoded) ? $decoded : [];
                }
            }

            $configData = [
                'name' => '默认主题',
                'sourceColor' => (string) ($default['sourceColor'] ?? '#775A0D'),
                'scheme' => 'auto',
                'contrast' => (string) ($default['contrast'] ?? 'standard'),
                'variant' => (string) ($default['variant'] ?? 'tonalSpot'),
                'density' => $density,
                'motion' => (string) ($default['motion'] ?? 'standard'),
                'isCustom' => false,
            ];
        }

        return [
            'themeCss' => ThemeVars::toCss($vars, $scheme, $density),
            'themeConfig' => $configData,
            'background' => $this->backgroundFor($userId),
            // 品牌令牌由源色推导（默认源色还原既定品牌外观，改色时强调色随动）
            'brandCss' => BrandPalette::css((string) ($configData['sourceColor'] ?? '#775A0D')),
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
