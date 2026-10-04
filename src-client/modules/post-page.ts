import { api } from '@/core/api';
import { confirmDialog } from '@/core/dialog';
import { $, $$, escapeHtml } from '@/core/dom';
import { renderComment } from './comment-render';
import { setButtonLoading } from '@/core/loading';
import { toast, toastError } from '@/core/toast';
import type { CommentItem } from '@/types';
import type { PageModule } from './types';

/** Twikoo 全局对象（twikoo.all.min.js 注入） */
interface TwikooGlobal {
  init: (opts: Record<string, unknown>) => Promise<unknown>;
}

const twikooGlobal = (): TwikooGlobal | undefined =>
  (window as unknown as { twikoo?: TwikooGlobal }).twikoo;

/**
 * 与 Twikoo 服务端配套的备选脚本源。
 * 首选配置里的 scriptUrl（默认是站内自托管副本 public/vendor/twikoo.all.min.js），
 * 它缺失/不可用时才回退 CDN；版本必须与后端一致（本项目后端为 2.0.12）。
 */
const TWIKOO_CDN_FALLBACKS: readonly string[] = [
  'https://registry.npmmirror.com/twikoo/2.0.12/files/dist/twikoo.all.min.js',
  'https://cdn.jsdelivr.net/npm/twikoo@2.0.12/dist/twikoo.all.min.js',
  'https://unpkg.com/twikoo@2.0.12/dist/twikoo.all.min.js',
];

/**
 * 接入 Twikoo（外部评论系统）。
 * 若配置了 twikoo.envId，则隐藏站内评论 UI，在挂载点初始化 Twikoo；否则保留站内评论。
 *
 * 关键约束：站内评论只能在 Twikoo **真的渲染出内容**之后才隐藏。
 * 之前是一调用就把站内 UI 隐掉，脚本/CDN 异常时页面只剩一块空白评论区，
 * 而且错抛出去会让整个 post 页面模块挂掉（回复/加载更多/删除全都不响应）。
 *
 * 另外脚本按需加载：评论区进入视口才开始拉（Shirone 的 lazy 策略），
 * 首屏不产生任何评论相关的网络开销。
 */
function initTwikoo(section: HTMLElement, postId: number): void {
  const config = window.__MOFU__?.twikoo;
  const envId = config?.envId;

  if (!envId) {
    return;
  }

  const mount = section.querySelector<HTMLElement>('[data-twikoo]');
  if (mount === null || mount === undefined) {
    return;
  }

  // 只隐藏站内评论自身的 UI；不能隐藏父级 section——Twikoo 挂载点就在同一 section 内。
  const nativeNodes = (): NodeListOf<HTMLElement> =>
    section.querySelectorAll<HTMLElement>(
      '[data-comment-list], [data-comment-form], .comments__guest, .comments__more, .comments__title',
    );

  const useNativeComments = (reason?: 'offline'): void => {
    for (const node of nativeNodes()) {
      node.removeAttribute('hidden');
    }

    mount.setAttribute('hidden', '');

    if (reason === 'offline') {
      showOfflineHint();
    }
  };

  /**
   * 后端连不上时的提示。只对管理员显示：
   * 普通访客看到的是能用的站内评论，不需要知道 Twikoo 怎么了。
   */
  const showOfflineHint = (): void => {
    if (window.__MOFU__?.user?.can_admin !== true || section.querySelector('[data-comment-fallback-hint]') !== null) {
      return;
    }

    const hint = document.createElement('p');
    hint.className = 'comments__fallback';
    hint.setAttribute('data-comment-fallback-hint', '');
    hint.textContent = 'Twikoo 评论服务当前不可达，已回退为站内评论（检查 TWIKOO_ENV_ID 与服务器/访客网络到该地址的连通性）';

    section.querySelector('[data-comment-list]')?.before(hint);
  };

  /** 探测 Twikoo 后端是否可达：不可达就别初始化，直接用站内评论 */
  const backendReachable = async (): Promise<boolean> => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 6000);

    try {
      const response = await fetch(envId, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event: 'GET_FUNC_VERSION' }),
        mode: 'cors',
        credentials: 'omit',
        signal: controller.signal,
      });

      if (!response.ok) {
        return false;
      }

      const data: unknown = await response.json();

      return typeof (data as { version?: unknown }).version === 'string';
    } catch {
      return false;
    } finally {
      window.clearTimeout(timer);
    }
  };

  /** Twikoo 渲染出内容后才接管：返回是否已接管 */
  const adoptTwikoo = (): boolean => {
    if (mount.childElementCount === 0) {
      return false;
    }

    for (const node of nativeNodes()) {
      node.setAttribute('hidden', '');
    }

    mount.removeAttribute('hidden');

    return true;
  };

  const loadTwikoo = (): void => {
    const twikoo = twikooGlobal();

    // 脚本未就绪或不是可用的 Twikoo（CDN 返回空对象等）：保留站内评论
    if (twikoo === undefined || typeof twikoo.init !== 'function') {
      useNativeComments();

      return;
    }

    try {
      const result: unknown = twikoo.init({
        envId,
        el: mount,
        path: `/posts/${postId}`,
        lang: config?.lang ?? 'zh-CN',
        onCommentLoaded: () => adoptTwikoo(),
      });

      if (result instanceof Promise) {
        void result.then(() => adoptTwikoo()).catch(() => useNativeComments());
      }
    } catch (error) {
      console.error('[mofu] twikoo init failed, falling back to native comments', error);
      useNativeComments();

      return;
    }

    // 兜底：初始化后仍未渲染任何内容，说明 Twikoo 后端不可用，回退站内评论
    for (const delay of [1200, 4000, 8000]) {
      window.setTimeout(() => {
        if (mount.childElementCount > 0) {
          adoptTwikoo();
        } else if (delay === 8000) {
          useNativeComments();
        }
      }, delay);
    }
  };

  /** 开始接入：后端可达且脚本就绪才初始化，否则回退站内评论 */
  const start = async (): Promise<void> => {
    // 注意：window.twikoo 存在不代表能用——之前 CDN 半途失败会留下一个空对象，
    // 那种情况仍然要自己注入脚本，否则永远回退站内评论。
    if (typeof twikooGlobal()?.init !== 'function') {
      // 先探一次后端：连不上（DNS 污染/CORS/后端挂了）就不要再初始化，
      // 否则会出现「组件加载了、评论空着」的尴尬状态。
      if (!(await backendReachable())) {
        console.warn('[mofu] twikoo backend unreachable, keeping native comments');
        useNativeComments('offline');

        return;
      }

      const sources = [config?.scriptUrl ?? '', ...TWIKOO_CDN_FALLBACKS].filter((src) => src !== '');

      const inject = (index: number): void => {
        const src = sources[index];

        if (src === undefined) {
          return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = loadTwikoo;
        script.onerror = () => inject(index + 1);
        document.head.appendChild(script);
      };

      inject(0);

      return;
    }

    if (!(await backendReachable())) {
      console.warn('[mofu] twikoo backend unreachable, keeping native comments');
      useNativeComments('offline');

      return;
    }

    loadTwikoo();
  };

  // 懒加载：评论区进入视口前不加载任何评论脚本
  if (!('IntersectionObserver' in window)) {
    void start();

    return;
  }

  const observer = new IntersectionObserver(
    (entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        observer.disconnect();
        void start();
      }
    },
    { rootMargin: '200px' },
  );

  observer.observe(section);
}


export const init: PageModule['init'] = (): (() => void) | void => {
  const section = $<HTMLElement>('[data-comments]');

  if (section === null) {
    return;
  }

  const postId = Number(section.dataset.postId ?? 0);

  const list = $<HTMLElement>('[data-comment-list]', section);
  const form = $<HTMLFormElement>('[data-comment-form]', section);
  const textarea = form?.querySelector<HTMLTextAreaElement>('textarea');
  const totalEl = $<HTMLElement>('[data-comment-total]');
  const moreButton = $<HTMLButtonElement>('[data-action="load-comments"]', section);

  if (postId <= 0 || list === null) {
    return;
  }

  /**
   * 内联回复编辑器：点「回复」就地在该条评论下方展开（Twikoo 式），
   * 不再把回复输入框放到页面顶部的表单里。
   */
  const replyBox = (): HTMLElement | null => $<HTMLElement>('[data-reply-box]', section);

  const closeReplyBox = (): void => {
    replyBox()?.remove();
  };

  const openReplyBox = (commentEl: HTMLElement, commentId: number, name: string): void => {
    closeReplyBox();

    const body = commentEl.querySelector<HTMLElement>(':scope > .comment__body');

    if (body === null) {
      return;
    }

    const box = document.createElement('div');
    box.className = 'comment-reply-box';
    box.setAttribute('data-reply-box', '');
    box.dataset.replyTo = String(commentId);
    box.innerHTML = `
      <textarea class="comment-reply-box__input" rows="2" maxlength="1000" placeholder="回复 ${escapeHtml(name)}…"></textarea>
      <div class="comment-reply-box__foot">
        <button class="text-btn text-btn--sm" type="button" data-action="cancel-reply">取消</button>
        <button class="filled-btn filled-btn--sm" type="button" data-action="submit-reply">发送</button>
      </div>`;

    // 插在子回复列表之前，让回复列表保持在最下方
    const replies = body.querySelector<HTMLElement>(':scope > .comment__replies');

    if (replies === null) {
      body.append(box);
    } else {
      body.insertBefore(box, replies);
    }

    box.querySelector<HTMLTextAreaElement>('textarea')?.focus();
  };

  /** 把服务端返回的回复挂到父评论下的回复列表（没有容器就建一个） */
  const appendReply = (parentId: number, comment: CommentItem): void => {
    const parent = section.querySelector<HTMLElement>(`.comment[data-comment-id="${parentId}"]`);
    const body = parent?.querySelector<HTMLElement>(':scope > .comment__body');

    if (body === null || body === undefined) {
      return;
    }

    let replies = body.querySelector<HTMLElement>(':scope > .comment__replies');

    if (replies === null) {
      replies = document.createElement('div');
      replies.className = 'comment__replies';
      body.querySelector<HTMLElement>('[data-reply-box]')?.remove();
      body.append(replies);
    }

    replies.append(renderComment(comment, { isReply: true }));
  };

  const bumpTotal = (delta: number): void => {
    if (totalEl !== null) {
      const current = Number(totalEl.textContent ?? '0');
      totalEl.textContent = String(Math.max(0, current + delta));
    }
  };

  const removeComment = async (commentId: number): Promise<void> => {
    if (commentId <= 0) {
      return;
    }

    if (!(await confirmDialog('确定删除这条评论吗？', { danger: true, confirmLabel: '删除' }))) {
      return;
    }

    try {
      await api.delete(`/api/v1/comments/${commentId}`);
      section.querySelector(`.comment[data-comment-id="${commentId}"]`)?.remove();
      bumpTotal(-1);
      toast('已删除', 'success');
    } catch (error) {
      toastError(error);
    }
  };

  const loadMore = async (button: HTMLButtonElement): Promise<void> => {
    const page = Number(button.dataset.nextPage ?? 2);
    setButtonLoading(button, true);

    try {
      const { data, meta } = await api.get<CommentItem[]>(`/api/v1/posts/${postId}/comments?page=${page}`);

      for (const comment of data) {
        list.append(renderComment(comment, { isReply: false }));
      }

      const pages = meta.pagination?.pages ?? 1;

      if (page >= pages) {
        button.remove();
      } else {
        button.dataset.nextPage = String(page + 1);
      }
    } catch (error) {
      toastError(error);
    } finally {
      setButtonLoading(button, false);
    }
  };

  const onClick = (event: Event): void => {
    const trigger = (event.target as HTMLElement).closest<HTMLElement>('[data-action]');

    if (trigger === null) {
      return;
    }

    switch (trigger.dataset.action) {
      case 'reply': {
        event.preventDefault();
        // 注意：回复按钮自身也带 data-comment-id，closest 必须先限定在 .comment 容器上
        const commentEl = trigger.closest<HTMLElement>('.comment[data-comment-id]');

        if (commentEl !== null) {
          openReplyBox(commentEl, Number(commentEl.dataset.commentId ?? 0), trigger.dataset.replyName ?? '');
        }
        break;
      }

      case 'cancel-reply':
        event.preventDefault();
        closeReplyBox();
        break;

      case 'submit-reply':
        event.preventDefault();
        void onReplySubmit(trigger as HTMLButtonElement);
        break;

      case 'remove-comment':
        event.preventDefault();
        void removeComment(Number(trigger.dataset.commentId ?? 0));
        break;

      case 'load-comments':
        event.preventDefault();
        void loadMore(trigger as HTMLButtonElement);
        break;
    }
  };

  const onReplySubmit = async (button: HTMLButtonElement): Promise<void> => {
    const box = button.closest<HTMLElement>('[data-reply-box]');
    const input = box?.querySelector<HTMLTextAreaElement>('textarea');
    const parentId = Number(box?.dataset.replyTo ?? 0);
    const content = input?.value.trim() ?? '';

    if (box === null || input == null || parentId <= 0) {
      return;
    }

    if (content === '') {
      toast('先写点什么吧', 'info');
      return;
    }

    setButtonLoading(button, true);

    try {
      const { data } = await api.post<CommentItem & { replies: CommentItem[] }>('/api/v1/comments', {
        post_id: postId,
        content,
        parent_id: parentId,
      });

      box.remove();
      appendReply(parentId, data);
      bumpTotal(1);
      toast('回复成功', 'success');
    } catch (error) {
      setButtonLoading(button, false);
      toastError(error);
    }
  };

  const onSubmit = async (event: Event): Promise<void> => {
    event.preventDefault();

    if (textarea == null) {
      return;
    }

    const content = textarea.value.trim();

    if (content === '') {
      toast('先写点什么吧', 'info');
      return;
    }

    const submitButton = form?.querySelector<HTMLButtonElement>('button[type="submit"]');

    if (submitButton != null) {
      submitButton.disabled = true;
      setButtonLoading(submitButton, true);
    }

    try {
      // 顶部表单只发顶层评论；回复走各评论下方的内联编辑器
      const { data } = await api.post<CommentItem & { replies: CommentItem[] }>('/api/v1/comments', {
        post_id: postId,
        content,
        parent_id: null,
      });

      // 顶层评论：移除空状态后追加
      section.querySelector('[data-empty-state]')?.remove();
      list.append(renderComment(data, { isReply: false }));

      bumpTotal(1);
      textarea.value = '';
      toast('评论成功', 'success');
    } catch (error) {
      toastError(error);
    } finally {
      if (submitButton != null) {
        submitButton.disabled = false;
        setButtonLoading(submitButton, false);
      }
    }
  };

  section.addEventListener('click', onClick);
  form?.addEventListener('submit', onSubmit);

  // 放在站内评论绑定之后：Twikoo 脚本异常也不能影响评论的回复/删除/加载更多
  if (postId > 0) {
    try {
      initTwikoo(section, postId);
    } catch (error) {
      console.error('[mofu] twikoo setup failed, keeping native comments', error);
    }
  }

  return () => {
    section.removeEventListener('click', onClick);
    form?.removeEventListener('submit', onSubmit);
  };
};

/** 供其它模块参考：当前页评论区元素 */
export function commentSection(): HTMLElement | null {
  return $('[data-comments]');
}

export { renderComment };

/** 辅助：统计页面所有评论节点（调试用） */
export function countCommentNodes(): number {
  return $$('.comment').length;
}
