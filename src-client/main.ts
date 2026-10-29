import './styles/icons.css';
import './styles/base.css';
import './styles/layout.css';
import './styles/pages.css';
import './styles/design-system.css';

import { setupGlobalActions, refreshUnreadCount, syncBackToTop } from './core/global-actions';
import { setupFormEnhance } from './core/form-enhance';
import { setupFormLoading } from './core/loading';
import {
  setupNavDrawer,
  syncNav,
  setupFloatingBottomNav,
  setupComposingState,
  showFloatingBottomNav,
  setupBottomNavThumbSync,
  setupSegmentedControls,
  syncSegmentedControls,
  setupCardLinks,
  centerActiveTabs,
  syncSlidingTabs,
} from './core/nav';
import { setupRealtime } from './core/realtime';
import { setupAnnouncements } from './core/announcements';
import { setupSwup } from './core/swup-setup';
import { setupTwikoo, setupTwikooCounts } from './core/twikoo';
import { setupAutoSave } from './core/auto-save';
import { setupMusicDock } from './core/music-dock';
import { initTheme, syncAppearanceMeta } from './theme/manager';
import { initI18n, applyTranslations } from './core/i18n';
import { installErrorBoundary } from './core/error-boundary';
import type { PageModule } from './modules/types';

const pages: Record<string, () => Promise<PageModule>> = {
  home: () => import('./modules/feed'),
  compose: () => import('./modules/compose'),
  post: () => import('./modules/post-page'),
  wall: () => import('./modules/confessions'),
  hole: () => import('./modules/confessions'),
  settings: () => import('./modules/settings'),
  search: () => import('./modules/search'),
  messages: () => import('./modules/messages'),
  admin: () => import('./modules/admin'),
  growth: () => import('./modules/growth'),
  shop: () => import('./modules/shop'),
  about: () => import('./modules/about'),
  konbini: () => import('./modules/konbini'),
};

let teardown: (() => void) | void;

/**
 * swup 只替换 #swup 内容，html[data-page] 会停留在首屏值，
 * 导致页面级样式与“按页挂模块”的判断错位；换页时从内容里的标记同步回来。
 */
function syncPageId(): void {
  const marker = document.querySelector<HTMLElement>('#swup [data-page-marker]');
  const page = marker?.dataset.page ?? '';

  if (page !== '') {
    document.documentElement.dataset.page = page;
  }
}

async function activatePage(): Promise<void> {
  if (typeof teardown === 'function') {
    try {
      teardown();
    } catch (error) {
      console.error('[mofu] teardown failed', error);
    }

    teardown = undefined;
  }

  syncPageId();

  const page = document.documentElement.dataset.page ?? '';

  syncNav(window.location.pathname);

  const loader = pages[page];

  if (loader === undefined) {
    return;
  }

  try {
    const module = await loader();
    teardown = await module.init();
  } catch (error) {
    console.error('[mofu] page module failed:', page, error);
  }
}

function registerServiceWorker(): void {
  if (!import.meta.env.PROD || !('serviceWorker' in navigator)) {
    return;
  }

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch((error: unknown) => {
      console.error('[mofu] service worker registration failed', error);
    });
  });
}

async function bootstrap(): Promise<void> {
  installErrorBoundary();
  initTheme();
  await initI18n();
  setupGlobalActions();
  setupFormEnhance();
  setupFormLoading();
  setupNavDrawer();
  setupFloatingBottomNav();
  setupComposingState();
  setupBottomNavThumbSync();
  setupSegmentedControls();
  setupCardLinks();
  setupTwikoo();
  setupTwikooCounts();
  setupAutoSave();
  setupMusicDock();
  registerServiceWorker();

  // 布局（字体/尺寸）稳定后再居中一次选中的标签：首次同步可能早于布局完成
  window.addEventListener('load', () => {
    window.requestAnimationFrame(() => centerActiveTabs());
  });

  // 视口变化后标签宽度会变，重新对齐滑块
  window.addEventListener('resize', () => {
    window.requestAnimationFrame(() => syncSlidingTabs());
  });
  setupRealtime();
  void setupAnnouncements();

  try {
    setupSwup({
      onPageView: () => {
        void activatePage();
        void refreshUnreadCount();
        void setupAnnouncements();
        setupFormEnhance();
        showFloatingBottomNav();
        syncSegmentedControls();
        setupTwikoo();
        setupTwikooCounts();
        setupAutoSave();
        // 换页后滚动归零，回顶部按钮要重新判断
        syncBackToTop();
        window.requestAnimationFrame(() => centerActiveTabs());
        applyTranslations();
        syncAppearanceMeta();
      },
    });
  } catch (error) {
    // Swup 不可用时降级为普通多页导航（链接仍可正常跳转）
    console.error('[mofu] swup init failed, falling back to plain navigation', error);
  }

  void activatePage();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => bootstrap().catch(console.error), { once: true });
} else {
  bootstrap().catch(console.error);
}
