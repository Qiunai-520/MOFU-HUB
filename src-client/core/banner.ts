/**
 * 横幅轮播（首页公告/资讯）：
 * - 自动轮播 + 圆点切换 + 左右滑动；
 * - 悬停 / 聚焦 / 标签页隐藏时暂停；
 * - 尊重 prefers-reduced-motion（不自动播放，仅手动切换）。
 */
const INTERVAL_MS = 5000;
const SWIPE_THRESHOLD = 40;

export function setupBanner(root: HTMLElement): () => void {
  const track = root.querySelector<HTMLElement>('[data-banner-track]');
  const slides = Array.from(root.querySelectorAll<HTMLElement>('[data-banner-slide]'));
  const dotsHost = root.querySelector<HTMLElement>('[data-banner-dots]');

  if (track === null || slides.length === 0) {
    return () => undefined;
  }

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let index = 0;
  let timer: number | undefined;

  const dots = slides.map((_, i) => {
    const dot = document.createElement('button');
    dot.type = 'button';
    dot.className = 'banner__dot';
    dot.setAttribute('role', 'tab');
    dot.setAttribute('aria-label', `第 ${i + 1} 条`);
    dot.addEventListener('click', () => {
      go(i);
      restart();
    });

    return dot;
  });

  if (dotsHost !== null && slides.length > 1) {
    dotsHost.append(...dots);
  }

  const prevButton = root.querySelector<HTMLButtonElement>('[data-banner-prev]');
  const nextButton = root.querySelector<HTMLButtonElement>('[data-banner-next]');

  prevButton?.addEventListener('click', () => {
    go(index - 1);
    restart();
  });

  nextButton?.addEventListener('click', () => {
    go(index + 1);
    restart();
  });

  function go(next: number): void {
    index = (next + slides.length) % slides.length;
    track!.style.transform = `translateX(-${index * 100}%)`;

    dots.forEach((dot, i) => {
      dot.classList.toggle('is-active', i === index);
      dot.setAttribute('aria-selected', i === index ? 'true' : 'false');
    });
  }

  const stop = (): void => {
    window.clearInterval(timer);
    timer = undefined;
  };

  const start = (): void => {
    if (reduceMotion || slides.length < 2 || document.hidden) {
      return;
    }

    stop();
    timer = window.setInterval(() => go(index + 1), INTERVAL_MS);
  };

  const restart = (): void => {
    stop();
    start();
  };

  let dragging = false;
  let startX = 0;

  const onPointerDown = (event: PointerEvent): void => {
    dragging = true;
    startX = event.clientX;
    stop();
  };

  const onPointerUp = (event: PointerEvent): void => {
    if (!dragging) {
      return;
    }

    dragging = false;
    const dx = event.clientX - startX;

    if (Math.abs(dx) > SWIPE_THRESHOLD) {
      go(index + (dx < 0 ? 1 : -1));
    }

    restart();
  };

  const onVisibility = (): void => {
    if (document.hidden) {
      stop();
    } else {
      start();
    }
  };

  root.addEventListener('pointerdown', onPointerDown);
  root.addEventListener('pointerup', onPointerUp);
  root.addEventListener('pointercancel', () => {
    dragging = false;
    restart();
  });

  // 没配跳转链接的轮播（href="#"）不应把页面弹回顶部
  const onClick = (event: Event): void => {
    const slide = event.target instanceof Element ? event.target.closest('a[href="#"]') : null;

    if (slide !== null && root.contains(slide)) {
      event.preventDefault();
    }
  };

  root.addEventListener('click', onClick);
  root.addEventListener('mouseenter', stop);
  root.addEventListener('mouseleave', start);
  root.addEventListener('focusin', stop);
  root.addEventListener('focusout', start);
  document.addEventListener('visibilitychange', onVisibility);

  go(0);
  start();

  return () => {
    stop();
    root.removeEventListener('click', onClick);
    document.removeEventListener('visibilitychange', onVisibility);
  };
}
