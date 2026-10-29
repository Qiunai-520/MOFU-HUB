import Swup from 'swup';
import SwupA11yPlugin from '@swup/a11y-plugin';
import SwupHeadPlugin from '@swup/head-plugin';
import SwupPreloadPlugin from '@swup/preload-plugin';
import SwupScrollPlugin from '@swup/scroll-plugin';
import { beginTabSwitch, cancelTabSwitch, endTabSwitch } from './tab-view';

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

  swup.hooks.on('link:click', (visit, { el }) => {
    try {
      if (isTabLikeNavigation(new URL(window.location.href), new URL(el.href, window.location.href))) {
        // 用 replace 而不是 push：切换设置页/后台标签、换筛选排序时
        // 不往历史里塞新条目，否则手机返回键会一直在同一区块内弹。
        visit.history.action = 'replace';
      }
    } catch {
      // URL 异常时保持默认行为
    }
  }, { before: true });

  /*
   * 页面切换契约（与 core/tab-view.ts 配套）：
   * - `#swup` 是唯一替换容器，外层 Header / 侧栏 / 底栏 / 悬浮组件天然持久；
   * - 容器内部需要跨页保持的节点用 data-swup-persist 标记（导航、筛选、侧栏等）；
   * - 随 tab 变化的内容放在 [data-tabs-panel] 内，过渡只作用于该面板；
   * - 标签栏发起的跳转由 tab-view 置位 is-tab-switch，并只在内容区显示骨架。
   * URL、浏览历史、滚动与缓存由 Swup（含 preload 插件）负责，不做整页刷新。
   */

  swup.hooks.on('visit:start', () => {
    beginTabSwitch();
  });

  swup.hooks.on('page:view', (visit) => {
    // SwupHeadPlugin intentionally leaves <title> alone. Without this, every
    // client-side navigation keeps the first page title in tabs and history.
    const nextTitle = visit.to.document?.title.trim() ?? '';

    if (nextTitle !== '') {
      document.title = nextTitle;
    }

    endTabSwitch();
    handlers.onPageView();
  });

  for (const event of ['visit:abort', 'visit:fail'] as const) {
    swup.hooks.on(event, cancelTabSwitch);
  }

  // 暴露给导航模块，用于滑块拖动后以 SPA 方式跳转
  (window as unknown as { __mofuSwup?: Swup }).__mofuSwup = swup;

  setupPageLoading(swup);

  return swup;
}

/**
 * 顶级内容页（首页除外）。这些页面之间「横着跳」属于同一层级的切换，
 * 不该往历史里压条目 —— 否则手机返回键会一路倒着退（表白墙 → 树洞 → 圈子 …），回不到主页。
 */
const SECTION_PAGES = [
  '/wall', '/hole', '/circles', '/boards', '/events', '/news',
  '/me', '/messages', '/notifications', '/music',
];

/** 路径层级：/boards = 1，/boards/forum = 2 */
function pathLevel(pathname: string): number {
  return pathname.split('/').filter(Boolean).length;
}

/**
 * 是否属于「同级横跳」的内容页：**只有一级分区页本身**。
 * 二级页（/history、/growth、设置、后台…）不在此列，必须 push —— 否则从 /me 点进去，
 * 返回键会直接跳过 /me 回到更早的页面。
 */
function isContentPath(pathname: string): boolean {
  return SECTION_PAGES.includes(pathname);
}

/**
 * 是否属于「标签式」跳转（不写入新的浏览历史，用 replace）：
 * - 只改查询参数（排序 / 筛选 / 分页）——同一条列表页的不同视图；
 * - 同层级的内容页之间（表白墙 ↔ 树洞 ↔ 圈子 ↔ 板块 ↔ 活动 ↔ 资讯 ↔ 我的 ↔ 消息）。
 *
 * 注意必须是「同层级」：列表 → 详情的层级不同（/news → /news/403），
 * 那种要 push，否则返回键会直接跳过列表页。
 *
 * 设置 / 后台子页是**真实层级导航**（一级 → 二级 → 三级），不能 replace：
 * 否则从 /settings/profile 退出时会把 /settings 从历史里抹掉，直接落到 /me。
 */
function isTabLikeNavigation(from: URL, to: URL): boolean {
  if (to.pathname === from.pathname) {
    return true;
  }

  return isContentPath(from.pathname)
    && isContentPath(to.pathname)
    && pathLevel(from.pathname) === pathLevel(to.pathname);
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
