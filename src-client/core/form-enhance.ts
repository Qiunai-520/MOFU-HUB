import { $$ } from '@/core/dom';

/**
 * 表单增强：
 * - `[data-counter]`（配合 maxlength）：实时字数统计，接近上限时标红；
 * 幂等设计：可在每次页面切换后安全重复调用。
 */
export function setupFormEnhance(): void {
  for (const field of $$<HTMLInputElement | HTMLTextAreaElement>('[data-counter]')) {
    if (field.dataset.counterBound === '1') {
      continue;
    }

    field.dataset.counterBound = '1';

    const max = Number(field.getAttribute('maxlength') ?? '0');
    const counter = document.createElement('span');
    counter.className = 'field__counter';
    field.insertAdjacentElement('afterend', counter);

    const update = (): void => {
      const length = Array.from(field.value).length;
      counter.textContent = max > 0 ? `${length}/${max}` : String(length);
      counter.classList.toggle('is-warn', max > 0 && length >= max * 0.9);
    };

    field.addEventListener('input', update);
    update();
  }
}
