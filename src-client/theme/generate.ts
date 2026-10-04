import {
  Hct,
  MaterialDynamicColors,
  SchemeContent,
  SchemeExpressive,
  SchemeFidelity,
  SchemeFruitSalad,
  SchemeMonochrome,
  SchemeNeutral,
  SchemeRainbow,
  SchemeTonalSpot,
  SchemeVibrant,
  argbFromHex,
  hexFromArgb,
} from '@material/material-color-utilities';
import type { DynamicColor, DynamicScheme } from '@material/material-color-utilities';

export type SchemeVariant =
  | 'tonalSpot'
  | 'vibrant'
  | 'expressive'
  | 'content'
  | 'fidelity'
  | 'neutral'
  | 'monochrome'
  | 'rainbow'
  | 'fruitSalad';

export type ContrastLevel = 'standard' | 'medium' | 'high';
export type ColorSchemeMode = 'auto' | 'light' | 'dark';
export type MotionMode = 'standard' | 'expressive';

export interface ThemeState {
  sourceColor: string;
  variant: SchemeVariant;
  contrast: ContrastLevel;
  scheme: ColorSchemeMode;
  density: number;
  motion: MotionMode;
}

export interface ThemeVars {
  light: Record<string, string>;
  dark: Record<string, string>;
}

const FONT_STACK = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "WenQuanYi Micro Hei", "Noto Sans CJK SC", "Source Han Sans SC", sans-serif';

type SchemeConstructor = new (sourceColorHct: Hct, isDark: boolean, contrastLevel: number) => DynamicScheme;

const VARIANTS: Record<SchemeVariant, SchemeConstructor> = {
  tonalSpot: SchemeTonalSpot,
  vibrant: SchemeVibrant,
  expressive: SchemeExpressive,
  content: SchemeContent,
  fidelity: SchemeFidelity,
  neutral: SchemeNeutral,
  monochrome: SchemeMonochrome,
  rainbow: SchemeRainbow,
  fruitSalad: SchemeFruitSalad,
};

const CONTRAST_LEVELS: Record<ContrastLevel, number> = {
  standard: 0,
  medium: 0.5,
  high: 1,
};

let cachedRoles: Array<[string, DynamicColor]> | null = null;

/** 枚举 M3 全部动态颜色角色（来自 material-color-utilities 的静态定义） */
function colorRoles(): Array<[string, DynamicColor]> {
  if (cachedRoles !== null) {
    return cachedRoles;
  }

  const roles: Array<[string, DynamicColor]> = [];

  for (const [name, value] of Object.entries(MaterialDynamicColors) as Array<[string, unknown]>) {
    const candidate = value as DynamicColor;
    if (candidate && typeof candidate.getArgb === 'function') {
      roles.push([name, candidate]);
    }
  }

  cachedRoles = roles;

  return roles;
}

function kebabCase(name: string): string {
  return name.replace(/[A-Z]/g, (match) => '-' + match.toLowerCase());
}

/** 规范化十六进制颜色 */
export function normalizeHex(input: string): string {
  let hex = input.trim();

  if (hex === '') {
    return '#775A0D';
  }

  if (!hex.startsWith('#')) {
    hex = '#' + hex;
  }

  if (/^#[0-9a-fA-F]{3}$/.test(hex)) {
    hex = '#' + hex.slice(1).split('').map((char) => char + char).join('');
  }

  if (!/^#[0-9a-fA-F]{6}$/.test(hex)) {
    return '#775A0D';
  }

  return hex.toUpperCase();
}

export function isSchemeVariant(value: string): value is SchemeVariant {
  return value in VARIANTS;
}

export function isContrastLevel(value: string): value is ContrastLevel {
  return value in CONTRAST_LEVELS;
}

/**
 * 由源色生成明暗两套 M3 颜色角色变量（--md-sys-color-*）。
 */
export function generateVars(state: ThemeState): ThemeVars {
  const hct = Hct.fromInt(argbFromHex(normalizeHex(state.sourceColor)));
  const contrast = CONTRAST_LEVELS[state.contrast] ?? 0;
  const SchemeClass = VARIANTS[state.variant] ?? SchemeTonalSpot;
  const roles = colorRoles();

  const build = (isDark: boolean): Record<string, string> => {
    const scheme = new SchemeClass(hct, isDark, contrast);
    const vars: Record<string, string> = {};

    for (const [name, color] of roles) {
      try {
        vars['--md-sys-color-' + kebabCase(name)] = hexFromArgb(color.getArgb(scheme));
      } catch {
        // 个别角色在该方案下不可用时跳过
      }
    }

    return vars;
  };

  return { light: build(false), dark: build(true) };
}

function cssRule(selector: string, body: Record<string, string>): string {
  const inner = Object.entries(body)
    .map(([key, value]) => `${key}:${value}`)
    .join(';');

  return inner === '' ? '' : `${selector}{${inner}}`;
}

/** 生成用于内联 <style> 的 CSS 文本 */
export function varsToCss(vars: ThemeVars, scheme: ColorSchemeMode, density: number): string {
  vars.light['--md-sys-font-family'] = FONT_STACK;
  vars.dark['--md-sys-font-family'] = FONT_STACK;

  let css = '';

  if (scheme === 'dark') {
    css += cssRule(':root', vars.dark);
  } else if (scheme === 'light') {
    css += cssRule(':root', vars.light);
  } else {
    css += cssRule(':root', vars.light);
    css += `@media (prefers-color-scheme: dark){${cssRule(':root', vars.dark)}}`;
  }

  if (density !== 0) {
    css += cssRule(':root', { '--md-sys-density-scale': String(density) });
  }

  return css;
}
