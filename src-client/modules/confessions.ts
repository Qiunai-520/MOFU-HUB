import { api } from '@/core/api';
import { confirmDialog } from '@/core/dialog';
import { $, $$, escapeHtml } from '@/core/dom';
import { renderComment } from './comment-render';
import { setButtonLoading } from '@/core/loading';
import { toast, toastError } from '@/core/toast';
import type { ConfessionCommentItem } from '@/types';
import type { PageModule } from './types';

interface ConfessionUpload {
  id: number;
  thumb: string;
}

/**
 * 表白墙 / 树洞页面模块：
 * - /wall/new、/hole/new 投稿表单
 * - /wall/{id}、/hole/{id} 详情评论区
 */
export const init: PageModule['init'] = (): (() => void) | void => {
  const composeRoot = $<HTMLElement>('[data-confession-compose]');

  if (composeRoot !== null) {
    return initCompose(composeRoot);
  }

  const detailRoot = $<HTMLElement>('[data-confession-comments]');

  if (detailRoot !== null) {
    return initDetail(detailRoot);
  }

  return;
};

// ---------------------------------------------------------------- 投稿

function initCompose(root: HTMLElement): (() => void) | void {
  const board = root.dataset.board ?? 'wall';
  const form = $<HTMLFormElement>('[data-confession-form]', root);
  const input = $<HTMLTextAreaElement>('[data-confession-input]', root);
  const categoryGroup = $<HTMLElement>('[data-category-group]', root);
  const fileInput = $<HTMLInputElement>('[data-confession-file]', root);
  const mediaBox = $<HTMLElement>('[data-confession-media]', root);
  const modeSelect = $<HTMLSelectElement>('[data-confession-mode]', root);
  const submitButton = $<HTMLButtonElement>('[data-confession-submit]', root);
  const errorBox = $<HTMLElement>('[data-confession-error]', root);

  if (form === null || input === null) {
    return;
  }

  let category = categoryGroup?.querySelector<HTMLElement>('.topic-chip.is-active')?.dataset.category ?? '';
  const mediaIds: number[] = [];
  const MAX_MEDIA = 4;

  const onCategoryClick = (event: Event): void => {
    const chip = (event.target as HTMLElement).closest<HTMLElement>('[data-category]');

    if (chip === null || categoryGroup === null) {
      return;
    }

    for (const item of $$('.topic-chip', categoryGroup)) {
      item.classList.toggle('is-active', item === chip);
      item.setAttribute('aria-pressed', item === chip ? 'true' : 'false');
    }

    category = chip.dataset.category ?? '';
  };

  const autoGrow = (): void => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 360)}px`;
  };

  const addPreview = (media: ConfessionUpload): void => {
    if (mediaBox === null) {
      return;
    }

    mediaBox.removeAttribute('hidden');

    const item = document.createElement('div');
    item.className = 'compose-media__item';
    item.dataset.mediaId = String(media.id);
    item.innerHTML = `<img src="${escapeHtml(media.thumb)}" alt="预览">`;

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'compose-media__remove';
    remove.setAttribute('aria-label', '移除图片');
    remove.textContent = '×';
    remove.addEventListener('click', () => {
      const index = mediaIds.indexOf(media.id);
      if (index >= 0) {
        mediaIds.splice(index, 1);
      }
      item.remove();

      if (mediaIds.length === 0) {
        mediaBox.setAttribute('hidden', '');
      }
    });

    item.append(remove);
    mediaBox.append(item);
  };

  const onFileChange = async (): Promise<void> => {
    if (fileInput === null) {
      return;
    }

    const files = Array.from(fileInput.files ?? []);
    fileInput.value = '';

    for (const file of files) {
      if (mediaIds.length >= MAX_MEDIA) {
        toast(`最多上传 ${MAX_MEDIA} 张图片`, 'info');
        break;
      }

      try {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await api.postForm<ConfessionUpload>('/api/v1/uploads', formData);
        mediaIds.push(data.id);
        addPreview(data);
      } catch (error) {
        toastError(error);
      }
    }
  };

  const onSubmit = async (event: Event): Promise<void> => {
    event.preventDefault();

    const content = input.value.trim();

    if (content === '') {
      toast('写点内容再投稿吧', 'info');
      return;
    }

    if (submitButton !== null) {
      submitButton.disabled = true;
      setButtonLoading(submitButton, true);
    }

    if (errorBox !== null) {
      errorBox.hidden = true;
    }

    try {
      const { data } = await api.post<{ id: number; pending: boolean; url: string }>('/api/v1/confessions', {
        board,
        category,
        content,
        mode: modeSelect?.value ?? 'anonymous',
        media_ids: mediaIds,
      });

      if (data.pending) {
        toast('投稿已提交，等待审核通过后展示', 'success');
        window.location.href = '/my/confessions';
      } else {
        toast('发布成功', 'success');
        window.location.href = data.url;
      }
    } catch (error) {
      if (errorBox !== null) {
        errorBox.textContent = error instanceof Error ? error.message : '投稿失败，请稍后重试';
        errorBox.hidden = false;
      }

      toastError(error);

      if (submitButton !== null) {
        submitButton.disabled = false;
        setButtonLoading(submitButton, false);
      }
    }
  };

  categoryGroup?.addEventListener('click', onCategoryClick);
  input.addEventListener('input', autoGrow);
  fileInput?.addEventListener('change', () => void onFileChange());
  form.addEventListener('submit', onSubmit);

  autoGrow();

  return () => {
    categoryGroup?.removeEventListener('click', onCategoryClick);
    input.removeEventListener('input', autoGrow);
    form.removeEventListener('submit', onSubmit);
  };
}

// ---------------------------------------------------------------- 详情评论


function initDetail(section: HTMLElement): (() => void) | void {
  const confessionId = Number(section.dataset.confessionId ?? 0);
  const list = $<HTMLElement>('[data-confession-comment-list]', section);
  const form = $<HTMLFormElement>('[data-confession-comment-form]', section);
  const textarea = form?.querySelector<HTMLTextAreaElement>('textarea');
  const totalEl = $<HTMLElement>('[data-comment-total]');
  const moreButton = $<HTMLButtonElement>('[data-action="load-confession-comments"]', section);

  if (confessionId <= 0 || list === null) {
    return;
  }

  /** 内联回复编辑器：点「回复」就地在该条回应下方展开（与帖子评论一致的 Twikoo 式交互） */
  const closeReplyBox = (): void => {
    $<HTMLElement>('[data-reply-box]', section)?.remove();
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

    const replies = body.querySelector<HTMLElement>(':scope > .comment__replies');

    if (replies === null) {
      body.append(box);
    } else {
      body.insertBefore(box, replies);
    }

    box.querySelector<HTMLTextAreaElement>('textarea')?.focus();
  };

  /** 把服务端返回的回复挂到父回应下的回复列表 */
  const appendReply = (parentId: number, comment: ConfessionCommentItem): void => {
    const parent = section.querySelector<HTMLElement>(`.comment[data-comment-id="${parentId}"]`);
    const body = parent?.querySelector<HTMLElement>(':scope > .comment__body');

    if (body == null) {
      return;
    }

    let replies = body.querySelector<HTMLElement>(':scope > .comment__replies');

    if (replies === null) {
      replies = document.createElement('div');
      replies.className = 'comment__replies';
      body.append(replies);
    }

    replies.append(renderComment(comment, { isReply: true, anonymous: true }));
  };

  const bumpTotal = (delta: number): void => {
    if (totalEl !== null) {
      totalEl.textContent = String(Math.max(0, Number(totalEl.textContent ?? '0') + delta));
    }
  };

  const removeComment = async (commentId: number): Promise<void> => {
    if (commentId <= 0) {
      return;
    }

    if (!(await confirmDialog('确定删除这条回应吗？', { danger: true, confirmLabel: '删除' }))) {
      return;
    }

    try {
      await api.delete(`/api/v1/confession-comments/${commentId}`);
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
      const { data, meta } = await api.get<ConfessionCommentItem[]>(`/api/v1/confessions/${confessionId}/comments?page=${page}`);

      for (const comment of data) {
        list.append(renderComment(comment, { isReply: false, anonymous: true }));
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
        // 回复按钮自身也带 data-comment-id，必须先限定到 .comment 容器
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

      case 'load-confession-comments':
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
      const { data } = await api.post<ConfessionCommentItem>('/api/v1/confession-comments', {
        confession_id: confessionId,
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
      // 顶部表单只发顶层回应；回复走内联编辑器
      const { data } = await api.post<ConfessionCommentItem>('/api/v1/confession-comments', {
        confession_id: confessionId,
        content,
        parent_id: null,
      });

      section.querySelector('[data-empty-state]')?.remove();
      list.append(renderComment(data, { isReply: false, anonymous: true }));

      bumpTotal(1);
      textarea.value = '';
      toast('回应成功', 'success');
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

  return () => {
    section.removeEventListener('click', onClick);
    form?.removeEventListener('submit', onSubmit);
  };
}
