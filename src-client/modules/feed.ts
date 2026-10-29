import { api } from '@/core/api';
import { setupBanner } from '@/core/banner';
import { $ } from '@/core/dom';
import { showSkeletonRows } from '@/core/skeleton';
import { toastError } from '@/core/toast';
import type { PostItem } from '@/types';
import { renderPostCard } from './post-card-render';
import type { PageModule } from './types';

/**
 * 已加载的额外页（第 2 页起）。
 *
 * swup 返回上一页时拿到的是服务端首屏 HTML（只含第 1 页），这里按 tab 缓存后再补回去，
 * 否则「点进帖子再返回」会退回只有第一页的状态。
 */
const loadedPages = new Map<string, { nextPage: number; nodes: HTMLElement[] }>();

/** 触底前多少像素开始预加载 */
const PRELOAD_MARGIN = '400px';

/**
 * 首页信息流：滚动到底自动加载下一页（不再有「加载更多」按钮）。
 */
export const init: PageModule['init'] = () => {
  // 首页横幅轮播（公告/资讯）。横幅是持久节点（data-swup-persist），
  // setupBanner 自带幂等守卫，不需要 teardown —— 否则会拆掉持久节点的监听与圆点。
  const banner = $<HTMLElement>('[data-banner]');
  if (banner !== null) {
    setupBanner(banner);
  }

  const feed = $<HTMLElement>('[data-feed]');
  const list = $<HTMLElement>('[data-feed-list]', feed ?? document);
  const sentinel = $<HTMLElement>('[data-feed-sentinel]', feed ?? document);

  if (feed === null || list === null || sentinel === null) {
    return () => undefined;
  }

  const tab = feed.dataset.currentTab ?? 'latest';
  const endpoint = feed.dataset.feedEndpoint ?? '';
  const pages = Number(feed.dataset.pages ?? 1);
  const publishedId = Number(feed.dataset.publishedId ?? 0);
  let nextPage = Number(feed.dataset.nextPage ?? 2);
  let loading = false;

  if (publishedId > 0) {
    window.requestAnimationFrame(() => {
      const card = list.querySelector<HTMLElement>(`[data-post-id="${publishedId}"]`);

      if (card !== null) {
        card.classList.add('is-new-post');
        card.scrollIntoView({
          block: 'center',
          behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        window.setTimeout(() => card.classList.remove('is-new-post'), 2400);
      }

      const url = new URL(window.location.href);
      url.searchParams.delete('published');
      window.history.replaceState(window.history.state, '', url);
    });
  }

  // 还原本次会话里已经加载过的页
  const cached = loadedPages.get(tab);
  if (cached !== undefined) {
    list.append(...cached.nodes);
    nextPage = cached.nextPage;
  }

  const atEnd = (): boolean => nextPage > pages;

  const loadNext = async (): Promise<void> => {
    if (loading || atEnd()) {
      return;
    }

    loading = true;
    const skeletons = showSkeletonRows(list, 2);

    try {
      const url =
        endpoint !== ''
          ? `${endpoint}?page=${nextPage}`
          : `/api/v1/feed?tab=${encodeURIComponent(tab)}&page=${nextPage}&per_page=10`;
      const { data } = await api.get<PostItem[]>(url);
      const nodes = data.map((post) => renderPostCard(post));

      for (const node of skeletons) {
        node.remove();
      }

      list.append(...nodes);
      nextPage += 1;

      const entry = loadedPages.get(tab) ?? { nextPage, nodes: [] };
      entry.nodes.push(...nodes);
      entry.nextPage = nextPage;
      loadedPages.set(tab, entry);

      if (atEnd()) {
        observer?.disconnect();
        sentinel.remove();
      }
    } catch (error) {
      for (const node of skeletons) {
        node.remove();
      }

      toastError(error);
    } finally {
      loading = false;
    }
  };

  const observer = new IntersectionObserver(
    (entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        void loadNext();
      }
    },
    { rootMargin: PRELOAD_MARGIN },
  );
  observer.observe(sentinel);

  let realtimeLoading = false;

  const onRealtimePost = (event: Event): void => {
    if (tab !== 'latest' || realtimeLoading) {
      return;
    }

    const ids = (event as CustomEvent<{ ids?: unknown[] }>).detail?.ids;

    if (!Array.isArray(ids) || ids.length === 0) {
      return;
    }

    const fresh = ids
      .map((id) => Number(id))
      .filter((id) => Number.isFinite(id) && id > 0 && list.querySelector(`[data-post-id="${id}"]`) === null);

    if (fresh.length === 0) {
      return;
    }

    realtimeLoading = true;

    void Promise.all(
      fresh.slice(0, 8).map(async (id) => {
        try {
          const { data } = await api.get<PostItem>(`/api/v1/posts/${id}`);
          return data;
        } catch {
          return null;
        }
      }),
    ).then((items) => {
      const nodes = items
        .filter((item): item is PostItem => item !== null)
        .sort((a, b) => b.id - a.id)
        .map((item) => {
          const node = renderPostCard(item);
          node.classList.add('is-new-post');
          return node;
        });

      if (nodes.length > 0) {
        list.querySelector('[data-empty-state]')?.remove();
        list.prepend(...nodes);
      }
    }).finally(() => {
      realtimeLoading = false;
    });
  };

  window.addEventListener('mofu:realtime-post', onRealtimePost);

  return () => {
    observer.disconnect();
    window.removeEventListener('mofu:realtime-post', onRealtimePost);
  };
};

/** 供外部（如空状态）复用的类型导出 */
export type { PostItem };
