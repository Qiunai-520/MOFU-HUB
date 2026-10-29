import { api } from '@/core/api';
import { $, $$ } from '@/core/dom';
import { setButtonLoading } from '@/core/loading';
import { toastError } from '@/core/toast';
import type { PageModule } from './types';

interface UploadResponse {
  id: number;
  url: string;
  thumb: string;
}

/** 一行「图片 + 若干输入框」编辑器的选择器契约（团队名单 / 首页轮播共用同一套逻辑） */
interface RowEditorConfig {
  /** 表单根 */
  formSelector: string;
  /** 行容器（新行追加到这里） */
  rowsSelector: string;
  /** <template> 里放着空行结构 */
  templateSelector: string;
  /** 单行 / 添加 / 删除 */
  rowSelector: string;
  addSelector: string;
  removeSelector: string;
  /** 行内图片：隐藏字段（存 URL）、文件选择、选择按钮、预览块 */
  mediaInputSelector: string;
  mediaFileSelector: string;
  mediaPickSelector: string;
  mediaPreviewSelector: string;
  /** 「添加」后聚焦的输入框 */
  focusSelector: string;
}

const TEAM_EDITOR: RowEditorConfig = {
  formSelector: '[data-team-form]',
  rowsSelector: '[data-team-rows]',
  templateSelector: '[data-team-template]',
  rowSelector: '[data-team-row]',
  addSelector: '[data-team-add]',
  removeSelector: '[data-team-remove]',
  mediaInputSelector: '[data-team-avatar-input]',
  mediaFileSelector: '[data-team-avatar-file]',
  mediaPickSelector: '[data-team-avatar-pick]',
  mediaPreviewSelector: '[data-team-avatar-preview]',
  focusSelector: 'input[name="member_name[]"]',
};

const BANNER_EDITOR: RowEditorConfig = {
  formSelector: '[data-banner-form]',
  rowsSelector: '[data-banner-rows]',
  templateSelector: '[data-banner-template]',
  rowSelector: '[data-banner-row]',
  addSelector: '[data-banner-add]',
  removeSelector: '[data-banner-remove]',
  mediaInputSelector: '[data-banner-image-input]',
  mediaFileSelector: '[data-banner-image-file]',
  mediaPickSelector: '[data-banner-image-pick]',
  mediaPreviewSelector: '[data-banner-image-preview]',
  focusSelector: 'input[name="banner_title[]"]',
};

/** 更新某一行的图片预览与隐藏字段 */
function applyImage(row: HTMLElement, config: RowEditorConfig, url: string): void {
  const input = $<HTMLInputElement>(config.mediaInputSelector, row);
  const preview = $<HTMLElement>(config.mediaPreviewSelector, row);

  if (input !== null) {
    input.value = url;
  }

  if (preview === null) {
    return;
  }

  if (url === '') {
    preview.style.backgroundImage = '';
    preview.classList.add('is-empty');
  } else {
    preview.style.backgroundImage = `url("${url}")`;
    preview.classList.remove('is-empty');
  }
}

async function uploadImage(
  row: HTMLElement,
  config: RowEditorConfig,
  file: File,
  button: HTMLElement | null,
): Promise<void> {
  const form = new FormData();
  form.append('file', file);

  if (button !== null) {
    setButtonLoading(button, true);
  }

  try {
    const { data } = await api.postForm<UploadResponse>('/api/v1/uploads', form);
    applyImage(row, config, data.thumb !== '' ? data.thumb : data.url);
  } catch (error) {
    toastError(error);
  } finally {
    if (button !== null) {
      setButtonLoading(button, false);
    }
  }
}

/**
 * 通用「按行增删 + 行内图片上传」编辑器。
 * 行内输入用并列数组提交（如 member_name[] / banner_title[]），服务端顺序读取，无需维护行号。
 */
function setupRowEditor(config: RowEditorConfig): (() => void) | void {
  const form = $<HTMLFormElement>(config.formSelector);

  if (form === null) {
    return;
  }

  const host = $<HTMLElement>(config.rowsSelector, form);
  const template = $<HTMLTemplateElement>(config.templateSelector, form);

  if (host === null || template === null) {
    return;
  }

  const rows = (): HTMLElement[] => Array.from(host.children).filter(
    (el): el is HTMLElement => el instanceof HTMLElement && el.matches(config.rowSelector),
  );

  const onClick = (event: Event): void => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    if (target.closest(config.addSelector) !== null) {
      const row = template.content.firstElementChild?.cloneNode(true);

      if (row instanceof HTMLElement) {
        host.append(row);
        row.querySelector<HTMLInputElement>(config.focusSelector)?.focus();
      }

      return;
    }

    const pick = target.closest(config.mediaPickSelector);

    if (pick !== null) {
      pick
        .closest<HTMLElement>(config.rowSelector)
        ?.querySelector<HTMLInputElement>(config.mediaFileSelector)
        ?.click();

      return;
    }

    const remove = target.closest(config.removeSelector);

    if (remove === null) {
      return;
    }

    const row = remove.closest<HTMLElement>(config.rowSelector);

    if (row === null) {
      return;
    }

    // 至少保留一行：最后一行改为清空，避免表单变空
    if (rows().length <= 1) {
      row.querySelectorAll('input').forEach((input) => {
        input.value = '';
      });
      applyImage(row, config, '');

      return;
    }

    row.remove();
  };

  const onChange = (event: Event): void => {
    const target = event.target;

    if (target instanceof HTMLSelectElement && target.name === 'banner_type[]') {
      target.closest<HTMLElement>('[data-banner-row]')?.classList.toggle('is-scene3d', target.value === 'scene3d');

      return;
    }

    if (!(target instanceof HTMLInputElement) || !target.matches(config.mediaFileSelector)) {
      return;
    }

    const file = target.files?.[0];
    const row = target.closest<HTMLElement>(config.rowSelector);

    if (file === undefined || row === null) {
      return;
    }

    void uploadImage(row, config, file, row.querySelector<HTMLElement>(config.mediaPickSelector));
    // 允许重复选择同一张图
    target.value = '';
  };

  form.addEventListener('click', onClick);
  form.addEventListener('change', onChange);

  return () => {
    form.removeEventListener('click', onClick);
    form.removeEventListener('change', onChange);
  };
}

/** 仪表盘数据入场：数字递增、折线描边、环形图按占比展开。 */
function setupDataVisuals(): (() => void) | void {
  const counts = $$<HTMLElement>('[data-admin-count]');
  const lines = $$<SVGPolylineElement>('.admin-chart__line');
  const rings = $$<SVGCircleElement>('.admin-chart__ring');

  if (counts.length === 0 && lines.length === 0 && rings.length === 0) {
    return;
  }

  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  const frames: number[] = [];

  counts.forEach((element, index) => {
    const target = Number(element.dataset.adminCount ?? '0');

    if (!Number.isFinite(target)) {
      return;
    }

    const startedAt = performance.now() + index * 70;
    const duration = 760;

    const tick = (now: number): void => {
      const progress = Math.min(1, Math.max(0, (now - startedAt) / duration));
      const eased = 1 - Math.pow(1 - progress, 3);
      element.textContent = String(Math.round(target * eased));

      if (progress < 1) {
        frames.push(window.requestAnimationFrame(tick));
      }
    };

    frames.push(window.requestAnimationFrame(tick));
  });

  frames.push(window.requestAnimationFrame(() => {
    lines.forEach((line, index) => {
      const length = line.getTotalLength();
      line.style.strokeDasharray = String(length);
      line.style.strokeDashoffset = String(length);
      line.style.transition = `stroke-dashoffset 1150ms cubic-bezier(.2,.8,.2,1) ${index * 110}ms`;

      frames.push(window.requestAnimationFrame(() => {
        line.style.strokeDashoffset = '0';
      }));
    });

    rings.forEach((ring, index) => {
      const target = ring.dataset.dash ?? '0 100';
      ring.style.strokeDasharray = '0 100';
      ring.style.transition = `stroke-dasharray 950ms cubic-bezier(.2,.8,.2,1) ${index * 90}ms`;

      frames.push(window.requestAnimationFrame(() => {
        ring.style.strokeDasharray = target;
      }));
    });
  }));

  return () => {
    frames.forEach((id) => window.cancelAnimationFrame(id));
    counts.forEach((element) => {
      element.textContent = element.dataset.adminCount ?? element.textContent;
    });
  };
}

/** 后台模块：团队名单与首页轮播的行编辑器（按页面存在的表单各自挂载） */
export const init: PageModule['init'] = () => {
  const teardowns = [setupRowEditor(TEAM_EDITOR), setupRowEditor(BANNER_EDITOR)].filter(
    (fn): fn is () => void => typeof fn === 'function',
  );
  const visuals = setupDataVisuals();

  if (typeof visuals === 'function') {
    teardowns.push(visuals);
  }

  if (teardowns.length === 0) {
    return;
  }

  return () => teardowns.forEach((fn) => fn());
};
