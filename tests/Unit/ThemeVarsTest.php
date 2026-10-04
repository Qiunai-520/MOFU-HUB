<?php

declare(strict_types=1);

namespace Mofu\Tests\Unit;

use Mofu\Modules\Themes\ThemeVars;
use PHPUnit\Framework\TestCase;

final class ThemeVarsTest extends TestCase
{
    public function testSanitizeKeepsValidVariables(): void
    {
        $vars = ThemeVars::sanitize([
            '--md-sys-color-primary' => '#6750A4',
            '--md-sys-color-surface' => 'rgb(255, 255, 255)',
            '--md-sys-density-scale' => '-1',
        ]);

        self::assertCount(3, $vars);
        self::assertSame('#6750A4', $vars['--md-sys-color-primary']);
        self::assertSame('-1', $vars['--md-sys-density-scale']);
    }

    public function testSanitizeRejectsInjectionAttempts(): void
    {
        $vars = ThemeVars::sanitize([
            '--md-sys-color-primary' => 'red; } body { display:none } .x{',
            '--md-sys-color-primary;}' => '#fff',
            '--other-var' => '#fff',
            'position' => 'fixed',
            '--md-sys-color-ok' => 'url(javascript:alert(1))',
        ]);

        self::assertSame([], $vars);
    }

    public function testSanitizeTrimsValues(): void
    {
        $vars = ThemeVars::sanitize(['--md-sys-color-primary' => ' #AABBCC ']);

        self::assertSame('#AABBCC', $vars['--md-sys-color-primary']);
    }

    public function testToCssAutoScheme(): void
    {
        $css = ThemeVars::toCss([
            'light' => ['--md-sys-color-primary' => '#111111'],
            'dark' => ['--md-sys-color-primary' => '#eeeeee'],
        ], 'auto', 0);

        self::assertStringContainsString('--md-sys-color-primary:#111111', $css);
        self::assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
        self::assertStringContainsString('#eeeeee', $css);
    }

    public function testToCssDarkSchemeOnlyDark(): void
    {
        $css = ThemeVars::toCss([
            'light' => ['--md-sys-color-primary' => '#111111'],
            'dark' => ['--md-sys-color-primary' => '#eeeeee'],
        ], 'dark', 0);

        self::assertStringContainsString('#eeeeee', $css);
        self::assertStringNotContainsString('#111111', $css);
    }

    public function testToCssDensity(): void
    {
        $css = ThemeVars::toCss(['light' => ['--md-sys-color-primary' => '#111111']], 'light', -2);

        self::assertStringContainsString('--md-sys-density-scale:-2', $css);
    }

    public function testToCssDropsInvalidVariables(): void
    {
        $css = ThemeVars::toCss([
            'light' => [
                '--md-sys-color-primary' => '#111111',
                '--evil' => 'expression(alert(1))',
            ],
            'dark' => [],
        ], 'light', 0);

        self::assertStringContainsString('#111111', $css);
        self::assertStringNotContainsString('expression', $css);
    }
}
