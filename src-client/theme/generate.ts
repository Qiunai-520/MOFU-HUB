/**
 * Appearance state shared by the theme settings page and the boot script.
 *
 * Mofukey's brand accent is fixed (#FFCC38), so the old HCT palette
 * generator is intentionally gone. The only user-controlled dimension
 * is light / dark / system appearance.
 */

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

export const BRAND_COLOR = '#FFCC38';
export const BRAND_ON_COLOR = '#241D0B';

/** Normalise a hex colour; invalid input falls back to the fixed brand accent. */
export function normalizeHex(input: string): string {
  let hex = input.trim();

  if (hex === '') {
    return BRAND_COLOR;
  }

  if (!hex.startsWith('#')) {
    hex = '#' + hex;
  }

  if (/^#[0-9a-fA-F]{3}$/.test(hex)) {
    hex = '#' + hex.slice(1).split('').map((char) => char + char).join('');
  }

  if (!/^#[0-9a-fA-F]{6}$/.test(hex)) {
    return BRAND_COLOR;
  }

  return hex.toUpperCase();
}

export function isSchemeVariant(value: string): value is SchemeVariant {
  return [
    'tonalSpot', 'vibrant', 'expressive', 'content', 'fidelity',
    'neutral', 'monochrome', 'rainbow', 'fruitSalad',
  ].includes(value);
}

export function isContrastLevel(value: string): value is ContrastLevel {
  return value === 'standard' || value === 'medium' || value === 'high';
}

/**
 * Kept for compatibility with callers/tests. The palette is no longer
 * derived from a source colour: every appearance uses the same fixed
 * Mofukey tokens, with only the neutral surfaces changing.
 */
export function generateVars(_state?: ThemeState): ThemeVars {
  const shared = {
    '--md-sys-color-primary': BRAND_COLOR,
    '--md-sys-color-on-primary': BRAND_ON_COLOR,
    '--md-sys-color-primary-container': '#FFF3C4',
    '--md-sys-color-on-primary-container': '#5C4300',
  };

  return {
    light: { ...shared, '--md-sys-color-surface': '#FFFFFF', '--md-sys-color-on-surface': '#1D1D1F' },
    dark: { ...shared, '--md-sys-color-surface': '#1C1C1E', '--md-sys-color-on-surface': '#F5F5F7' },
  };
}

function cssRule(selector: string, body: Record<string, string>): string {
  const inner = Object.entries(body)
    .map(([key, value]) => `${key}:${value}`)
    .join(';');

  return inner === '' ? '' : `${selector}{${inner}}`;
}

/** CSS that fixes the appearance mode at first paint. */
export function varsToCss(_vars: ThemeVars, scheme: ColorSchemeMode, _density = 0): string {
  if (scheme === 'dark') {
    return cssRule(':root', { 'color-scheme': 'dark' });
  }

  if (scheme === 'light') {
    return cssRule(':root', { 'color-scheme': 'light' });
  }

  return cssRule(':root', { 'color-scheme': 'light dark' });
}
