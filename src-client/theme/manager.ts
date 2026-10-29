import type { ThemeConfig } from '@/types';
import { $theme } from '@/core/store';
import {
  BRAND_COLOR,
  isContrastLevel,
  isSchemeVariant,
  normalizeHex,
} from './generate';
import type { ColorSchemeMode, ThemeState } from './generate';

const GUEST_KEY = 'mofu.appearance';
const LEGACY_GUEST_KEY = 'mofu.theme.guest';

interface GuestAppearanceRecord {
  name: string;
  scheme: ColorSchemeMode;
}

export function configToState(config: ThemeConfig): ThemeState {
  return {
    // The brand accent is fixed; old saved source colours are ignored.
    sourceColor: BRAND_COLOR,
    variant: isSchemeVariant(config.variant) ? config.variant : 'tonalSpot',
    contrast: isContrastLevel(config.contrast) ? config.contrast : 'standard',
    scheme: (['auto', 'light', 'dark'] as const).includes(config.scheme) ? config.scheme : 'auto',
    density: 0,
    motion: 'standard',
  };
}

export function stateToConfig(state: ThemeState, name: string): ThemeConfig {
  return {
    name,
    sourceColor: BRAND_COLOR,
    scheme: state.scheme,
    contrast: 'standard',
    variant: 'tonalSpot',
    density: 0,
    motion: 'standard',
    isCustom: true,
  };
}

function systemIsDark(): boolean {
  return typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function syncThemeColor(state: ThemeState): void {
  const meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');

  if (meta === null) {
    return;
  }

  const dark = state.scheme === 'dark' || (state.scheme === 'auto' && systemIsDark());
  meta.setAttribute('content', dark ? '#000000' : '#F5F5F7');
}

/**
 * Apply an appearance without rebuilding the page. CSS `color-scheme`
 * drives every light-dark() token, so only one attribute changes.
 */
export function applyTheme(state: ThemeState): void {
  const root = document.documentElement;
  root.dataset.appearance = state.scheme;
  syncThemeColor(state);
}

/** Keep the browser chrome colour in sync after Swup replaces the <head>. */
export function syncAppearanceMeta(): void {
  const mode = document.documentElement.dataset.appearance;

  syncThemeColor({
    sourceColor: BRAND_COLOR,
    variant: 'tonalSpot',
    contrast: 'standard',
    scheme: mode === 'light' || mode === 'dark' ? mode : 'auto',
    density: 0,
    motion: 'standard',
  });
}

export function saveGuestTheme(state: ThemeState, name = '我的外观'): void {
  try {
    localStorage.setItem(GUEST_KEY, JSON.stringify({ name, scheme: state.scheme } satisfies GuestAppearanceRecord));
  } catch {
    // Storage may be unavailable (private mode); appearance still applies for this visit.
  }
}

export function loadGuestTheme(): { state: ThemeState; name: string } | null {
  try {
    const raw = localStorage.getItem(GUEST_KEY);

    if (raw !== null) {
      const decoded = JSON.parse(raw) as Partial<GuestAppearanceRecord>;

      if (decoded.scheme === 'light' || decoded.scheme === 'dark' || decoded.scheme === 'auto') {
        return {
          state: configToState({
            sourceColor: BRAND_COLOR,
            scheme: decoded.scheme,
            contrast: 'standard',
            variant: 'tonalSpot',
            density: 0,
            motion: 'standard',
          }),
          name: typeof decoded.name === 'string' && decoded.name.trim() !== '' ? decoded.name : '我的外观',
        };
      }
    }

    // Migrate only the appearance mode from the old HCT record. The old
    // source colour / generated CSS is deliberately discarded.
    const legacy = localStorage.getItem(LEGACY_GUEST_KEY);

    if (legacy !== null) {
      const decoded = JSON.parse(legacy) as { state?: { scheme?: string }; stateName?: string };
      const scheme = decoded.state?.scheme;

      if (scheme === 'light' || scheme === 'dark' || scheme === 'auto') {
        return {
          state: configToState({
            sourceColor: BRAND_COLOR,
            scheme,
            contrast: 'standard',
            variant: 'tonalSpot',
            density: 0,
            motion: 'standard',
          }),
          name: '我的外观',
        };
      }
    }
  } catch {
    // Malformed / inaccessible storage: fall back to the SSR appearance.
  }

  return null;
}

export function clearGuestTheme(): void {
  try {
    localStorage.removeItem(GUEST_KEY);
    localStorage.removeItem(LEGACY_GUEST_KEY);
  } catch {
    // Ignore.
  }
}

/**
 * Boot the appearance:
 * - logged-in users already have the SSR mode on <html>;
 * - guests may have a locally saved mode, which overrides it;
 * - meta theme-color follows the active/system appearance.
 */
export function initTheme(): void {
  const config = window.__MOFU__?.theme;

  if (!config) {
    return;
  }

  $theme.set(config);

  const user = window.__MOFU__?.user ?? null;
  const guest = user === null ? loadGuestTheme() : null;
  const state = guest?.state ?? configToState(config);

  applyTheme(state);

  if (guest !== null) {
    $theme.set(stateToConfig(guest.state, guest.name));
  }

  if (typeof window.matchMedia === 'function') {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (document.documentElement.dataset.appearance === 'auto') {
        syncThemeColor({ ...state, scheme: 'auto' });
      }
    });
  }
}

/** Normalise a user-entered/preset source colour, always returning the brand accent. */
export function brandColor(): string {
  return normalizeHex(BRAND_COLOR);
}
