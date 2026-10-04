import { $, escapeHtml } from '@/core/dom';
import { navigateTo } from '@/core/nav';
import type { PageModule } from './types';

const STORAGE_KEY = 'mofu.search.history';
const MAX_ITEMS = 8;

function readHistory(): string[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    const decoded: unknown = raw === null ? null : JSON.parse(raw);

    return Array.isArray(decoded)
      ? decoded.filter((item): item is string => typeof item === 'string' && item !== '').slice(0, MAX_ITEMS)
      : [];
  } catch {
    return [];
  }
}

function writeHistory(items: string[]): void {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(items.slice(0, MAX_ITEMS)));
  } catch {
    // 存储不可用时忽略
  }
}

interface SuggestItem {
  label: string;
  sub: string;
  url: string;
}

interface SuggestResponse {
  topics?: Array<{ name: string; url: string }>;
  users?: Array<{ name: string; username: string; url: string }>;
}

/**
 * 输入时的实时搜索建议：话题 + 用户。
 * 键盘可用（↑↓ 选择 / Enter 进入 / Esc 关闭），返回清理函数。
 */
function setupSuggest(input: HTMLInputElement, box: HTMLElement): () => void {
  let items: SuggestItem[] = [];
  let active = -1;
  let timer: number | undefined;
  let controller: AbortController | null = null;

  const close = (): void => {
    box.hidden = true;
    box.replaceChildren();
    items = [];
    active = -1;
    input.setAttribute('aria-expanded', 'false');
  };

  const highlight = (): void => {
    Array.from(box.querySelectorAll<HTMLElement>('.search-suggest__item')).forEach((el, i) => {
      el.classList.toggle('is-active', i === active);
    });
  };

  const render = (data: SuggestResponse): void => {
    items = [
      ...(data.topics ?? []).map((topic) => ({ label: '#' + topic.name, sub: '话题', url: topic.url })),
      ...(data.users ?? []).map((user) => ({ label: user.name, sub: '@' + user.username, url: user.url })),
    ];

    if (items.length === 0) {
      close();

      return;
    }

    box.innerHTML = items
      .map(
        (item, i) =>
          `<a class="search-suggest__item" role="option" href="${escapeHtml(item.url)}" data-index="${i}">` +
          `<span class="search-suggest__label">${escapeHtml(item.label)}</span>` +
          `<span class="search-suggest__sub">${escapeHtml(item.sub)}</span></a>`,
      )
      .join('');
    active = -1;
    box.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  };

  const onInput = (): void => {
    const keyword = input.value.trim();
    window.clearTimeout(timer);

    if (keyword === '') {
      close();

      return;
    }

    timer = window.setTimeout(() => {
      controller?.abort();
      controller = new AbortController();

      void fetch(`/api/v1/search/suggest?q=${encodeURIComponent(keyword)}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: controller.signal,
      })
        .then((res) => (res.ok ? res.json() : null))
        .then((payload: { ok?: boolean; data?: SuggestResponse } | null) => {
          if (payload?.ok === true && payload.data !== undefined) {
            render(payload.data);
          }
        })
        .catch(() => undefined);
    }, 180);
  };

  const onKeydown = (event: KeyboardEvent): void => {
    if (box.hidden) {
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      active = Math.min(active + 1, items.length - 1);
      highlight();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      active = Math.max(active - 1, 0);
      highlight();
    } else if (event.key === 'Enter' && active >= 0) {
      const item = items[active];

      if (item !== undefined) {
        event.preventDefault();
        navigateTo(item.url);
      }
    } else if (event.key === 'Escape') {
      close();
    }
  };

  const onBoxClick = (event: Event): void => {
    const anchor = (event.target as HTMLElement).closest<HTMLAnchorElement>('a[data-index]');

    if (anchor === null) {
      return;
    }

    event.preventDefault();
    const item = items[Number(anchor.dataset.index ?? -1)];

    if (item !== undefined) {
      navigateTo(item.url);
    }
  };

  const onDocumentClick = (event: Event): void => {
    if (!(event.target instanceof Node) || box.contains(event.target) || event.target === input) {
      return;
    }

    close();
  };

  input.addEventListener('input', onInput);
  input.addEventListener('keydown', onKeydown);
  box.addEventListener('click', onBoxClick);
  document.addEventListener('click', onDocumentClick);

  return () => {
    window.clearTimeout(timer);
    controller?.abort();
    input.removeEventListener('input', onInput);
    input.removeEventListener('keydown', onKeydown);
    box.removeEventListener('click', onBoxClick);
    document.removeEventListener('click', onDocumentClick);
  };
}

/**
 * 搜索页模块：
 * - 记录/展示/清空本地搜索历史；
 * - 将当前关键词回填到应用外壳的搜索框。
 */
export const init: PageModule['init'] = () => {
  const wrap = $<HTMLElement>('[data-search-history-wrap]');
  const list = $<HTMLElement>('[data-search-history]');

  const current = new URLSearchParams(window.location.search).get('q')?.trim() ?? '';

  if (current !== '') {
    writeHistory([current, ...readHistory().filter((item) => item !== current)]);
  }

  const headerInput = document.querySelector<HTMLInputElement>('.global-search__input');

  if (headerInput !== null && current !== '') {
    headerInput.value = current;
  }

  const render = (): void => {
    if (wrap === null || list === null) {
      return;
    }

    const items = readHistory();

    if (items.length === 0) {
      wrap.setAttribute('hidden', '');
      return;
    }

    wrap.removeAttribute('hidden');
    list.replaceChildren(
      ...items.map((keyword) => {
        const link = document.createElement('a');
        link.className = 'topic-chip';
        link.href = `/search?q=${encodeURIComponent(keyword)}`;
        link.textContent = keyword;

        return link;
      }),
    );
  };

  render();

  const onClick = (event: Event): void => {
    const trigger = (event.target as HTMLElement).closest('[data-action="clear-search-history"]');

    if (trigger === null) {
      return;
    }

    event.preventDefault();
    writeHistory([]);
    render();
  };

  document.addEventListener('click', onClick);

  const heroInput = $<HTMLInputElement>('.search-hero__input');
  const suggestBox = $<HTMLElement>('[data-search-suggest]');
  const teardownSuggest = heroInput !== null && suggestBox !== null ? setupSuggest(heroInput, suggestBox) : null;

  return () => {
    document.removeEventListener('click', onClick);
    teardownSuggest?.();
  };
};
