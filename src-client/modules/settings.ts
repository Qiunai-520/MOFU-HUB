import { $ } from '@/core/dom';
import type { PageModule } from './types';
import { $lang, setLang } from '@/core/i18n';

/**
 * 设置页模块路由：主题中心页加载主题控制器，背景页启用透明度滑杆实时预览。
 */
export const init: PageModule['init'] = async () => {
  const teardowns: Array<() => void> = [];

  // 主题中心只在外观页存在；不能提前 return，否则同页的语言选择器等绑定会被跳过
  if ($('[data-theme-center]') !== null) {
    const module = await import('./theme-center');
    const stop = await module.init();

    if (typeof stop === 'function') {
      teardowns.push(stop);
    }
  }

  const opacityPanel = $('[data-bg-opacity]') as HTMLElement | null;

  if (opacityPanel !== null) {
    const input = $('[data-bg-opacity-input]', opacityPanel) as HTMLInputElement | null;
    const output = $('[data-bg-opacity-value]', opacityPanel) as HTMLElement | null;

    if (input !== null && output !== null) {
      input.addEventListener('input', () => {
        output.textContent = `${input.value}%`;
      });
    }
  }

  // 头像框 / 昵称挂件：选择时同步顶部预览，保存前即可看到效果
  const decorForm = $<HTMLFormElement>('[data-decor-form]');

  if (decorForm !== null) {
    const previewFrame = $<HTMLElement>('[data-decor-frame]');
    const previewBadge = $<HTMLElement>('[data-decor-badge]');

    const syncPreview = (): void => {
      const frame = decorForm.querySelector<HTMLInputElement>('input[name="avatar_frame"]:checked');
      const badge = decorForm.querySelector<HTMLInputElement>('input[name="name_badge"]:checked');

      if (previewFrame !== null && frame !== null) {
        previewFrame.className = `avatar-frame avatar-frame--xl ${frame.dataset.frameClass ?? ''}`;
      }

      if (previewBadge !== null && badge !== null) {
        const image = badge.dataset.badgeImage ?? '';
        previewBadge.innerHTML = image !== ''
          ? `<img class="name-badge__image" src="${image}" alt="">`
          : (badge.dataset.badgeEmoji ?? '');
      }
    };

    decorForm.addEventListener('change', syncPreview);
    teardowns.push(() => decorForm.removeEventListener('change', syncPreview));
  }

  // 语言切换器
  const langSelect = $('[data-lang-select]') as HTMLSelectElement | null;
  if (langSelect !== null) {
    langSelect.value = $lang.get();
    langSelect.addEventListener('change', () => {
      setLang(langSelect.value);
    });
  }

  return () => {
    teardowns.forEach((fn) => fn());
  };
};
