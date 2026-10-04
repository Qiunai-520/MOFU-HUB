import { setButtonLoading } from '@/core/loading';
import { showSkeletonRows, removeSkeletonRows } from '@/core/skeleton';
import { toastError } from '@/core/toast';

/**
 * 列表「加载更多」：抓取下一页的 SSR HTML，提取同名列表容器的子节点并追加。
 *
 * 契约（见 components/load-more.twig）：
 *   data-endpoint   下一页链接前缀（以 page= 结尾，直接拼页码）
 *   data-next-page  要加载的页码
 *   data-pages      总页数
 *   data-target     列表容器名（页面容器上需有 data-list="<target>"）
 *
 * 说明：追加的节点依赖全局事件委托（点赞/收藏/举报等）生效，无需重新绑定。
 */
export async function loadMore(button: HTMLButtonElement): Promise<void> {
  const endpoint = button.dataset.endpoint ?? '';
  const nextPage = Number(button.dataset.nextPage ?? 0);
  const pages = Number(button.dataset.pages ?? 1);
  const target = button.dataset.target ?? '';
  const list = target !== '' ? document.querySelector<HTMLElement>(`[data-list="${target}"]`) : null;

  if (endpoint === '' || nextPage <= 0 || list === null) {
    return;
  }

  setButtonLoading(button, true);
  showSkeletonRows(list, 2);

  try {
    const response = await fetch(`${endpoint}${nextPage}`, {
      headers: { Accept: 'text/html' },
      credentials: 'same-origin',
    });

    if (!response.ok) {
      throw new Error(`加载失败（HTTP ${response.status}）`);
    }

    const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
    const source = doc.querySelector<HTMLElement>(`[data-list="${target}"]`);

    if (source === null) {
      throw new Error('没有找到更多内容');
    }

    const items = Array.from(source.children);
    list.append(...items);

    const loaded = nextPage;
    button.dataset.nextPage = String(loaded + 1);

    if (loaded >= pages) {
      button.hidden = true;

      const hint = button.parentElement?.querySelector<HTMLElement>('[data-load-more-hint]');
      if (hint != null) {
        hint.hidden = false;
      }
    }
  } catch (error) {
    toastError(error);
  } finally {
    removeSkeletonRows(list);
    setButtonLoading(button, false);
  }
}
