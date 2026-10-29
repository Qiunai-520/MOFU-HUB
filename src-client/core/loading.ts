const INDICATOR_CLASS = 'mofu-spinner';

/**
 * 按钮加载态：显示轻量原生加载指示器，隐藏原有文字/图标但保持按钮尺寸。
 */
export function setButtonLoading(element: HTMLElement | null, loading: boolean): void {
  if (element === null) {
    return;
  }

  if (loading) {
    if (element.querySelector(`.${INDICATOR_CLASS}`) === null) {
      const indicator = document.createElement('span');
      indicator.className = INDICATOR_CLASS;
      indicator.setAttribute('aria-hidden', 'true');
      element.append(indicator);
    }

    element.classList.add('is-loading');
    element.setAttribute('aria-busy', 'true');
  } else {
    element.classList.remove('is-loading');
    element.removeAttribute('aria-busy');
    element.querySelector(`.${INDICATOR_CLASS}`)?.remove();
  }
}

/**
 * 原生表单提交的全局加载反馈。
 * 需在 setupGlobalActions 之后注册：data-confirm 取消与模块拦截都会置 defaultPrevented。
 */
export function setupFormLoading(): void {
  document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) {
      return;
    }

    const form = event.target as HTMLFormElement;
    const submitter = (event as SubmitEvent).submitter;
    const button =
      submitter ?? form.querySelector<HTMLButtonElement>('button[type="submit"], button:not([type])');

    if (button instanceof HTMLButtonElement && !button.disabled) {
      setButtonLoading(button, true);
    }
  });
}
