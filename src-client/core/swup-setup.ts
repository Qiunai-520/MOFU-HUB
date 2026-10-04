import Swup from 'swup';
import SwupA11yPlugin from '@swup/a11y-plugin';
import SwupHeadPlugin from '@swup/head-plugin';
import SwupPreloadPlugin from '@swup/preload-plugin';
import SwupScrollPlugin from '@swup/scroll-plugin';

export interface SwupHandlers {
  onPageView: () => void;
}

/**
 * 初始化 Swup 平滑切页：
 * - 仅替换 #swup 主内容容器，应用外壳（导航/主题/通知角标）保持持久；
 * - head 资源保持持久（persistAssets），避免组件脚本重复加载；
 * - 滚动、无障碍焦点与预加载由官方插件处理。
 */
export function setupSwup(handlers: SwupHandlers): Swup {
  const swup = new Swup({
    containers: ['#swup'],
    plugins: [
      new SwupScrollPlugin({
        animateScroll: { betweenPages: false, samePageWithHash: false, samePage: false },
      }),
      new SwupHeadPlugin({ persistAssets: true, awaitAssets: true }),
      new SwupA11yPlugin(),
      new SwupPreloadPlugin(),
    ],
  });

  swup.hooks.on('visit:start', () => {
    // 标签栏发起的跳转：标记后只让内容区淡入（标签栏自身不动）
    if (document.documentElement.dataset.tabSwitch !== undefined) {
      delete document.documentElement.dataset.tabSwitch;
      document.documentElement.classList.add('is-tab-switch');
    }
  });

  swup.hooks.on('page:view', () => {
    handlers.onPageView();

    // 动画跑完再摘掉标记（动画期间必须保留，否则新内容不会淡入）
    if (document.documentElement.classList.contains('is-tab-switch')) {
      window.setTimeout(() => document.documentElement.classList.remove('is-tab-switch'), 400);
    }
  });

  for (const event of ['visit:end', 'visit:abort', 'visit:fail'] as const) {
    swup.hooks.on(event, () => {
      delete document.documentElement.dataset.tabSwitch;
    });
  }

  // 暴露给导航模块，用于滑块拖动后以 SPA 方式跳转
  (window as unknown as { __mofuSwup?: Swup }).__mofuSwup = swup;

  setupPageLoading(swup);

  return swup;
}

/** 页面切换加载指示：延迟显示，避免快速/预加载导航闪烁。 */
function setupPageLoading(swup: Swup): void {
  const element = document.querySelector<HTMLElement>('[data-page-loading]');

  if (element === null) {
    return;
  }

  let showTimer: number | undefined;

  const hide = (): void => {
    window.clearTimeout(showTimer);
    showTimer = undefined;
    element.setAttribute('hidden', '');
  };

  swup.hooks.on('visit:start', () => {
    window.clearTimeout(showTimer);
    // 延迟拉长：只要不是真正很慢就不闪加载条（swup 自己有进场动画，加载条反而像刷新）
    showTimer = window.setTimeout(() => element.removeAttribute('hidden'), 1000);
  });

  swup.hooks.on('visit:end', hide);
  swup.hooks.on('visit:abort', hide);
  swup.hooks.on('visit:fail', hide);
}
