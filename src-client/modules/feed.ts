import { api } from '@/core/api';
import { setupBanner } from '@/core/banner';
import { $ } from '@/core/dom';
import { setButtonLoading } from '@/core/loading';
import { toastError } from '@/core/toast';
import type { PostItem } from '@/types';
import { renderPostCard } from './post-card-render';
import type { PageModule } from './types';

export const init: PageModule['init'] = () => {
  const teardowns: Array<() => void> = [];

  // 首页横幅轮播（公告/资讯）
  const banner = $<HTMLElement>('[data-banner]');
  if (banner !== null) {
    teardowns.push(setupBanner(banner));
  }

  const feed = $<HTMLElement>('[data-feed]');
  const list = $<HTMLElement>('[data-feed-list]', feed ?? document);
  const moreButton = $<HTMLButtonElement>('[data-action="load-more"]', feed ?? document);

  if (feed === null || list === null || moreButton === null) {
    return () => teardowns.forEach((fn) => fn());
  }

  const tab = feed.dataset.currentTab ?? 'latest';
  const endpoint = feed.dataset.feedEndpoint ?? '';
  let nextPage = Number(moreButton.dataset.nextPage ?? 2);
  let loading = false;

  const onClick = async (): Promise<void> => {
    if (loading) {
      return;
    }

    loading = true;
    setButtonLoading(moreButton, true);

    try {
      const url =
        endpoint !== ''
          ? `${endpoint}?page=${nextPage}`
          : `/api/v1/feed?tab=${encodeURIComponent(tab)}&page=${nextPage}&per_page=10`;
      const { data, meta } = await api.get<PostItem[]>(url);

      for (const post of data) {
        list.append(renderPostCard(post));
      }

      nextPage += 1;
      const pages = meta.pagination?.pages ?? nextPage;

      if (nextPage > pages) {
        moreButton.setAttribute('hidden', '');

        const hint = moreButton.parentElement?.querySelector<HTMLElement>('[data-load-more-hint]');
        if (hint != null) {
          hint.hidden = false;
        }
      }
    } catch (error) {
      toastError(error);
    } finally {
      loading = false;
      setButtonLoading(moreButton, false);
    }
  };

  moreButton.addEventListener('click', onClick);

  return () => {
    moreButton.removeEventListener('click', onClick);
    teardowns.forEach((fn) => fn());
  };
};

/** 供外部（如空状态）复用的类型导出 */
export type { PostItem };
