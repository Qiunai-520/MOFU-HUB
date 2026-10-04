import { api } from './api';
import { confirmDialog } from './dialog';
import { $, $$ } from './dom';
import { loadMore } from './load-more';
import { setButtonLoading } from './loading';
import { $unreadCount, $user } from './store';
import { toast, toastError } from './toast';
import { openReportDialog } from '@/modules/report-dialog';

let bound = false;

/** 灯箱触发元素（关闭后还原焦点） */
let lightboxTrigger: HTMLElement | null = null;

/** 已通过确认对话框的表单（短窗口内跳过二次确认） */
const confirmBypass = new WeakSet<HTMLFormElement>();

/**
 * 全局交互（事件委托，Swup 切页后依然有效）：
 * 点赞、收藏、关注、举报、图片灯箱、删除、回到顶部、用户菜单等。
 */
export function setupGlobalActions(): void {
  if (bound) {
    return;
  }
  bound = true;

  document.addEventListener('click', (event) => {
    // event.target 不一定是 Element（可由脚本/扩展派发到 document）
    const clicked = event.target;
    const trigger = clicked instanceof Element ? clicked.closest<HTMLElement>('[data-action]') : null;

    if (trigger === null || trigger === undefined) {
      return;
    }

    switch (trigger.dataset.action) {
      case 'toggle-like':
        event.preventDefault();
        void handleLike(trigger);
        break;

      case 'toggle-favorite':
        event.preventDefault();
        void handleFavorite(trigger);
        break;

      case 'toggle-repost':
        event.preventDefault();
        void handleRepost(trigger);
        break;

      case 'toggle-follow':
        event.preventDefault();
        void handleFollow(trigger);
        break;

      case 'report':
        event.preventDefault();
        openReportDialog(trigger.dataset.targetType ?? '', Number(trigger.dataset.targetId ?? 0));
        break;

      case 'lightbox':
        event.preventDefault();
        openLightbox(trigger.dataset.src ?? '');
        break;

      case 'close-lightbox':
        event.preventDefault();
        closeLightbox();
        break;

      case 'share':
        event.preventDefault();
        void shareLink(trigger);
        break;

      case 'toggle-block':
        event.preventDefault();
        void handleBlockToggle(trigger);
        break;

      case 'delete-post':
        event.preventDefault();
        void handleDeletePost(trigger);
        break;

      case 'delete-confession':
        event.preventDefault();
        void handleDeleteConfession(trigger);
        break;

      case 'mark-all-read':
        event.preventDefault();
        void handleMarkAllRead();
        break;

      case 'open-notification': {
        const id = Number(trigger.dataset.notificationId ?? 0);
        if (id > 0) {
          void api.post('/api/v1/notifications/read', { ids: [id] }).catch(() => undefined);
        }
        break;
      }

      case 'toggle-user-menu':
        event.preventDefault();
        toggleUserMenu(trigger);
        break;

      case 'back-to-top':
        event.preventDefault();
        window.scrollTo({
          top: 0,
          behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
        break;

      case 'load-more-page':
        event.preventDefault();
        void loadMore(trigger as HTMLButtonElement);
        break;
    }
  });

  // 点击空白处关闭用户菜单
  document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement | null;

    if (target === null || target.closest('[data-user-menu]') !== null) {
      return;
    }

    closeAllUserMenus();
  });

  document.addEventListener('submit', (event) => {
    const form = event.target as HTMLFormElement;
    const submitter = (event as SubmitEvent).submitter;

    if (confirmBypass.has(form)) {
      return;
    }

    const message = submitter?.getAttribute('data-confirm') ?? form.getAttribute('data-confirm');

    if (message === null) {
      return;
    }

    event.preventDefault();

    void confirmDialog(message).then((approved) => {
      if (!approved) {
        return;
      }

      // 同步窗口内打标并在放行后立即移除，避免 requestSubmit 再次触发确认
      confirmBypass.add(form);
      form.requestSubmit(submitter ?? undefined);
      confirmBypass.delete(form);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeLightbox();
      closeAllUserMenus();
    }
  });

  // 标签/筛选组的键盘漫游（←/→/Home/End）
  document.addEventListener('keydown', (event) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const tabs = target.closest<HTMLElement>('.feed-tabs, .filter-tabs, .profile-tabs');

    if (tabs === null || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
      return;
    }

    const links = Array.from(tabs.querySelectorAll<HTMLElement>(':scope > a')).filter((link) => !link.hasAttribute('hidden'));

    if (links.length === 0) {
      return;
    }

    const current = links.findIndex((link) => link === target || link.contains(target));

    if (current < 0) {
      return;
    }

    let next = current;

    if (event.key === 'ArrowLeft') {
      next = (current - 1 + links.length) % links.length;
    } else if (event.key === 'ArrowRight') {
      next = (current + 1) % links.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else {
      next = links.length - 1;
    }

    const targetLink = links[next];

    if (targetLink !== undefined) {
      event.preventDefault();
      targetLink.focus();
    }
  });

  // 用户菜单方向键导览（↑/↓/Home/End）
  document.addEventListener('keydown', (event) => {
    const panel = document.querySelector<HTMLElement>('[data-user-menu-panel]:not([hidden])');

    if (panel === null || !['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      return;
    }

    const items = Array.from(panel.querySelectorAll<HTMLElement>('a.user-menu__item, button.user-menu__item'));

    if (items.length === 0) {
      return;
    }

    const current = items.indexOf(document.activeElement as HTMLElement);
    let next = current;

    if (event.key === 'ArrowDown') {
      next = (current + 1 + items.length) % items.length;
    } else if (event.key === 'ArrowUp') {
      next = (current - 1 + items.length) % items.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else {
      next = items.length - 1;
    }

    const targetItem = items[next];

    if (targetItem !== undefined) {
      event.preventDefault();
      targetItem.focus();
    }
  });

  // 未读角标联动
  $unreadCount.subscribe((count) => {
    for (const badge of $$<HTMLElement>('[data-unread-badge]')) {
      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.removeAttribute('hidden');
      } else {
        badge.setAttribute('hidden', '');
      }
    }
  });

  setupBackToTop();
  void refreshUnreadCount();
}

/** 将未读计数应用到角标（SSE 推送与轮询共用） */
export function applyUnreadCounts(notificationCount: number, messageCount: number): void {
  $unreadCount.set(notificationCount);

  for (const badge of $$<HTMLElement>('[data-message-badge]')) {
    if (messageCount > 0) {
      badge.textContent = messageCount > 99 ? '99+' : String(messageCount);
      badge.removeAttribute('hidden');
    } else {
      badge.setAttribute('hidden', '');
    }
  }
}

export async function refreshUnreadCount(): Promise<void> {
  if ($user.get() === null) {
    return;
  }

  try {
    const [notifications, messages] = await Promise.all([
      api.get<{ count: number }>('/api/v1/notifications/unread-count'),
      api.get<{ count: number }>('/api/v1/messages/unread-count'),
    ]);

    applyUnreadCounts(notifications.data.count, messages.data.count);
  } catch {
    // 静默失败（未登录或网络异常）
  }
}

// ---------------------------------------------------------------- 回到顶部

/** 回到顶部：滚过足够多内容才出现（至少一屏多，且不少于 900px） */
const BACK_TO_TOP_MIN_SCROLL = 900;

export function syncBackToTop(): void {
  const button = document.getElementById('back-to-top');

  if (button === null) {
    return;
  }

  button.hidden = window.scrollY < Math.max(BACK_TO_TOP_MIN_SCROLL, window.innerHeight * 1.2);
}

function setupBackToTop(): void {
  if (document.getElementById('back-to-top') === null) {
    return;
  }

  let ticking = false;

  window.addEventListener(
    'scroll',
    () => {
      if (ticking) {
        return;
      }

      ticking = true;
      window.requestAnimationFrame(() => {
        syncBackToTop();
        ticking = false;
      });
    },
    { passive: true },
  );

  syncBackToTop();
}

// ---------------------------------------------------------------- 用户菜单

function toggleUserMenu(trigger: HTMLElement): void {
  const menu = trigger.closest('[data-user-menu]');
  const panel = menu?.querySelector<HTMLElement>('[data-user-menu-panel]');

  if (panel === null || panel === undefined) {
    return;
  }

  const isOpen = !panel.hasAttribute('hidden');
  closeAllUserMenus();

  if (!isOpen) {
    panel.removeAttribute('hidden');
    trigger.setAttribute('aria-expanded', 'true');
    panel.querySelector<HTMLElement>('a.user-menu__item, button.user-menu__item')?.focus();
  }
}

function closeAllUserMenus(): void {
  const active = document.activeElement;
  let restore: HTMLElement | null = null;

  for (const panel of $$<HTMLElement>('[data-user-menu-panel]')) {
    if (active !== null && panel.contains(active)) {
      restore = panel.closest('[data-user-menu]')?.querySelector<HTMLElement>('[data-action="toggle-user-menu"]') ?? null;
    }

    panel.setAttribute('hidden', '');
  }

  for (const trigger of $$<HTMLElement>('[data-action="toggle-user-menu"]')) {
    trigger.setAttribute('aria-expanded', 'false');
  }

  // 菜单关闭时若焦点在菜单内，还原到触发按钮
  restore?.focus();
}

// ---------------------------------------------------------------- 分享

async function shareLink(button: HTMLElement): Promise<void> {
  const url = button.dataset.shareUrl ?? '';

  if (url === '') {
    return;
  }

  const absolute = new URL(url, window.location.origin).toString();

  let copied = false;

  try {
    if (navigator.clipboard !== undefined) {
      await navigator.clipboard.writeText(absolute);
      copied = true;
    }
  } catch {
    copied = false;
  }

  if (!copied) {
    const input = document.createElement('textarea');
    input.value = absolute;
    input.setAttribute('readonly', '');
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.append(input);
    input.select();

    try {
      copied = document.execCommand('copy');
    } finally {
      input.remove();
    }
  }

  toast(copied ? '链接已复制' : '复制失败，请手动复制链接', copied ? 'success' : 'info');
}

// ---------------------------------------------------------------- 拉黑

async function handleBlockToggle(button: HTMLElement): Promise<void> {
  if ($user.get() === null) {
    toast('登录后可以拉黑用户', 'info');
    return;
  }

  const userId = Number(button.dataset.userId ?? 0);

  if (userId <= 0 || button.classList.contains('is-busy')) {
    return;
  }

  const currentlyBlocked = button.classList.contains('is-blocked');

  if (!currentlyBlocked && !(await confirmDialog('拉黑后双方无法互相关注或私信，已有的关注关系会解除。确定拉黑吗？', {
    title: '拉黑用户',
    danger: true,
    confirmLabel: '拉黑',
  }))) {
    return;
  }

  button.classList.add('is-busy');

  try {
    const { data } = await api.post<{ blocked: boolean }>('/api/v1/blocks/toggle', { user_id: userId });

    button.classList.remove('is-busy');
    toast(data.blocked ? '已拉黑' : '已解除拉黑', 'success');

    if (button.dataset.from === 'settings') {
      button.closest<HTMLElement>('[data-blocked-user]')?.remove();
      return;
    }

    // 个人主页：拉黑会同时移除关注关系，刷新页面保持状态一致
    window.location.reload();
  } catch (error) {
    button.classList.remove('is-busy');
    toastError(error);
  }
}

// ---------------------------------------------------------------- 点赞 / 收藏 / 关注

interface ToggleLikeResponse {
  active: boolean;
  count: number;
}

async function handleLike(button: HTMLElement): Promise<void> {
  if ($user.get() === null) {
    toast('登录后就可以点赞了', 'info');
    return;
  }

  const targetType = button.dataset.targetType;
  const targetId = Number(button.dataset.targetId ?? 0);

  if (!targetType || targetId <= 0 || button.classList.contains('is-busy')) {
    return;
  }

  button.classList.add('is-busy');

  const wasActive = button.classList.contains('is-active');
  button.classList.toggle('is-active', !wasActive);

  try {
    const { data } = await api.post<ToggleLikeResponse>('/api/v1/reactions/toggle', {
      target_type: targetType,
      target_id: targetId,
      kind: 'like',
    });

    button.classList.toggle('is-active', data.active);
    button.setAttribute('aria-pressed', data.active ? 'true' : 'false');

    const countEl = button.querySelector<HTMLElement>('[data-like-count]');
    if (countEl !== null) {
      countEl.textContent = String(data.count);
    }
  } catch (error) {
    button.classList.toggle('is-active', wasActive);
    toastError(error);
  } finally {
    button.classList.remove('is-busy');
  }
}

async function handleFavorite(button: HTMLElement): Promise<void> {
  if ($user.get() === null) {
    toast('登录后可以收藏', 'info');
    return;
  }

  const targetType = button.dataset.targetType;
  const targetId = Number(button.dataset.targetId ?? 0);

  if (!targetType || targetId <= 0 || button.classList.contains('is-busy')) {
    return;
  }

  button.classList.add('is-busy');

  const wasActive = button.classList.contains('is-active');
  button.classList.toggle('is-active', !wasActive);

  try {
    const { data } = await api.post<ToggleLikeResponse>('/api/v1/reactions/toggle', {
      target_type: targetType,
      target_id: targetId,
      kind: 'favorite',
    });

    button.classList.toggle('is-active', data.active);
    button.setAttribute('aria-pressed', data.active ? 'true' : 'false');
    toast(data.active ? '已收藏' : '已取消收藏', 'success');
  } catch (error) {
    button.classList.toggle('is-active', wasActive);
    toastError(error);
  } finally {
    button.classList.remove('is-busy');
  }
}

interface ToggleFollowResponse {
  following: boolean;
  followers_count: number;
}

interface ToggleRepostResponse {
  active: boolean;
  repost_count: number;
}

async function handleRepost(button: HTMLElement): Promise<void> {
  if ($user.get() === null) {
    toast('登录后可以转发', 'info');
    return;
  }

  const postId = Number(button.dataset.targetId ?? 0);

  if (postId <= 0 || button.classList.contains('is-busy')) {
    return;
  }

  button.classList.add('is-busy');

  const wasActive = button.classList.contains('is-active');

  try {
    const { data } = wasActive
      ? await api.delete<ToggleRepostResponse>(`/api/v1/posts/${postId}/repost`)
      : await api.post<ToggleRepostResponse>(`/api/v1/posts/${postId}/repost`);

    button.classList.toggle('is-active', data.active);
    button.setAttribute('aria-pressed', data.active ? 'true' : 'false');

    const countEl = button.querySelector<HTMLElement>('[data-repost-count]');
    if (countEl !== null) {
      countEl.textContent = String(data.repost_count);
    }

    toast(data.active ? '已转发到你的动态' : '已取消转发', 'success');
  } catch (error) {
    toastError(error);
  } finally {
    button.classList.remove('is-busy');
  }
}

async function handleFollow(button: HTMLElement): Promise<void> {
  if ($user.get() === null) {
    toast('登录后可以关注', 'info');
    return;
  }

  const userId = Number(button.dataset.userId ?? 0);

  if (userId <= 0 || button.classList.contains('is-loading')) {
    return;
  }

  setButtonLoading(button, true);

  try {
    const { data } = await api.post<ToggleFollowResponse>('/api/v1/follows/toggle', { user_id: userId });

    setButtonLoading(button, false);

    for (const target of $$<HTMLElement>(`[data-action="toggle-follow"][data-user-id="${userId}"]`)) {
      target.classList.toggle('is-following', data.following);
      target.setAttribute('aria-pressed', data.following ? 'true' : 'false');

      if (target.classList.contains('follow-btn')) {
        target.textContent = data.following ? '已关注' : '关注';

        // 关注按钮的视觉来自按钮族：切换 filled / outlined（尺寸类按 data-follow-size 处理）
        target.classList.toggle('filled-btn', !data.following);
        target.classList.toggle('outlined-btn', data.following);

        if (target.dataset.followSize === 'sm') {
          target.classList.toggle('filled-btn--sm', !data.following);
          target.classList.toggle('outlined-btn--sm', data.following);
        }
      }
    }

    toast(data.following ? '已关注' : '已取消关注', 'success');
  } catch (error) {
    setButtonLoading(button, false);
    toastError(error);
  }
}

// ---------------------------------------------------------------- 删除

async function handleDeletePost(button: HTMLElement): Promise<void> {
  const postId = Number(button.dataset.postId ?? 0);

  if (postId <= 0) {
    return;
  }

  if (!(await confirmDialog('确定删除这条动态吗？删除后不可恢复。', { danger: true, confirmLabel: '删除' }))) {
    return;
  }

  try {
    await api.delete(`/api/v1/posts/${postId}`);
    toast('已删除', 'success');
    window.location.href = '/';
  } catch (error) {
    toastError(error);
  }
}

async function handleDeleteConfession(button: HTMLElement): Promise<void> {
  const confessionId = Number(button.dataset.confessionId ?? 0);

  if (confessionId <= 0) {
    return;
  }

  if (!(await confirmDialog('确定删除这条投稿吗？删除后不可恢复。', { danger: true, confirmLabel: '删除' }))) {
    return;
  }

  try {
    await api.delete(`/api/v1/confessions/${confessionId}`);
    toast('已删除', 'success');

    const detail = button.closest<HTMLElement>('.confession-detail');

    if (detail !== null) {
      const board = detail.dataset.board === 'hole' ? '/hole' : '/wall';
      window.location.href = board;
      return;
    }

    button.closest<HTMLElement>('.confession-card, .my-confession')?.remove();
  } catch (error) {
    toastError(error);
  }
}

async function handleMarkAllRead(): Promise<void> {
  try {
    await api.post('/api/v1/notifications/read', {});
    $$('.notification.is-unread').forEach((item) => item.classList.remove('is-unread'));
    $unreadCount.set(0);
    toast('已全部标记为已读', 'success');
  } catch (error) {
    toastError(error);
  }
}

// ---------------------------------------------------------------- 灯箱

function openLightbox(src: string): void {
  const box = $('#lightbox');
  const image = box?.querySelector<HTMLImageElement>('.lightbox__img');

  if (box === null || image === null || image === undefined || src === '') {
    return;
  }

  lightboxTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  image.src = src;
  box.removeAttribute('hidden');
  document.body.classList.add('lightbox-open');

  // 焦点移入灯箱（关闭按钮），Esc / 关闭后还原到触发元素
  box.querySelector<HTMLElement>('.lightbox__close')?.focus();
}

function closeLightbox(): void {
  const box = $('#lightbox');

  if (box === null || box.hasAttribute('hidden')) {
    return;
  }

  box.setAttribute('hidden', '');
  document.body.classList.remove('lightbox-open');

  const image = box.querySelector<HTMLImageElement>('.lightbox__img');
  if (image !== null) {
    image.src = '';
  }

  lightboxTrigger?.focus();
  lightboxTrigger = null;
}
