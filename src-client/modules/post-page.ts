import { api } from '@/core/api';
import { $ } from '@/core/dom';
import { toast, toastError } from '@/core/toast';
import type { PageModule } from './types';

interface CoinSummary {
  coin_count: number;
  my_coins: number;
  max: number;
  balance?: number | null;
}

interface CoinResult extends CoinSummary {
  xp: number;
}

/**
 * 帖子详情页模块。
 *
 * 评论（含回复、头像身份）统一由 Twikoo 渲染，入口是 core/twikoo.ts 的 setupTwikoo()，
 * 不再有站内评论 UI。这里只负责 M币投币：点击按钮展开 1 / 2 币选择，选定后再提交。
 */
export const init: PageModule['init'] = (): (() => void) | void => {
  const section = $<HTMLElement>('[data-comments]');
  const postId = section === null ? 0 : Number(section.dataset.postId ?? 0);

  if (postId <= 0) {
    return;
  }

  const coinButton = $<HTMLButtonElement>('[data-action="give-coin"]');
  const coinCount = coinButton === null ? null : $<HTMLElement>('[data-coin-count]', coinButton);
  const host = coinButton === null ? null : coinButton.closest<HTMLElement>('.coin-host');

  if (coinButton === null || coinCount === null || host === null) {
    return;
  }

  const menu = document.createElement('div');
  menu.className = 'coin-menu';
  menu.setAttribute('role', 'menu');
  menu.hidden = true;
  host.append(menu);

  /** 本篇还能投几枚（每篇上限 max，自己已投 my_coins） */
  let remaining = 0;

  const apply = (data: CoinSummary): void => {
    coinCount.textContent = String(data.coin_count);
    remaining = Math.max(0, data.max - data.my_coins);
    coinButton.disabled = remaining <= 0;
  };

  const renderMenu = (): void => {
    menu.innerHTML = '';

    for (const amount of [1, 2]) {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'card-menu__item coin-menu__item';
      item.setAttribute('role', 'menuitem');
      item.dataset.amount = String(amount);
      item.disabled = amount > remaining;
      item.innerHTML =
        `<span class="material-symbols-outlined" aria-hidden="true">paid</span>投 ${amount} 个 M币`;
      menu.append(item);
    }
  };

  const closeMenu = (): void => {
    menu.hidden = true;
    coinButton.setAttribute('aria-expanded', 'false');
  };

  const openMenu = (): void => {
    renderMenu();
    menu.hidden = false;
    coinButton.setAttribute('aria-expanded', 'true');
  };

  // 初始数量与剩余额度向服务端取（页面里没有内嵌余额信息）
  void (async (): Promise<void> => {
    try {
      const { data } = await api.get<CoinSummary>(`/api/v1/posts/${postId}/coins`);
      apply(data);
    } catch {
      // 未登录等场景：保持默认值
    }
  })();

  const onButtonClick = (event: Event): void => {
    event.preventDefault();

    if (menu.hidden) {
      openMenu();
    } else {
      closeMenu();
    }
  };

  const onMenuClick = async (event: Event): Promise<void> => {
    const target = event.target;
    const item = target instanceof Element ? target.closest<HTMLButtonElement>('.coin-menu__item') : null;

    if (item === null || item.disabled) {
      return;
    }

    const amount = Number(item.dataset.amount ?? 1) || 1;
    closeMenu();
    coinButton.disabled = true;

    try {
      const { data } = await api.post<CoinResult>(`/api/v1/posts/${postId}/coins`, { amount });
      apply(data);
      toast(data.xp > 0 ? `投币成功，+${data.xp} 经验` : '投币成功', 'success');
    } catch (error) {
      coinButton.disabled = false;
      toastError(error);
    }
  };

  const onDocumentClick = (event: Event): void => {
    if (menu.hidden) {
      return;
    }

    const target = event.target;

    if (target instanceof Node && host.contains(target)) {
      return;
    }

    closeMenu();
  };

  const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
      closeMenu();
    }
  };

  coinButton.addEventListener('click', onButtonClick);
  menu.addEventListener('click', (event) => void onMenuClick(event));
  document.addEventListener('click', onDocumentClick);
  document.addEventListener('keydown', onKeydown);

  return () => {
    coinButton.removeEventListener('click', onButtonClick);
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
    menu.remove();
  };
};
