/**
 * 全站悬浮音乐播放器。
 *
 * iframe 位于 #swup 之外，首次打开后才加载；收起时只做视觉隐藏，不销毁 iframe，
 * 因此 Swup 换页时音乐不会被打断。
 */
let bound = false;

export function setupMusicDock(): void {
  if (bound) {
    return;
  }

  const root = document.querySelector<HTMLElement>('[data-music-dock]');
  const panel = root?.querySelector<HTMLElement>('[data-music-panel]');
  const frame = root?.querySelector<HTMLIFrameElement>('[data-music-frame]');
  const toggle = document.querySelector<HTMLButtonElement>('[data-action="toggle-music"]');
  const close = root?.querySelector<HTMLButtonElement>('[data-action="close-music"]');

  if (root === null || panel === null || panel === undefined || frame === null || frame === undefined || toggle === null) {
    return;
  }

  bound = true;
  let opened = false;

  const setOpen = (open: boolean): void => {
    opened = open;
    root.classList.toggle('is-open', open);
    panel.classList.toggle('is-open', open);
    toggle.classList.toggle('is-open', open);
    panel.setAttribute('aria-hidden', open ? 'false' : 'true');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.inert = !open;

    if (open && frame.src === '') {
      frame.src = frame.dataset.src ?? '';
    }
  };

  toggle.addEventListener('click', () => setOpen(!opened));
  close?.addEventListener('click', () => setOpen(false));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && opened) {
      setOpen(false);
      toggle.focus();
    }
  });

  setOpen(false);
}
