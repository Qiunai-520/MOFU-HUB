import { $, el } from './dom';

let host: HTMLElement | null = null;

function ensureHost(): HTMLElement {
  if (host === null || !host.isConnected) {
    host = $('#toast-host');
  }

  if (host === null) {
    host = el('div', { class: 'toast-host', id: 'toast-host' });
    document.body.append(host);
  }

  return host;
}

export function toast(message: string, kind: 'info' | 'success' | 'error' = 'info'): void {
  const node = el('div', { class: `toast toast--${kind}`, role: 'status' }, [message]);

  ensureHost().append(node);

  requestAnimationFrame(() => node.classList.add('is-visible'));

  window.setTimeout(() => {
    node.classList.remove('is-visible');
    window.setTimeout(() => node.remove(), 300);
  }, kind === 'error' ? 4200 : 2600);
}

export function toastError(error: unknown): void {
  const message = error instanceof Error ? error.message : '操作失败，请稍后再试';
  toast(message, 'error');
}
