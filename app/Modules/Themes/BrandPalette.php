<?php

declare(strict_types=1);

namespace Mofu\Modules\Themes;

/**
 * 由主题源色推导品牌色阶与品牌语义令牌。
 *
 * 设计意图：色相取自源色，明度/饱和度沿用既定设计刻度——
 * 因此默认源色 #775A0D 会还原既定的品牌外观，而用户在主题中心改色时
 * 强调色随色相变化、暖调中性色保持固定（中性色不在本类里）。
 */
final class BrandPalette
{
    /** 浅色色阶刻度：name => [饱和度, 明度]；500 固定为源色本身（基准色不得擅改） */
    private const SCALE = [
        '50' => [60, 97],
        '100' => [58, 93],
        '200' => [52, 84],
        '300' => [50, 70],
        '400' => [62, 47],
        '500' => null,
        '600' => [82, 21],
        '700' => [80, 17],
        '800' => [76, 13],
        '900' => [72, 10],
    ];

    /** 深色模式衍生色刻度（单独设计，不是简单反转） */
    private const DARK = [
        'primary' => [75, 31],
        'hover' => [73, 35],
        'subtle' => [49, 15],
        'subtleStrong' => [58, 20],
        'muted' => [55, 10],
        'onSubtle' => [9, 83],
    ];

    /** 生成覆盖品牌令牌的 :root 样式块（应置于主样式之后） */
    public static function css(string $sourceHex): string
    {
        [$hue] = self::toHsl(self::normalize($sourceHex));
        $base = self::normalize($sourceHex);

        $step = static function (string $name) use ($hue, $base): string {
            $sl = self::SCALE[$name];

            return $sl === null ? $base : self::toHex($hue, $sl[0], $sl[1]);
        };

        $scale = [];
        foreach (array_keys(self::SCALE) as $name) {
            // PHP 会把纯数字字符串键转为 int，这里统一转回字符串
            $scale[(string) $name] = $step((string) $name);
        }

        $dark = [];
        foreach (self::DARK as $key => [$sat, $light]) {
            $dark[$key] = self::toHex($hue, $sat, $light);
        }

        $tokens = [
            '--brand-50' => $scale['50'],
            '--brand-100' => $scale['100'],
            '--brand-200' => $scale['200'],
            '--brand-300' => $scale['300'],
            '--brand-400' => $scale['400'],
            '--brand-500' => $scale['500'],
            '--brand-600' => $scale['600'],
            '--brand-700' => $scale['700'],
            '--brand-800' => $scale['800'],
            '--brand-900' => $scale['900'],
            '--color-brand-primary' => sprintf('light-dark(%s, %s)', $scale['500'], $dark['primary']),
            '--color-brand-hover' => sprintf('light-dark(%s, %s)', $scale['600'], $dark['hover']),
            '--color-brand-active' => sprintf('light-dark(%s, %s)', $scale['700'], $scale['500']),
            '--color-brand-subtle' => sprintf('light-dark(%s, %s)', $scale['100'], $dark['subtle']),
            '--color-brand-subtle-strong' => sprintf('light-dark(%s, %s)', $scale['200'], $dark['subtleStrong']),
            '--color-brand-muted' => sprintf('light-dark(%s, %s)', $scale['50'], $dark['muted']),
            '--color-brand-foreground' => sprintf('light-dark(%s, %s)', $scale['500'], $scale['300']),
            '--color-brand-on-subtle' => sprintf('light-dark(%s, %s)', $scale['500'], $dark['onSubtle']),
            '--color-brand-focus' => sprintf('light-dark(%s, %s)', $scale['500'], $scale['300']),
        ];

        $body = '';
        foreach ($tokens as $name => $value) {
            $body .= $name . ':' . $value . ';';
        }

        return ':root{' . $body . '}';
    }

    /** #RRGGBB（大写），非法输入回退到品牌基准色 */
    public static function normalize(string $hex): string
    {
        $hex = strtoupper(trim($hex));

        if (preg_match('/^[0-9A-F]{6}$/', $hex) === 1) {
            return '#' . $hex;
        }

        if (preg_match('/^#[0-9A-F]{6}$/', $hex) === 1) {
            return $hex;
        }

        return '#775A0D';
    }

    /** @return array{0: float, 1: float, 2: float} [H, S, L]，H 0-360、S/L 0-100 */
    private static function toHsl(string $hex): array
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;

        if ($d === 0.0) {
            return [0.0, 0.0, $l * 100];
        }

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        if ($max === $r) {
            $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
        } elseif ($max === $g) {
            $h = ($b - $r) / $d + 2;
        } else {
            $h = ($r - $g) / $d + 4;
        }

        return [$h * 60, $s * 100, $l * 100];
    }

    private static function toHex(float $h, float $s, float $l): string
    {
        $h = fmod($h, 360.0);
        $s /= 100;
        $l /= 100;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return sprintf(
            '#%02X%02X%02X',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
