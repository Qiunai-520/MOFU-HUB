/**
 * 通用对话框（M3 Dialog / 移动端底部面板）：
 * - `openDialog()` 返回被点击动作的 value（取消/遮罩/Esc → null）；
 * - 焦点管理：聚焦面板、Tab 在面板内循环、关闭还原触发元素；
 * - 移动端（≤600px）由样式自动转为底部面板形态（见 base.css .mofu-dialog）。
 */
interface DialogAction {
  label: string;
  value: string;
  style?: 'filled' | 'danger' | 'tonal' | 'text';
}

interface DialogConfig {
  title?: string;
  message?: string;
  actions: DialogAction[];
}

const FOCUSABLE = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

function actionClass(style: DialogAction['style']): string {
  switch (style) {
    case 'danger':
      return 'filled-btn filled-btn--sm filled-btn--danger';
    case 'tonal':
      return 'tonal-btn tonal-btn--sm';
    case 'text':
      return 'text-btn';
    default:
      return 'filled-btn filled-btn--sm';
  }
}

export function openDialog(config: DialogConfig): Promise<string | null> {
  return new Promise((resolve) => {
    const previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    const dialog = document.createElement('div');
    dialog.className = 'mofu-dialog';

    const panel = document.createElement('div');
    panel.className = 'mofu-dialog__panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.tabIndex = -1;

    let resolved = false;

    const finish = (value: string | null): void => {
      if (resolved) {
        return;
      }

      resolved = true;
      document.removeEventListener('keydown', onKeydown);
      dialog.remove();
      previouslyFocused?.focus();
      resolve(value);
    };

    if (config.title !== undefined && config.title !== '') {
      const title = document.createElement('h2');
      title.className = 'mofu-dialog__title';
      title.id = 'mofu-dialog-title';
      title.textContent = config.title;
      panel.setAttribute('aria-labelledby', title.id);
      panel.append(title);
    }

    if (config.message !== undefined && config.message !== '') {
      const text = document.createElement('p');
      text.className = 'mofu-dialog__text';
      text.textContent = config.message;
      panel.append(text);
    }

    const foot = document.createElement('div');
    foot.className = 'mofu-dialog__foot';

    for (const action of config.actions) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = actionClass(action.style);
      button.textContent = action.label;
      button.addEventListener('click', () => finish(action.value));
      foot.append(button);
    }

    panel.append(foot);
    dialog.append(panel);

    const onKeydown = (event: KeyboardEvent): void => {
      if (event.key === 'Escape') {
        finish(null);
        return;
      }

      if (event.key !== 'Tab') {
        return;
      }

      const focusables = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (element) => !element.hasAttribute('disabled'),
      );

      if (focusables.length === 0) {
        event.preventDefault();
        return;
      }

      const first = focusables[0];

      if (first === undefined) {
        return;
      }

      const last = focusables[focusables.length - 1] ?? first;
      const active = document.activeElement;

      if (event.shiftKey && (active === first || active === panel)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    };

    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        finish(null);
      }
    });

    document.addEventListener('keydown', onKeydown);
    document.body.append(dialog);
    panel.focus();
  });
}

/**
 * 确认对话框（替代 window.confirm）：返回是否确认。
 * 动作顺序保持「取消在前、确认在后」；dialog 模块异常时回退原生 confirm。
 */
export async function confirmDialog(
  message: string,
  options: { title?: string; danger?: boolean; confirmLabel?: string } = {},
): Promise<boolean> {
  try {
    const result = await openDialog({
      title: options.title ?? '确认操作',
      message,
      actions: [
        { label: '取消', value: 'cancel', style: 'text' },
        {
          label: options.confirmLabel ?? '确定',
          value: 'confirm',
          style: options.danger === true ? 'danger' : 'filled',
        },
      ],
    });

    return result === 'confirm';
  } catch {
    return window.confirm(message);
  }
}
