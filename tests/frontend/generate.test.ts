import { describe, expect, it } from 'vitest';
import { generateVars, normalizeHex, varsToCss } from '../../src-client/theme/generate';

describe('normalizeHex', () => {
  it('扩展三位十六进制', () => {
    expect(normalizeHex('#abc')).toBe('#AABBCC');
  });

  it('补全缺少的井号并大写', () => {
    expect(normalizeHex('775a0d')).toBe('#775A0D');
  });

  it('非法输入回退到品牌默认色', () => {
    expect(normalizeHex('not-a-color')).toBe('#775A0D');
    expect(normalizeHex('')).toBe('#775A0D');
  });
});

describe('generateVars', () => {
  const state = {
    sourceColor: '#775A0D',
    variant: 'tonalSpot' as const,
    contrast: 'standard' as const,
    scheme: 'auto' as const,
    density: 0,
    motion: 'standard' as const,
  };

  it('生成完整的明暗两套颜色角色', () => {
    const vars = generateVars(state);

    expect(Object.keys(vars.light).length).toBeGreaterThan(30);
    expect(Object.keys(vars.dark).length).toBeGreaterThan(30);
    expect(vars.light['--md-sys-color-primary']).toMatch(/^#[0-9a-fA-F]{6}$/);
    expect(vars.dark['--md-sys-color-surface']).toMatch(/^#[0-9a-fA-F]{6}$/);
  });

  it('同一源色生成结果稳定（可复现）', () => {
    const a = generateVars(state);
    const b = generateVars(state);

    expect(a.light['--md-sys-color-primary']).toBe(b.light['--md-sys-color-primary']);
    expect(a.dark['--md-sys-color-primary']).toBe(b.dark['--md-sys-color-primary']);
  });

  it('明暗两套主题的 primary 不同', () => {
    const vars = generateVars(state);

    expect(vars.light['--md-sys-color-primary']).not.toBe(vars.dark['--md-sys-color-primary']);
  });

  it('不同源色产生不同色板', () => {
    const blue = generateVars(state);
    const red = generateVars({ ...state, sourceColor: '#B3261E' });

    expect(blue.light['--md-sys-color-primary']).not.toBe(red.light['--md-sys-color-primary']);
  });
});

describe('varsToCss', () => {
  const vars = {
    light: { '--md-sys-color-primary': '#111111' },
    dark: { '--md-sys-color-primary': '#eeeeee' },
  };

  it('auto 方案输出媒体查询', () => {
    const css = varsToCss(vars, 'auto', 0);

    expect(css).toContain('--md-sys-color-primary:#111111');
    expect(css).toContain('@media (prefers-color-scheme: dark)');
    expect(css).toContain('#eeeeee');
  });

  it('light/dark 固定方案只输出一套', () => {
    const lightCss = varsToCss(vars, 'light', 0);
    const darkCss = varsToCss(vars, 'dark', 0);

    expect(lightCss).toContain('#111111');
    expect(lightCss).not.toContain('#eeeeee');
    expect(darkCss).toContain('#eeeeee');
    expect(darkCss).not.toContain('#111111');
  });

  it('密度写入密度变量', () => {
    const css = varsToCss(vars, 'auto', -1);

    expect(css).toContain('--md-sys-density-scale:-1');
  });
});
