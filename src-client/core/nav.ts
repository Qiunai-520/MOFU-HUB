import { $, $$ } from './dom';

function normalize(path: string): string {
  const trimmed = path.replace(/\/+$/, '');

  return trimmed === '' ? '/' : trimmed;
}

function matches(path: string, target: string): boolean {
  return path === target || (target !== '/' && path.startsWith(target + '/'));
}

/** 路由 → 主导航分区的状态机（顺序即优先级） */
const SECTION_RULES: ReadonlyArray<readonly [string, string]> = [
  ['/boards', '/boards'],
  ['/messages', '/messages'],
  ['/notifications', '/messages'],
  ['/me', '/me'],
  ['/u/', '/me'],
  ['/settings', '/me'],
  ['/history', '/me'],
  ['/my/', '/me'],
];

/** 这些前缀下的页面是从「我的」点进去的二级页，不展示悬浮主导航（/me 本身不在内） */
const NAV_COMPACT_PREFIXES: readonly string[] = ['/u', '/settings', '/my', '/history', '/about'];

/**
 * 把横向标签栏里当前选中的项滚到可见位置。
 * 窄屏下标签栏可横滑，若停在最左就会看不到选中项（看起来像“选中跳回第一个”）。
 */
export function centerActiveTabs(): void {
  const containers = $$<HTMLElement>(
    '.settings-nav, .admin-nav, .profile-tabs, .feed-tabs, .filter-tabs',
  );

  for (const container of containers) {
    if (container.scrollWidth <= container.clientWidth) {
      continue;
    }

    const active = container.querySelector<HTMLElement>('.is-active');

    if (active === null) {
      continue;
    }

    // 用 rect 差值计算（容器无 position:relative，offsetLeft 参照系不是容器）
    const cRect = container.getBoundingClientRect();
    const aRect = active.getBoundingClientRect();
    const margin = 8;
    const relLeft = aRect.left - cRect.left + container.scrollLeft;
    const relRight = relLeft + aRect.width;
    const viewLeft = container.scrollLeft;
    const viewRight = viewLeft + container.clientWidth;
    let left = relLeft - (container.clientWidth - aRect.width) / 2;

    if (relLeft - margin < viewLeft) {
      left = relLeft - margin;
    } else if (relRight + margin > viewRight) {
      left = relRight + margin - container.clientWidth;
    }

    container.scrollTo({ left: Math.max(0, left), behavior: 'auto' });
  }
}

/* ---------- 横向标签栏滑块（后台导航 / 设置导航 / 个人主页 / 信息流 / 筛选共用） ---------- */
const SLIDING_TABS_SELECTOR = '.admin-nav, .settings-nav, .profile-tabs, .feed-tabs, .filter-tabs';

/** 上一次滑块几何：换页后容器会被重建，用它做「从旧位置滑过去」的续接 */
const lastThumbGeometry = new Map<string, { width: number; x: number }>();

function tabItems(container: HTMLElement): HTMLElement[] {
  return Array.from(container.children).filter(
    (el): el is HTMLElement => el instanceof HTMLElement && !el.classList.contains('tabs-thumb'),
  );
}

/** 只有「同类容器 + 项数 + 首项」一致才续接动画，避免跨页面误滑 */
function tabKey(container: HTMLElement, items: HTMLElement[]): string {
  const name = Array.from(container.classList).filter((c) => !c.startsWith('is-')).join('.');
  const first = items[0]?.textContent?.trim() ?? '';

  return `${name}|${items.length}|${first}`;
}

function positionTabThumb(container: HTMLElement, thumb: HTMLElement): void {
  // 桌面端后台/设置导航转为纵向时，滑块被媒体查询隐藏
  if (thumb.offsetParent === null) {
    return;
  }

  const items = tabItems(container);
  const active = items.find((el) => el.classList.contains('is-active'));

  if (active === undefined) {
    thumb.classList.remove('is-ready');

    return;
  }

  const { width, x } = thumbGeometry(container, active);

  thumb.style.width = `${width}px`;
  thumb.style.transform = `translateX(${x}px)`;
  thumb.classList.add('is-ready');
  lastThumbGeometry.set(tabKey(container, items), { width, x });
}

/** 点击时先把滑块滑到目标项，再交给 swup 换页（不等新文档回来） */
function onTabPress(container: HTMLElement, thumb: HTMLElement, event: Event): void {
  const pressed = event.target instanceof Element ? event.target.closest<HTMLElement>('a, button') : null;

  if (pressed === null || !container.contains(pressed)) {
    return;
  }

  const items = tabItems(container);
  const index = items.findIndex((el) => el === pressed);

  if (index < 0) {
    return;
  }

  items.forEach((el, i) => {
    el.classList.toggle('is-active', i === index);

    if (i === index) {
      el.setAttribute('aria-current', 'page');
    } else {
      el.removeAttribute('aria-current');
    }
  });
  positionTabThumb(container, thumb);

  // 标记本次跳转来自标签栏：swup 那边只淡入内容区，标签栏本身不参与动画（避免整页闪一下）
  if (pressed instanceof HTMLAnchorElement && pressed.href !== location.href) {
    document.documentElement.dataset.tabSwitch = '';
  }
}

/**
 * 持久化的标签栏不会随新页面重建，所以换页（含前进/后退）后要按当前 URL 重新对齐选中项，
 * 否则会把上一页的选中状态留给新页面。只在能确定匹配时改，避免高亮错项。
 */
function syncTabActiveFromLocation(container: HTMLElement): void {
  const links = tabItems(container).filter(
    (el): el is HTMLAnchorElement => el instanceof HTMLAnchorElement && el.hasAttribute('href'),
  );

  if (links.length === 0) {
    return;
  }

  const currentTab = new URLSearchParams(location.search).get('tab') ?? '';
  const match = links.find((link) => {
    if (link.pathname !== location.pathname) {
      return false;
    }

    const linkTab = new URL(link.href).searchParams.get('tab') ?? '';

    return linkTab === currentTab || (currentTab === '' && link.dataset.tabDefault !== undefined);
  });

  if (match === undefined) {
    return;
  }

  for (const link of links) {
    link.classList.toggle('is-active', link === match);

    if (link === match) {
      link.setAttribute('aria-current', 'page');
    } else {
      link.removeAttribute('aria-current');
    }
  }
}

/** 给所有横向标签栏挂上滑块并同步位置（幂等，换页后可重复调用） */
export function syncSlidingTabs(): void {
  for (const container of $$<HTMLElement>(SLIDING_TABS_SELECTOR)) {
    // 持久化的标签栏要先按当前 URL 对齐选中项，再定位滑块
    if (container.hasAttribute('data-swup-persist')) {
      syncTabActiveFromLocation(container);
    }

    const existing = $<HTMLElement>(':scope > .tabs-thumb', container);

    if (existing !== null) {
      positionTabThumb(container, existing);

      continue;
    }

    const thumb = document.createElement('span');
    thumb.className = 'tabs-thumb';
    thumb.setAttribute('aria-hidden', 'true');
    // 先打标记让滑块拿到绝对定位样式，再插入，避免短暂参与弹性布局
    container.dataset.tabs = '';
    container.prepend(thumb);
    // 用 click 而不是 pointerdown：横向拖动标签栏（看后面的标签）时不应该把滑块也带过去
    container.addEventListener('click', (event) => onTabPress(container, thumb, event));

    const previous = lastThumbGeometry.get(tabKey(container, tabItems(container)));

    if (previous === undefined) {
      positionTabThumb(container, thumb);

      continue;
    }

    // 先摆在上一次的位置（无过渡），下一帧再滑到新位置，跨页也能看到连续滑动
    thumb.style.transition = 'none';
    thumb.style.width = `${previous.width}px`;
    thumb.style.transform = `translateX(${previous.x}px)`;
    thumb.classList.add('is-ready');
    window.requestAnimationFrame(() => {
      thumb.style.transition = '';
      positionTabThumb(container, thumb);
    });
  }
}

/** 把任意路径归到一个主导航分区（默认主页） */
function sectionOf(path: string): string {
  for (const [prefix, nav] of SECTION_RULES) {
    if (path === prefix.replace(/\/$/, '') || path.startsWith(prefix.endsWith('/') ? prefix : prefix + '/')) {
      return nav;
    }
  }

  return '/';
}

/** 高亮当前导航项（最长前缀匹配；未命中时按分区状态机兜底） */
export function syncNav(path: string): void {
  const normalized = normalize(path);
  const items = $$<HTMLElement>('[data-nav-item]');
  const bottomValues = new Set(
    $$<HTMLElement>('.bottom-nav__item[data-nav-item]').map((item) => normalize(item.dataset.navItem ?? '')),
  );

  let bestValue = '';
  let bestLength = -1;

  for (const item of items) {
    const value = normalize(item.dataset.navItem ?? '');

    if (matches(normalized, value) && value.length > bestLength) {
      bestLength = value.length;
      bestValue = value;
    }
  }

  // 深层路由（关于、详情、个人页、设置等）不直接对应底栏项：
  // 侧栏沿用最长前缀匹配结果，底栏按「路由 → 主导航分区」兜底，保证底栏始终有且只有一个选中项。
  const sectionValue = bestValue !== '' && bottomValues.has(bestValue) ? bestValue : sectionOf(normalized);

  // 供样式层使用：从「我的」点进去的二级页（个人主页 / 设置）不再展示悬浮主导航；
  // 「我的」首页本身仍然展示，它仍是主导航的一个顶级分区。
  const compactNav = NAV_COMPACT_PREFIXES.some(
    (prefix) => normalized === prefix || normalized.startsWith(prefix + '/'),
  );

  if (compactNav) {
    document.documentElement.dataset.navCompact = '';
  } else {
    delete document.documentElement.dataset.navCompact;
  }

  for (const item of items) {
    const value = normalize(item.dataset.navItem ?? '');
    const active = bottomValues.has(value) ? value === sectionValue : value === bestValue;

    item.classList.toggle('is-active', active);

    if (active) {
      item.setAttribute('aria-current', 'page');
    } else {
      item.removeAttribute('aria-current');
    }
  }

  // 底部导航滑块跟随当前路由
  syncBottomNavThumb();
  // 横向标签栏滑块（后台/设置/个人主页/信息流/筛选）同步到当前页
  syncSlidingTabs();
  // 窄屏下把选中的标签滚到可见位置
  centerActiveTabs();
}

/** 移动端导航抽屉（全局一次性绑定） */
export function setupNavDrawer(): void {
  const nav = $('#side-nav');

  if (nav === null) {
    return;
  }

  const scrim = $('[data-nav-scrim]');

  const close = (): void => {
    nav.classList.remove('is-open');
    scrim?.setAttribute('hidden', '');
    document.body.classList.remove('nav-open');
    $$('.nav-toggle').forEach((btn) => btn.setAttribute('aria-expanded', 'false'));

    // 抽屉关闭后焦点回到开关按钮（键盘用户不丢失位置）
    $<HTMLElement>('.nav-toggle')?.focus();
  };

  const open = (): void => {
    nav.classList.add('is-open');
    scrim?.removeAttribute('hidden');
    document.body.classList.add('nav-open');
    $$('.nav-toggle').forEach((btn) => btn.setAttribute('aria-expanded', 'true'));

    // 打开后聚焦首个导航项，支持键盘直接导览
    nav.querySelector<HTMLElement>('.side-nav__item')?.focus();
  };

  document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement | null;

    if (target === null) {
      return;
    }

    if (target.closest('[data-action="toggle-nav"]')) {
      event.preventDefault();
      nav.classList.contains('is-open') ? close() : open();
      return;
    }

    if (target.closest('[data-nav-scrim]')) {
      close();
      return;
    }

    if (nav.classList.contains('is-open') && target.closest('#side-nav a')) {
      close();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && nav.classList.contains('is-open')) {
      close();
    }
  });
}

/* ---------- 底部悬浮导航：下滑收起、上滑浮出 ---------- */
const BOTTOM_NAV_HIDDEN = 'is-hidden';
const BOTTOM_NAV_HIDE_AFTER = 96;
const BOTTOM_NAV_MIN_DELTA = 6;

let bottomNavLastY = 0;
let bottomNavTicking = false;
let bottomNavReshowTimer: number | undefined;

/** 收起后 1 秒自动弹回 */
const BOTTOM_NAV_RESHOW_MS = 1000;
/** 悬浮按钮（发布/音乐/回顶）随导航一起收起，收起 1.5 秒后自己回来 */
const FAB_RESHOW_DELAY_MS = 1500;

let bottomNavFabTimer: number | undefined;

/** 悬浮按钮显隐：显示立即，收起则延迟 1.5 秒后自动回到可见 */
function syncFabVisibility(visible: boolean): void {
  const fab = $('.fab-stack');

  if (fab === null) {
    return;
  }

  if (visible) {
    window.clearTimeout(bottomNavFabTimer);
    bottomNavFabTimer = undefined;
    fab.classList.remove(BOTTOM_NAV_HIDDEN);

    return;
  }

  // 已在收起状态或正在计时：不重启倒计时，否则持续下滑会让它永远不回来
  if (bottomNavFabTimer !== undefined || fab.classList.contains(BOTTOM_NAV_HIDDEN)) {
    return;
  }

  fab.classList.add(BOTTOM_NAV_HIDDEN);
  bottomNavFabTimer = window.setTimeout(() => {
    bottomNavFabTimer = undefined;
    fab.classList.remove(BOTTOM_NAV_HIDDEN);
  }, FAB_RESHOW_DELAY_MS);
}

function scheduleBottomNavReshow(nav: HTMLElement): void {
  window.clearTimeout(bottomNavReshowTimer);
  bottomNavReshowTimer = window.setTimeout(() => {
    // 只把导航弹回来；悬浮按钮的收起由自己的 1.5 秒计时负责
    nav.classList.remove(BOTTOM_NAV_HIDDEN);
  }, BOTTOM_NAV_RESHOW_MS);
}

function cancelBottomNavReshow(): void {
  window.clearTimeout(bottomNavReshowTimer);
  bottomNavReshowTimer = undefined;
}

/** 聚焦输入控件时，不自动收起导航 */
function bottomNavBusy(): boolean {
  const active = document.activeElement;

  if (active instanceof HTMLElement) {
    if (active.closest('.bottom-nav') !== null) {
      return true;
    }

    const tag = active.tagName;

    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || active.isContentEditable) {
      return true;
    }
  }

  return false;
}

/** 立即显示底部导航（页面切换后复位，避免停留在收起态） */
export function showFloatingBottomNav(): void {
  bottomNavLastY = window.scrollY;
  cancelBottomNavReshow();
  syncFabVisibility(true);
  delete document.documentElement.dataset.composing;
  $('.bottom-nav')?.classList.remove(BOTTOM_NAV_HIDDEN);
}

/** 是否为「写内容」的长文本编辑控件（搜索框等短输入不算） */
function isLongTextEditor(el: Element | null): boolean {
  if (el === null) {
    return false;
  }

  if (el instanceof HTMLTextAreaElement || (el instanceof HTMLElement && el.isContentEditable)) {
    return true;
  }

  return el.closest('textarea, [contenteditable="true"], [contenteditable=""]') !== null;
}

/**
 * 写内容时收起悬浮导航与悬浮按钮：手机键盘弹起后导航会正好挡在输入框上方。
 * 光标离开长文本控件（点击发送/取消、焦点移到别处）后自动恢复。
 *
 * 用 setTimeout 而不是 requestAnimationFrame：后台标签页与无头环境会冻结 rAF，
 * 一旦冻住标记就永远不撤，导航会一直消失；再加一个点击兜底，
 * 覆盖「点取消后输入框连同焦点一起被移除」这种不一定会触发 focusout 的情况。
 */
export function setupComposingState(): void {
  const sync = (): void => {
    if (isLongTextEditor(document.activeElement)) {
      document.documentElement.dataset.composing = '';
    } else {
      delete document.documentElement.dataset.composing;
    }
  };

  document.addEventListener('focusin', sync);
  document.addEventListener('focusout', () => window.setTimeout(sync, 0));
  document.addEventListener('click', () => window.setTimeout(sync, 0));
}

/** 底部悬浮导航随滚动收起/浮出（全局一次性绑定） */
export function setupFloatingBottomNav(): void {
  const nav = $('.bottom-nav');

  if (nav === null) {
    return;
  }

  bottomNavLastY = window.scrollY;

  const update = (): void => {
    bottomNavTicking = false;

    const y = window.scrollY;
    const delta = y - bottomNavLastY;

    // 交互中 / 聚焦输入时保持可见，避免操作中导航消失
    if (bottomNavBusy()) {
      cancelBottomNavReshow();
      nav.classList.remove(BOTTOM_NAV_HIDDEN);
      syncFabVisibility(true);
      bottomNavLastY = y;

      return;
    }

    if (Math.abs(delta) < BOTTOM_NAV_MIN_DELTA) {
      return;
    }

    if (y <= BOTTOM_NAV_HIDE_AFTER || delta < 0) {
      cancelBottomNavReshow();
      nav.classList.remove(BOTTOM_NAV_HIDDEN);
      syncFabVisibility(true);
    } else {
      nav.classList.add(BOTTOM_NAV_HIDDEN);
      scheduleBottomNavReshow(nav);
      syncFabVisibility(false);
    }

    bottomNavLastY = y;
  };

  window.addEventListener('scroll', () => {
    if (!bottomNavTicking) {
      bottomNavTicking = true;
      window.requestAnimationFrame(update);
    }
  }, { passive: true });
}

/* ---------- 底部导航滑块 ---------- */

function bottomNavParts(): { bar: HTMLElement; thumb: HTMLElement; items: HTMLElement[] } | null {
  const bar = $<HTMLElement>('.bottom-nav__bar');

  if (bar === null) {
    return null;
  }

  const thumb = $<HTMLElement>('.bottom-nav__thumb', bar);
  const items = visibleBottomNavItems(bar);

  return thumb === null || items.length === 0 ? null : { bar, thumb, items };
}

/** 仅取当前断点下可见的导航项（桌面会隐藏与侧栏重复的入口） */
function visibleBottomNavItems(bar: HTMLElement): HTMLElement[] {
  return $$<HTMLElement>('.bottom-nav__item', bar).filter(
    (item) => item.offsetWidth > 0 || item.offsetHeight > 0,
  );
}

/**
 * 滑块应落在的位置与宽度：直接按实际 rect 推导，
 * 使滑块盒子与导航项完全重合（CSS 内边距/圆角调整后无需同步改数字）。
 * 滑块的定位原点是容器的内边距盒左边缘；translateX 作用在内容坐标系上，
 * 所以要补回容器自身的 scrollLeft（标签栏可横滑时否则会偏移）。
 */
function thumbGeometry(container: HTMLElement, item: HTMLElement): { width: number; x: number } {
  const containerRect = container.getBoundingClientRect();
  const itemRect = item.getBoundingClientRect();

  return {
    width: itemRect.width,
    x: itemRect.left - (containerRect.left + container.clientLeft) + container.scrollLeft,
  };
}

/** 把滑块对到当前激活项（换页/改窗口后调用） */
export function syncBottomNavThumb(): void {
  const parts = bottomNavParts();

  if (parts === null) {
    return;
  }

  const { bar, thumb, items } = parts;
  const activeIndex = items.findIndex((item) => item.classList.contains('is-active'));

  if (activeIndex < 0) {
    // 当前路由不在可见项内（如桌面隐藏了该入口）：滑块收起
    thumb.style.opacity = '0';

    return;
  }

  const el = items[activeIndex];

  if (el === undefined) {
    return;
  }

  thumb.style.opacity = '';
  const { width, x } = thumbGeometry(bar, el);
  thumb.style.width = `${width}px`;
  thumb.style.transform = `translateX(${x}px)`;
}

export function navigateTo(href: string): void {
  const swup = (window as unknown as { __mofuSwup?: { navigate: (url: string) => void } }).__mofuSwup;

  if (swup !== undefined) {
    swup.navigate(href);
  } else {
    window.location.assign(href);
  }
}

/** 底部导航滑块：只跟随当前路由（不做拖动选择，拖动跟手会和点击/滚动手感打架） */
export function setupBottomNavThumbSync(): void {
  window.addEventListener('resize', syncBottomNavThumb);
  syncBottomNavThumb();
}

/* ---------- 通用分段滑块（消息：私信/通知） ---------- */
function syncSegmented(root: HTMLElement): void {
  const thumb = $<HTMLElement>('[data-segmented-thumb]', root);
  const items = $$<HTMLElement>('.segmented__item', root);

  if (thumb === null || items.length === 0) {
    return;
  }

  const active = items.findIndex((item) => item.classList.contains('is-active'));
  const el = items[active < 0 ? 0 : active];

  if (el === undefined) {
    return;
  }

  const { width, x } = thumbGeometry(root, el);
  thumb.style.width = `${width}px`;
  thumb.style.transform = `translateX(${x}px)`;
}

/** 同步所有分段控件滑块（换页后需重新调用，元素会被替换） */
export function syncSegmentedControls(): void {
  $$<HTMLElement>('[data-segmented]').forEach(syncSegmented);
}

/** 全局一次性绑定：窗口变化时重算滑块 */
export function setupSegmentedControls(): void {
  window.addEventListener('resize', () => {
    syncBottomNavThumb();
    syncSegmentedControls();
  });
  syncSegmentedControls();
}

/* ---------- 卡片整块可点（点卡片进详情，点内部按钮/链接不拦截） ---------- */
export function setupCardLinks(): void {
  document.addEventListener('click', (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const card = target.closest<HTMLElement>('[data-post-link]');

    if (card === null) {
      return;
    }

    if (target.closest('a, button, input, textarea, select, video, audio, [data-action]') !== null) {
      return;
    }

    const selection = window.getSelection();

    if (selection !== null && selection.toString() !== '') {
      return;
    }

    const href = card.getAttribute('data-post-link');

    if (href !== null && href !== '') {
      navigateTo(href);
    }
  });
}
