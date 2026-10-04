import { api } from '@/core/api';
import { confirmDialog } from '@/core/dialog';
import { $ } from '@/core/dom';
import { setButtonLoading } from '@/core/loading';
import { toast, toastError } from '@/core/toast';
import { extractSourceColor } from '@/theme/extract';
import {
  applyTheme,
  clearGuestTheme,
  configToState,
  saveGuestTheme,
} from '@/theme/manager';
import { generateVars } from '@/theme/generate';
import type { ThemeState, ThemeVars } from '@/theme/generate';
import type { ThemeConfig } from '@/types';
import type { PageModule } from './types';

interface SavedThemeRow {
  id: number;
  name: string;
  source_color: string;
  scheme: string;
  contrast: string;
  variant: string;
  density: number;
  motion: string;
  is_active: number;
}

const FALLBACK: ThemeConfig = {
  name: '默认主题',
  sourceColor: '#775A0D',
  scheme: 'auto',
  contrast: 'standard',
  variant: 'tonalSpot',
  density: 0,
  motion: 'standard',
};

function parseJson<T>(raw: string): T | null {
  try {
    const decoded = raw.trim() === '' ? null : (JSON.parse(raw) as T);

    return decoded ?? null;
  } catch {
    return null;
  }
}

function rowToConfig(row: SavedThemeRow): ThemeConfig {
  return {
    name: row.name,
    sourceColor: row.source_color,
    scheme: (row.scheme === 'light' || row.scheme === 'dark' ? row.scheme : 'auto') as ThemeConfig['scheme'],
    contrast: (row.contrast === 'medium' || row.contrast === 'high' ? row.contrast : 'standard') as ThemeConfig['contrast'],
    variant: row.variant,
    density: Number(row.density ?? 0),
    motion: row.motion === 'expressive' ? 'expressive' : 'standard',
    isCustom: true,
  };
}

export const init: PageModule['init'] = (): (() => void) | void => {
  const root = $<HTMLElement>('[data-theme-center]');

  if (root === null) {
    return;
  }

  const user = window.__MOFU__?.user ?? null;

  const defaultConfig = parseJson<ThemeConfig>(root.dataset.defaultTheme ?? '') ?? FALLBACK;
  const activeRow = parseJson<SavedThemeRow>(root.dataset.activeTheme ?? '');
  const initialConfig: ThemeConfig = activeRow !== null ? rowToConfig(activeRow) : { ...defaultConfig, name: '默认主题' };

  let state: ThemeState = configToState(initialConfig);
  let previewVars: ThemeVars | null = null;

  const colorInput = $<HTMLInputElement>('[data-theme-color-input]', root);
  const colorValue = $<HTMLElement>('[data-theme-color-value]', root);
  const variantSelect = $<HTMLSelectElement>('[data-theme-variant]', root);
  const schemeSelect = $<HTMLSelectElement>('[data-theme-scheme]', root);
  const contrastSelect = $<HTMLSelectElement>('[data-theme-contrast]', root);
  const densitySelect = $<HTMLSelectElement>('[data-theme-density]', root);
  const motionSelect = $<HTMLSelectElement>('[data-theme-motion]', root);
  const nameInput = $<HTMLInputElement>('[data-theme-name]', root);
  const previewName = $<HTMLElement>('[data-theme-preview-name]');
  const wallpaperInput = $<HTMLInputElement>('[data-theme-wallpaper-input]', root);

  const updatePreviewName = (name: string): void => {
    if (previewName !== null) {
      previewName.textContent = name === '' ? '未命名主题' : name;
    }
  };

  const fillControls = (): void => {
    if (colorInput !== null) {
      colorInput.value = state.sourceColor;
    }
    if (colorValue !== null) {
      colorValue.textContent = state.sourceColor;
    }
    if (variantSelect !== null) {
      variantSelect.value = state.variant;
    }
    if (schemeSelect !== null) {
      schemeSelect.value = state.scheme;
    }
    if (contrastSelect !== null) {
      contrastSelect.value = state.contrast;
    }
    if (densitySelect !== null) {
      densitySelect.value = String(state.density);
    }
    if (motionSelect !== null) {
      motionSelect.value = state.motion;
    }
  };

  const refreshPreview = (): void => {
    previewVars = generateVars(state);
    applyTheme(state, previewVars);
    fillControls();
  };

  fillControls();
  refreshPreview();

  const setSourceColor = (color: string): void => {
    state = { ...state, sourceColor: color };
    refreshPreview();
  };

  const onColorInput = (): void => {
    if (colorInput !== null) {
      setSourceColor(colorInput.value.toUpperCase());
    }
  };

  const onPresetClick = (event: Event): void => {
    const preset = (event.target as HTMLElement).closest<HTMLElement>('[data-color]');

    if (preset !== null) {
      setSourceColor((preset.dataset.color ?? '').toUpperCase());
    }
  };

  const onVariantChange = (): void => {
    if (variantSelect !== null) {
      state = { ...state, variant: variantSelect.value as ThemeState['variant'] };
      refreshPreview();
    }
  };

  const onSchemeChange = (): void => {
    if (schemeSelect !== null) {
      state = { ...state, scheme: schemeSelect.value as ThemeState['scheme'] };
      refreshPreview();
    }
  };

  const onContrastChange = (): void => {
    if (contrastSelect !== null) {
      state = { ...state, contrast: contrastSelect.value as ThemeState['contrast'] };
      refreshPreview();
    }
  };

  const onDensityChange = (): void => {
    if (densitySelect !== null) {
      state = { ...state, density: Number(densitySelect.value) };
      refreshPreview();
    }
  };

  const onMotionChange = (): void => {
    if (motionSelect !== null) {
      state = { ...state, motion: motionSelect.value === 'expressive' ? 'expressive' : 'standard' };
      refreshPreview();
    }
  };

  const onWallpaperChange = async (): Promise<void> => {
    const file = wallpaperInput?.files?.[0];

    if (file === undefined) {
      return;
    }

    try {
      const color = await extractSourceColor(file);
      setSourceColor(color);
      toast(`已从图片提取主题色 ${color}`, 'success');
    } catch (error) {
      toastError(error);
    } finally {
      if (wallpaperInput !== null) {
        wallpaperInput.value = '';
      }
    }
  };

  const save = async (button: HTMLElement | null): Promise<void> => {
    const name = (nameInput?.value ?? '').trim() || '我的主题';
    const vars = previewVars ?? generateVars(state);

    try {
      if (user === null) {
        saveGuestTheme(state, vars);
        updatePreviewName(name);
        toast('已应用到本机（登录后可跨设备同步）', 'success');
        return;
      }

      setButtonLoading(button, true);

      await api.post('/api/v1/themes', {
        name,
        source_color: state.sourceColor,
        scheme: state.scheme,
        contrast: state.contrast,
        variant: state.variant,
        density: state.density,
        motion: state.motion,
        css_vars: vars,
      });

      toast('主题已保存并应用', 'success');
      window.location.assign('/settings/appearance?ok=1');
    } catch (error) {
      setButtonLoading(button, false);
      toastError(error);
    }
  };

  const reset = (): void => {
    if (user === null) {
      clearGuestTheme();
    }

    state = configToState({ ...defaultConfig, name: '默认主题' });
    updatePreviewName('默认主题');
    refreshPreview();
    toast('已恢复默认主题（点击保存后生效）', 'info');
  };

  const activate = async (themeId: number, button: HTMLElement | null): Promise<void> => {
    if (themeId <= 0) {
      return;
    }

    setButtonLoading(button, true);

    try {
      await api.post(`/api/v1/themes/${themeId}/activate`);
      window.location.assign('/settings/appearance?ok=1');
    } catch (error) {
      setButtonLoading(button, false);
      toastError(error);
    }
  };

  const removeTheme = async (themeId: number, button: HTMLElement | null): Promise<void> => {
    if (themeId <= 0) {
      return;
    }

    if (!(await confirmDialog('确定删除这套主题吗？', { danger: true, confirmLabel: '删除' }))) {
      return;
    }

    setButtonLoading(button, true);

    try {
      await api.delete(`/api/v1/themes/${themeId}`);
      window.location.assign('/settings/appearance?ok=1');
    } catch (error) {
      setButtonLoading(button, false);
      toastError(error);
    }
  };

  const onClick = (event: Event): void => {
    const trigger = (event.target as HTMLElement).closest<HTMLElement>('[data-action]');

    if (trigger === null) {
      return;
    }

    switch (trigger.dataset.action) {
      case 'save-theme':
        event.preventDefault();
        void save(trigger);
        break;

      case 'reset-theme':
        event.preventDefault();
        void reset();
        break;

      case 'activate-theme':
        event.preventDefault();
        void activate(Number(trigger.dataset.themeId ?? 0), trigger);
        break;

      case 'delete-theme':
        event.preventDefault();
        void removeTheme(Number(trigger.dataset.themeId ?? 0), trigger);
        break;
    }
  };

  root.addEventListener('click', onClick);
  root.addEventListener('click', onPresetClick);
  colorInput?.addEventListener('input', onColorInput);
  variantSelect?.addEventListener('change', onVariantChange);
  schemeSelect?.addEventListener('change', onSchemeChange);
  contrastSelect?.addEventListener('change', onContrastChange);
  densitySelect?.addEventListener('change', onDensityChange);
  motionSelect?.addEventListener('change', onMotionChange);
  wallpaperInput?.addEventListener('change', () => void onWallpaperChange());

  return () => {
    root.removeEventListener('click', onClick);
    root.removeEventListener('click', onPresetClick);
    colorInput?.removeEventListener('input', onColorInput);
    variantSelect?.removeEventListener('change', onVariantChange);
    schemeSelect?.removeEventListener('change', onSchemeChange);
    contrastSelect?.removeEventListener('change', onContrastChange);
    densitySelect?.removeEventListener('change', onDensityChange);
    motionSelect?.removeEventListener('change', onMotionChange);
  };
};
