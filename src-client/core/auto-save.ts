import { toast } from './toast';

/**
 * 设置项的「点一下就保存」。
 *
 * 侧栏/设置页里的单选按钮组带 data-auto-save：切换即提交，不再需要单独点保存、
 * 也不会整页刷新（redirect: 'manual' 下，成功保存的 302 表现为 opaqueredirect）。
 */
export function setupAutoSave(): void {
  document
    .querySelectorAll<HTMLFormElement>('form[data-auto-save]:not([data-auto-save-bound])')
    .forEach((form) => {
      form.dataset['autoSaveBound'] = '1';
      form.addEventListener('change', () => {
        void save(form);
      });
    });
}

async function save(form: HTMLFormElement): Promise<void> {
  const button = form.querySelector<HTMLButtonElement>('button[type="submit"]');

  try {
    const response = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      credentials: 'same-origin',
      redirect: 'manual',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (response.type === 'opaqueredirect' || response.ok) {
      toast('已保存', 'success');

      return;
    }

    toast('保存失败，请重试', 'info');
  } catch {
    toast('网络异常，请重试', 'info');
  } finally {
    if (button !== null) {
      button.disabled = false;
    }
  }
}
