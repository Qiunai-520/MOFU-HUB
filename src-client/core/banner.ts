/**
 * 横幅轮播（首页公告/资讯）：
 * - 自动轮播 + 圆点切换 + 左右滑动；
 * - 悬停 / 聚焦 / 标签页隐藏时暂停；
 * - 尊重 prefers-reduced-motion（不自动播放，仅手动切换）。
 */
const INTERVAL_MS = 5000;
const SWIPE_THRESHOLD = 40;

/**
 * 首页横幅是持久节点（data-swup-persist="home-banner"）：换页后同一个 DOM 会被复用。
 * 若每次 page:view 都重新初始化，圆点会被重复追加、自动轮播被打回第一张。
 * 所以每个节点只绑定一次（dataset 守卫），节点级监听随节点一起存活。
 * 文档级 visibilitychange 只绑一次，通过注册表统一调度所有还在页面上的横幅。
 */
const activeBanners = new Set<{ start: () => void; stop: () => void }>();
let visibilityBound = false;

function bindVisibility(): void {
  if (visibilityBound) {
    return;
  }

  visibilityBound = true;

  document.addEventListener('visibilitychange', () => {
    for (const banner of activeBanners) {
      if (document.hidden) {
        banner.stop();
      } else {
        banner.start();
      }
    }
  });
}

export function setupBanner(root: HTMLElement): () => void {
  if (root.dataset.bannerBound === '1') {
    return () => undefined;
  }

  root.dataset.bannerBound = '1';

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
    timer = window.setInterval(() => {
      // 横幅节点可能已经随换页被移除：停表并退出注册表，避免空跑
      if (!root.isConnected) {
        stop();
        activeBanners.delete(controller);

        return;
      }

      go(index + 1);
    }, INTERVAL_MS);
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

  const onCancel = (): void => {
    dragging = false;
    restart();
  };

  root.addEventListener('pointerdown', onPointerDown);
  root.addEventListener('pointerup', onPointerUp);
  root.addEventListener('pointercancel', onCancel);

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

  const controller = { start, stop };
  activeBanners.add(controller);
  bindVisibility();

  go(0);
  start();

  return () => {
    activeBanners.delete(controller);
    stop();
    root.removeEventListener('pointerdown', onPointerDown);
    root.removeEventListener('pointerup', onPointerUp);
    root.removeEventListener('pointercancel', onCancel);
    root.removeEventListener('click', onClick);
    root.removeEventListener('mouseenter', stop);
    root.removeEventListener('mouseleave', start);
    root.removeEventListener('focusin', stop);
    root.removeEventListener('focusout', start);
    delete root.dataset.bannerBound;
  };
}
