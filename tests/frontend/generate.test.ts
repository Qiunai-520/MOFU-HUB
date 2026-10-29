import { describe, expect, it } from 'vitest';
import { generateVars, normalizeHex, varsToCss } from '../../src-client/theme/generate';

describe('normalizeHex', () => {
  it('扩展三位十六进制', () => {
    expect(normalizeHex('#abc')).toBe('#AABBCC');
  });

  it('补全缺少的井号并大写', () => {
    expect(normalizeHex('ffcc38')).toBe('#FFCC38');
  });

  it('非法输入回退到固定品牌色', () => {
    expect(normalizeHex('not-a-color')).toBe('#FFCC38');
    expect(normalizeHex('')).toBe('#FFCC38');
  });
});

describe('generateVars', () => {
  const state = {
    sourceColor: '#FFCC38',
    variant: 'tonalSpot' as const,
    contrast: 'standard' as const,
    scheme: 'auto' as const,
    density: 0,
    motion: 'standard' as const,
  };

  it('明暗两套都使用固定品牌色', () => {
    const vars = generateVars(state);

    expect(vars.light['--md-sys-color-primary']).toBe('#FFCC38');
    expect(vars.dark['--md-sys-color-primary']).toBe('#FFCC38');
    expect(vars.light['--md-sys-color-primary-container']).toBe('#FFF3C4');
  });

  it('明暗两套的中性表面不同', () => {
    const vars = generateVars(state);

    expect(vars.light['--md-sys-color-surface']).not.toBe(vars.dark['--md-sys-color-surface']);
  });

  it('不再根据源色生成不同色板', () => {
    const a = generateVars(state);
    const b = generateVars({ ...state, sourceColor: '#B3261E' });

    expect(a).toEqual(b);
  });
});

describe('varsToCss', () => {
  const vars = generateVars();

  it('auto 方案输出跟随系统的 color-scheme', () => {
    expect(varsToCss(vars, 'auto', 0)).toContain('color-scheme:light dark');
  });

  it('light/dark 固定方案只输出一套 color-scheme', () => {
    expect(varsToCss(vars, 'light', 0)).toContain('color-scheme:light');
    expect(varsToCss(vars, 'dark', 0)).toContain('color-scheme:dark');
    expect(varsToCss(vars, 'light', 0)).not.toContain('color-scheme:dark');
  });
});
