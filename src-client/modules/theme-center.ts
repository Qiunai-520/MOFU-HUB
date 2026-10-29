import { api } from '@/core/api';
import { confirmDialog } from '@/core/dialog';
import { $ } from '@/core/dom';
import { setButtonLoading } from '@/core/loading';
import { toast, toastError } from '@/core/toast';
import {
  applyTheme,
  clearGuestTheme,
  configToState,
  loadGuestTheme,
  saveGuestTheme,
} from '@/theme/manager';
import { BRAND_COLOR } from '@/theme/generate';
import type { ThemeState } from '@/theme/generate';
import type { ThemeConfig } from '@/types';
import type { PageModule } from './types';

interface SavedThemeRow {
  id: number;
  name: string;
  scheme: string;
  is_active: number;
}

const FALLBACK: ThemeConfig = {
  name: '默认外观',
  sourceColor: BRAND_COLOR,
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
    ...FALLBACK,
    name: row.name,
    scheme: row.scheme === 'light' || row.scheme === 'dark' ? row.scheme : 'auto',
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
  const guestAppearance = user === null ? loadGuestTheme() : null;
  const initialConfig = activeRow !== null ? rowToConfig(activeRow) : { ...defaultConfig, name: '默认外观' };

  let state: ThemeState = guestAppearance?.state ?? configToState(initialConfig);

  const options = Array.from(root.querySelectorAll<HTMLInputElement>('[data-appearance-option]'));
  const nameInput = $<HTMLInputElement>('[data-theme-name]', root);
  const previewName = $<HTMLElement>('[data-theme-preview-name]');

  if (nameInput !== null) {
    nameInput.value = guestAppearance?.name ?? activeRow?.name ?? '我的外观';
  }

  const updatePreviewName = (name: string): void => {
    if (previewName !== null) {
      previewName.textContent = name.trim() === '' ? '未命名外观' : name.trim();
    }
  };

  const fillControls = (): void => {
    for (const option of options) {
      option.checked = option.value === state.scheme;
    }

    if (nameInput !== null && nameInput.value.trim() === '') {
      nameInput.value = activeRow?.name ?? '我的外观';
    }

    updatePreviewName(nameInput?.value ?? activeRow?.name ?? '我的外观');
  };

  const apply = (): void => {
    applyTheme(state);
    fillControls();
  };

  const onModeChange = (event: Event): void => {
    const input = event.target as HTMLInputElement;

    if (input.matches('[data-appearance-option]')) {
      const value = input.value;

      if (value === 'light' || value === 'dark' || value === 'auto') {
        state = { ...state, scheme: value };
        apply();
      }
    }
  };

  const onNameInput = (): void => {
    updatePreviewName(nameInput?.value ?? '');
  };

  const save = async (button: HTMLElement | null): Promise<void> => {
    const name = (nameInput?.value ?? '').trim() || '我的外观';

    try {
      if (user === null) {
        saveGuestTheme(state, name);
        toast('外观已保存到本机', 'success');
        return;
      }

      setButtonLoading(button, true);

      await api.post('/api/v1/themes', {
        name,
        source_color: BRAND_COLOR,
        scheme: state.scheme,
        contrast: 'standard',
        variant: 'tonalSpot',
        density: 0,
        motion: 'standard',
        // Kept for API compatibility. Runtime appearance no longer consumes these.
        css_vars: {
          light: { '--md-sys-color-primary': BRAND_COLOR },
          dark: { '--md-sys-color-primary': BRAND_COLOR },
        },
      });

      toast('外观已保存', 'success');
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

    state = configToState({ ...defaultConfig, name: '默认外观' });
    apply();
    toast(user === null ? '已恢复为跟随系统' : '已恢复为跟随系统（保存后生效）', 'info');
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

    if (!(await confirmDialog('确定删除这套外观吗？', { danger: true, confirmLabel: '删除' }))) {
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
        reset();
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

  fillControls();
  apply();

  root.addEventListener('click', onClick);
  root.addEventListener('change', onModeChange);
  nameInput?.addEventListener('input', onNameInput);

  return () => {
    root.removeEventListener('click', onClick);
    root.removeEventListener('change', onModeChange);
    nameInput?.removeEventListener('input', onNameInput);
  };
};
