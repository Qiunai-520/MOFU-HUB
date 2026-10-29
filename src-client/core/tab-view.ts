import { removeSkeletonRows, showSkeletonRows } from './skeleton';

/**
 * 「固定外壳 + 局部内容」的 tab 切换生命周期。
 *
 * 契约（服务端模板与客户端共同遵守，全站复用）：
 *  - `data-swup-persist="<唯一 key>"`：持久外壳。切 tab 时 Swup 保留该节点，
 *    导航不重建、滑块动画不重启；key 必须各页面唯一，避免把 A 页的标签栏带进 B 页。
 *  - `[data-tabs-panel]`：可替换内容面板。切 tab 时只有这里换内容、做过渡。
 *  - `html.is-tab-switch`：本模块置位，CSS 据此只让 `[data-tabs-panel]` 轻微淡入
 *    （见 styles/layout.css），外层 `.transition-swup` 的整页动画被关掉。
 *
 * URL / 浏览历史 / 缓存由 Swup 负责（同页换页走 history.replace，回访命中 Swup 内存缓存）；
 * 这里只管「过渡时序」与「内容区骨架」，不重新请求、不触发整页渲染。
 */

/** tab 切换时内容区骨架的延迟：只要不是真的慢就不闪 */
const SKELETON_DELAY = 200;

/** is-tab-switch 保留时长，需覆盖 CSS 过渡（180ms）后再移除 */
const SWITCH_CLASS_MS = 400;

let skeletonTimer: number | undefined;
let skeletonHost: HTMLElement | null = null;
let switchTimer: number | undefined;

/** 标记「本次跳转来自标签栏」；由 nav.ts 的滑块点击调用 */
export function markTabSwitch(): void {
  document.documentElement.dataset.tabSwitch = '';
}

function showTabSkeleton(): void {
  const panel = document.querySelector<HTMLElement>('[data-tabs-panel]');

  if (panel === null) {
    return;
  }

  // 列表容器优先（[data-list] 通用、[data-feed-list] 首页）；都没有就直接填面板
  const list = panel.querySelector<HTMLElement>('[data-list], [data-feed-list]') ?? panel;
  skeletonHost = list;
  showSkeletonRows(list, 3);
}

function clearTabSkeleton(): void {
  window.clearTimeout(skeletonTimer);
  skeletonTimer = undefined;

  if (skeletonHost !== null) {
    removeSkeletonRows(skeletonHost);
    skeletonHost = null;
  }
}

/** 换页开始：把「来自标签栏」的标记转成过渡类，并在内容区延迟显示骨架 */
export function beginTabSwitch(): void {
  if (document.documentElement.dataset.tabSwitch === undefined) {
    return;
  }

  delete document.documentElement.dataset.tabSwitch;
  document.documentElement.classList.add('is-tab-switch');

  window.clearTimeout(skeletonTimer);
  skeletonTimer = window.setTimeout(showTabSkeleton, SKELETON_DELAY);
}

/** 新内容就位：清掉骨架，动画跑完再摘掉过渡类 */
export function endTabSwitch(): void {
  clearTabSkeleton();

  if (!document.documentElement.classList.contains('is-tab-switch')) {
    return;
  }

  window.clearTimeout(switchTimer);
  switchTimer = window.setTimeout(() => {
    document.documentElement.classList.remove('is-tab-switch');
  }, SWITCH_CLASS_MS);
}

/** 跳转中止/失败：撤销过渡状态与骨架 */
export function cancelTabSwitch(): void {
  delete document.documentElement.dataset.tabSwitch;
  clearTabSkeleton();
  window.clearTimeout(switchTimer);
  document.documentElement.classList.remove('is-tab-switch');
}
