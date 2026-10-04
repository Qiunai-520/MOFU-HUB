import type { ThemeConfig } from '@/types';
import { $theme } from '@/core/store';
import {
  generateVars,
  isContrastLevel,
  isSchemeVariant,
  normalizeHex,
  varsToCss,
} from './generate';
import type { ColorSchemeMode, MotionMode, ThemeState, ThemeVars } from './generate';

const GUEST_KEY = 'mofu.theme.guest';

interface GuestThemeRecord {
  state: ThemeState;
  vars: ThemeVars;
  /** 预计算的 CSS：<head> 内联脚本在首帧直接应用它，避免刷新闪白 */
  css: string;
}

export function configToState(config: ThemeConfig): ThemeState {
  return {
    sourceColor: normalizeHex(config.sourceColor),
    variant: isSchemeVariant(config.variant) ? config.variant : 'tonalSpot',
    contrast: isContrastLevel(config.contrast) ? config.contrast : 'standard',
    scheme: (['auto', 'light', 'dark'] as const).includes(config.scheme) ? config.scheme : 'auto',
    density: Math.max(-3, Math.min(1, Number(config.density) || 0)),
    motion: (config.motion === 'expressive' ? 'expressive' : 'standard') as MotionMode,
  };
}

export function stateToConfig(state: ThemeState, name: string): ThemeConfig {
  return {
    name,
    sourceColor: state.sourceColor,
    scheme: state.scheme,
    contrast: state.contrast,
    variant: state.variant,
    density: state.density,
    motion: state.motion,
    isCustom: true,
  };
}

/**
 * 应用主题：更新内联变量、m3e-theme 属性与 meta 主题色。
 */
export function applyTheme(state: ThemeState, vars?: ThemeVars): ThemeVars {
  const resolved = vars ?? generateVars(state);

  const styleEl = document.getElementById('theme-vars');
  if (styleEl) {
    styleEl.textContent = varsToCss(resolved, state.scheme, state.density);
  }

  const themeEl = document.getElementById('app-theme');
  if (themeEl) {
    themeEl.setAttribute('color', state.sourceColor);
    themeEl.setAttribute('scheme', state.scheme);
    themeEl.setAttribute('contrast', state.contrast);
    themeEl.setAttribute('density', String(state.density));
    themeEl.setAttribute('motion', state.motion);
  }

  const meta = document.querySelector('meta[name="theme-color"]');
  const surface = resolved.light['--md-sys-color-surface'];
  if (meta && surface) {
    meta.setAttribute('content', surface);
  }

  return resolved;
}

export function saveGuestTheme(state: ThemeState, vars: ThemeVars): void {
  try {
    localStorage.setItem(
      GUEST_KEY,
      JSON.stringify({
        state,
        vars,
        css: varsToCss(vars, state.scheme, state.density),
      } satisfies GuestThemeRecord),
    );
  } catch {
    // 存储不可用时静默忽略
  }
}

export function loadGuestTheme(): GuestThemeRecord | null {
  try {
    const raw = localStorage.getItem(GUEST_KEY);
    if (raw === null) {
      return null;
    }

    const decoded = JSON.parse(raw) as Partial<GuestThemeRecord>;

    if (decoded && decoded.state && decoded.vars) {
      const css =
        typeof decoded.css === 'string' && decoded.css !== ''
          ? decoded.css
          : varsToCss(decoded.vars as ThemeVars, decoded.state.scheme, decoded.state.density);

      // 旧记录缺少 css 字段：补写回去，下次刷新首帧即可直接应用
      if (decoded.css !== css) {
        saveGuestTheme(decoded.state, decoded.vars);
      }

      return { state: decoded.state, vars: decoded.vars, css };
    }
  } catch {
    // 解析失败按无主题处理
  }

  return null;
}

export function clearGuestTheme(): void {
  try {
    localStorage.removeItem(GUEST_KEY);
  } catch {
    // 忽略
  }
}

/**
 * 初始化主题引擎：
 * - 游客：优先应用本地保存的主题（head 内联脚本已提前应用 CSS，这里同步组件属性）；
 * - 登录用户：SSR 已内联用户主题，无需重复计算。
 */
export function initTheme(): void {
  const config = window.__MOFU__?.theme;
  if (!config) {
    return;
  }

  $theme.set(config);

  const user = window.__MOFU__?.user ?? null;

  if (user === null) {
    const guest = loadGuestTheme();

    if (guest !== null) {
      applyTheme(guest.state, guest.vars);
      $theme.set(stateToConfig(guest.state, '我的主题'));
    }
  }
}
