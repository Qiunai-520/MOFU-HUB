<?php

declare(strict_types=1);

namespace Mofu\Modules\Themes;

/**
 * 主题变量的校验与 CSS 生成（服务端渲染首帧主题用）。
 */
final class ThemeVars
{
    public const NAME_PATTERN = '/^--md-sys-[a-z0-9-]{1,60}$/';
    public const VALUE_PATTERN = '/^(#[0-9a-fA-F]{3,8}|rgba?\(\s*[0-9.,%\s]+\)|-?[0-9]+(\.[0-9]+)?)$/';

    private const MAX_VARS = 600;
    private const FONT_STACK = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "WenQuanYi Micro Hei", "Noto Sans CJK SC", "Source Han Sans SC", sans-serif';

    /**
     * 过滤为安全的 --md-sys-* 变量集合。
     *
     * @param array<mixed> $vars
     * @return array<string, string>
     */
    public static function sanitize(array $vars): array
    {
        $out = [];

        foreach ($vars as $key => $value) {
            if (count($out) >= self::MAX_VARS) {
                break;
            }

            if (!is_string($key) || !preg_match(self::NAME_PATTERN, $key)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '' || mb_strlen($value) > 80 || !preg_match(self::VALUE_PATTERN, $value)) {
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * 由 light/dark 两套变量与方案生成内联 CSS。
     * 结构：{"light": {...}, "dark": {...}}
     *
     * @param array<mixed> $vars
     */
    public static function toCss(array $vars, string $scheme, int $density): string
    {
        $light = self::sanitize(is_array($vars['light'] ?? null) ? $vars['light'] : []);
        $dark = self::sanitize(is_array($vars['dark'] ?? null) ? $vars['dark'] : []);

        $light['--md-sys-font-family'] = self::FONT_STACK;
        $dark['--md-sys-font-family'] = self::FONT_STACK;

        $css = '';

        if ($scheme === 'dark') {
            if ($dark !== []) {
                $css .= self::rule(':root', $dark);
            } elseif ($light !== []) {
                $css .= self::rule(':root', $light);
            }
        } elseif ($scheme === 'light') {
            if ($light !== []) {
                $css .= self::rule(':root', $light);
            }
        } else {
            if ($light !== []) {
                $css .= self::rule(':root', $light);
            }
            if ($dark !== []) {
                $css .= '@media (prefers-color-scheme: dark){' . self::rule(':root', $dark) . '}';
            }
        }

        if ($density !== 0) {
            $css .= self::rule(':root', ['--md-sys-density-scale' => (string) $density]);
        }

        return $css;
    }

    /** @param array<string, string> $vars */
    private static function rule(string $selector, array $vars): string
    {
        if ($vars === []) {
            return '';
        }

        $body = '';

        foreach ($vars as $name => $value) {
            $body .= $name . ':' . $value . ';';
        }

        return $selector . '{' . $body . '}';
    }
}
