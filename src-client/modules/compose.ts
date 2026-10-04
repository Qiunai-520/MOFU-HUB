import { api } from '@/core/api';
import { $, el } from '@/core/dom';
import { setButtonLoading } from '@/core/loading';
import { toast, toastError } from '@/core/toast';
import type { PageModule } from './types';

interface UploadResponse {
  id: number;
  kind?: string;
  url: string;
  thumb: string;
}

interface MentionUser {
  id: number;
  username: string;
  nickname: string;
  avatar: string;
}

const MAX_MEDIA = 9;
const MAX_TOPICS = 5;

const EMOJIS = [
  '😀', '😂', '🥰', '😊', '😍', '🤔', '😎', '😭',
  '😅', '😉', '🙃', '🥳', '😴', '🤗', '😏', '😢',
  '👍', '👎', '👏', '🙏', '💪', '🤝', '👀', '🙌',
  '❤️', '🧡', '💛', '💚', '💙', '💜', '💖', '💯',
  '✨', '🔥', '🌈', '⭐', '🎉', '🎊', '🌸', '🍀',
  '⚡', '☀️', '🌙', '🍰', '🧋', '🎧', '🏀', '⚽',
];

const DRAFT_PREFIX = 'mofu.draft.compose.';

function parseStringArray(raw: string | undefined): string[] {
  if (raw === undefined || raw === '') {
    return [];
  }

  try {
    const decoded: unknown = JSON.parse(raw);

    return Array.isArray(decoded) ? decoded.filter((item): item is string => typeof item === 'string') : [];
  } catch {
    return [];
  }
}

function parseMediaArray(raw: string | undefined): EditMediaPreview[] {
  if (raw === undefined || raw === '') {
    return [];
  }

  try {
    const decoded: unknown = JSON.parse(raw);

    if (!Array.isArray(decoded)) {
      return [];
    }

    return decoded
      .filter((item): item is Record<string, unknown> => typeof item === 'object' && item !== null)
      .map((item) => ({ thumb: typeof item['thumb'] === 'string' ? item['thumb'] : '' }))
      .filter((item) => item.thumb !== '');
  } catch {
    return [];
  }
}

interface EditMediaPreview {
  thumb: string;
}

/** 表情快捷面板：点击按钮开关，点击 emoji 插入光标处。 */
function setupEmojiPanel(input: HTMLTextAreaElement, root: HTMLElement): () => void {
  const toggle = $<HTMLButtonElement>('[data-action="toggle-emoji"]', root);
  const panel = $<HTMLElement>('[data-emoji-panel]', root);

  if (toggle === null || panel === null) {
    return () => undefined;
  }

  const hide = (): void => {
    panel.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  };

  const render = (): void => {
    panel.replaceChildren(
      ...EMOJIS.map((emoji) => {
        const button = el('button', { class: 'emoji-panel__item', type: 'button' }, [emoji]);
        button.addEventListener('click', () => {
          insertAtCursor(input, emoji);
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.focus();
        });

        return button;
      }),
    );
  };

  toggle.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();

    if (panel.hidden) {
      render();
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
    } else {
      hide();
    }
  });

  document.addEventListener('click', (event) => {
    if (!panel.contains(event.target as Node) && !toggle.contains(event.target as Node)) {
      hide();
    }
  });

  return hide;
}

/** 在 textarea 光标处插入文本。 */
function insertAtCursor(textarea: HTMLTextAreaElement, text: string): void {
  const start = textarea.selectionStart ?? textarea.value.length;
  const end = textarea.selectionEnd ?? textarea.value.length;
  textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
  const pos = start + text.length;
  textarea.selectionStart = pos;
  textarea.selectionEnd = pos;
  textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

/** @提及：输入 @ 后弹出用户候选，方向键选择，Enter/Tab 选中并替换为 @用户名。 */
function setupMention(input: HTMLTextAreaElement, root: HTMLElement): () => void {
  const panel = $<HTMLElement>('[data-mention-panel]', root);

  if (panel === null) {
    return () => undefined;
  }

  let keyword = '';
  let items: MentionUser[] = [];
  let activeIndex = 0;
  let searchTimer: number | undefined;

  const hide = (): void => {
    panel.hidden = true;
    items = [];
    keyword = '';
  };

  const insertMention = (user: MentionUser): void => {
    const value = input.value;
    const cursor = input.selectionStart ?? value.length;
    const before = value.slice(0, cursor);
    const at = before.lastIndexOf('@');
    const after = value.slice(cursor);

    input.value = before.slice(0, at) + `@${user.username} ` + after;
    const pos = at + user.username.length + 2;
    input.selectionStart = pos;
    input.selectionEnd = pos;
    hide();
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
  };

  const render = (): void => {
    panel.replaceChildren(
      ...items.map((user, index) => {
        const item = el('button', {
          class: 'mention-panel__item' + (index === activeIndex ? ' is-active' : ''),
          type: 'button',
          role: 'option',
          'aria-selected': index === activeIndex ? 'true' : 'false',
        }, [
          el('img', { class: 'mention-panel__avatar', src: user.avatar || '/avatar-default.svg', alt: '' }),
          el('span', { class: 'mention-panel__name' }, [user.nickname]),
          el('span', { class: 'mention-panel__username' }, [`@${user.username}`]),
        ]);

        item.addEventListener('click', () => insertMention(user));
        item.addEventListener('mousemove', () => {
          activeIndex = index;
          render();
        });

        return item;
      }),
    );

    if (items.length > 0) {
      panel.hidden = false;
    } else {
      panel.hidden = true;
    }
  };

  const search = async (): Promise<void> => {
    if (keyword.length < 2) {
      panel.hidden = true;
      return;
    }

    try {
      const { data } = await api.get<MentionUser[]>(`/api/v1/users/search?q=${encodeURIComponent(keyword)}`);
      items = data ?? [];
      activeIndex = 0;
      render();
    } catch (error) {
      panel.hidden = true;
      toastError(error);
    }
  };

  const onInput = (): void => {
    const cursor = input.selectionStart ?? input.value.length;
    const before = input.value.slice(0, cursor);
    const at = before.lastIndexOf('@');

    // 前面没有 @，或 @ 前还有非空字符（已是完整词）时隐藏
    if (at < 0 || /[\w\u4e00-\u9fa5]/.test(before.slice(0, at).slice(-1) ?? '')) {
      hide();
      return;
    }

    const rawKeyword = before.slice(at + 1);

    if (rawKeyword.includes(' ') || rawKeyword.includes('\n')) {
      hide();
      return;
    }

    keyword = rawKeyword;

    if (keyword === '') {
      panel.hidden = true;
      return;
    }

    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => void search(), 250);
  };

  const onKeydown = (event: KeyboardEvent): void => {
    if (panel.hidden || items.length === 0) {
      return;
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activeIndex = (activeIndex + 1) % items.length;
      render();
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      activeIndex = (activeIndex - 1 + items.length) % items.length;
      render();
    } else if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault();
      const mention = items[activeIndex] ?? items[0];

      if (mention !== undefined) {
        insertMention(mention);
      }
    } else if (event.key === 'Escape') {
      event.preventDefault();
      hide();
    }
  };

  input.addEventListener('input', onInput);
  input.addEventListener('keydown', onKeydown);

  return () => {
    input.removeEventListener('input', onInput);
    input.removeEventListener('keydown', onKeydown);
    window.clearTimeout(searchTimer);
  };
}

/** 草稿：防抖保存到 localStorage，发布成功后清除。 */
function setupDraft(
  input: HTMLTextAreaElement,
  root: HTMLElement,
  isEdit: boolean,
  communityId: string,
): () => void {
  const hint = $<HTMLElement>('[data-draft-hint]', root);

  if (isEdit || hint === null) {
    return () => undefined;
  }

  const key = DRAFT_PREFIX + (communityId === '' ? 'post' : `circle-${communityId}`);
  let timer: number | undefined;

  const restore = (): void => {
    try {
      const raw = localStorage.getItem(key);
      if (raw) {
        const draft = JSON.parse(raw) as { content?: string; savedAt?: string };
        if (typeof draft.content === 'string' && draft.content.trim() !== '') {
          input.value = draft.content;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          if (draft.savedAt) {
            hint.textContent = `已恢复草稿（${draft.savedAt}）`;
            hint.hidden = false;
          }
        }
      }
    } catch {
      localStorage.removeItem(key);
    }
  };

  const save = (): void => {
    if (input.value.trim() === '') {
      localStorage.removeItem(key);
      hint.hidden = true;
      return;
    }

    const savedAt = new Date().toLocaleString('zh-CN', { hour: '2-digit', minute: '2-digit' });
    localStorage.setItem(key, JSON.stringify({ content: input.value, savedAt }));
    hint.textContent = `已自动保存草稿 ${savedAt}`;
    hint.hidden = false;
  };

  const onInput = (): void => {
    window.clearTimeout(timer);
    timer = window.setTimeout(save, 800);
  };

  restore();
  input.addEventListener('input', onInput);

  return () => {
    window.clearTimeout(timer);
    input.removeEventListener('input', onInput);
  };
}

export const init: PageModule['init'] = (): (() => void) | void => {
  const root = $<HTMLElement>('[data-compose]');

  if (root === null) {
    return;
  }

  const form = $<HTMLFormElement>('[data-compose-form]', root);
  const input = $<HTMLTextAreaElement>('[data-compose-input]', root);
  const fileInput = $<HTMLInputElement>('[data-compose-file]', root);
  const mediaBox = $<HTMLElement>('[data-compose-media]', root);
  const chipsBox = $<HTMLElement>('[data-compose-chips]', root);
  const topicInput = $<HTMLInputElement>('[data-topic-input]', root);
  const visibilitySelect = $<HTMLSelectElement>('[data-compose-visibility]', root);
  const submitButton = $<HTMLButtonElement>('[data-compose-submit]', root);
  const errorBox = $<HTMLElement>('[data-compose-error]', root);

  if (form === null || input === null) {
    return;
  }

  const editPostId = root.dataset.editPostId ?? '';
  const communityId = root.dataset.communityId ?? '';
  const editTopics = parseStringArray(root.dataset.editTopics);
  const editMedia = parseMediaArray(root.dataset.editMedia);
  const hasExistingMedia = editMedia.length > 0;

  const mediaIds: number[] = [];
  const topics: string[] = [];
  let videoId: number | null = null;

  const autoGrow = (): void => {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 420)}px`;
  };

  const renderChips = (): void => {
    if (chipsBox === null) {
      return;
    }

    chipsBox.replaceChildren(
      ...topics.map((topic, index) =>
        el('span', { class: 'topic-chip topic-chip--editable' }, [
          `#${topic}`,
          (() => {
            const remove = el('button', { class: 'topic-chip__remove', type: 'button', 'aria-label': `移除话题 ${topic}` }, ['×']);
            remove.addEventListener('click', () => {
              topics.splice(index, 1);
              renderChips();
            });

            return remove;
          })(),
        ]),
      ),
    );
  };

  // 编辑模式：展示已有图片（只读）并预填话题
  if (mediaBox !== null && editMedia.length > 0) {
    mediaBox.removeAttribute('hidden');

    for (const media of editMedia) {
      mediaBox.append(el('div', { class: 'compose-media__item' }, [el('img', { src: media.thumb, alt: '已有图片' })]));
    }
  }

  if (editTopics.length > 0) {
    topics.push(...editTopics);
    renderChips();
  }

  const addTopic = (raw: string): void => {
    const name = raw.trim().replace(/^#+/, '');

    if (name === '' || name.length > 24) {
      return;
    }

    if (topics.length >= MAX_TOPICS) {
      toast(`最多添加 ${MAX_TOPICS} 个话题`, 'info');
      return;
    }

    if (!topics.some((topic) => topic.toLowerCase() === name.toLowerCase())) {
      topics.push(name);
      renderChips();
    }
  };

  const onTopicKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' || event.key === ',') {
      event.preventDefault();
      if (topicInput !== null) {
        addTopic(topicInput.value);
        topicInput.value = '';
      }
    } else if (event.key === 'Backspace' && topicInput !== null && topicInput.value === '' && topics.length > 0) {
      topics.pop();
      renderChips();
    }
  };

  const addPreview = (media: UploadResponse): void => {
    if (mediaBox === null) {
      return;
    }

    mediaBox.removeAttribute('hidden');

    const item = el('div', { class: 'compose-media__item', 'data-media-id': String(media.id) }, [
      el('img', { src: media.thumb, alt: '预览' }),
    ]);

    const remove = el('button', { class: 'compose-media__remove', type: 'button', 'aria-label': '移除图片' }, ['×']);
    remove.addEventListener('click', () => {
      const index = mediaIds.indexOf(media.id);
      if (index >= 0) {
        mediaIds.splice(index, 1);
      }
      item.remove();

      if (mediaIds.length === 0 && videoId === null) {
        mediaBox.setAttribute('hidden', '');
      }
    });

    item.append(remove);
    mediaBox.append(item);
  };

  const addVideoPreview = (media: UploadResponse): void => {
    if (mediaBox === null) {
      return;
    }

    mediaBox.removeAttribute('hidden');

    const item = el('div', { class: 'compose-media__item compose-media__item--video', 'data-media-id': String(media.id) }, [
      el('video', { src: media.url, controls: 'controls', preload: 'metadata', playsinline: 'playsinline' }),
    ]);

    const remove = el('button', { class: 'compose-media__remove', type: 'button', 'aria-label': '移除视频' }, ['×']);
    remove.addEventListener('click', () => {
      videoId = null;
      item.remove();

      if (mediaIds.length === 0 && videoId === null) {
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
      const isVideo = file.type.startsWith('video/');

      if (isVideo) {
        if (videoId !== null) {
          toast('只能添加 1 个视频', 'info');
          break;
        }

        if (mediaIds.length > 0) {
          toast('视频不能与图片同时发布', 'info');
          break;
        }

        const maxMb = window.__MOFU__?.upload?.videoMaxMb ?? 60;

        if (file.size > maxMb * 1048576) {
          toast(`视频不能超过 ${maxMb} MB`, 'info');
          break;
        }

        try {
          const formData = new FormData();
          formData.append('file', file);
          formData.append('kind', 'video');
          const { data } = await api.postForm<UploadResponse>('/api/v1/uploads', formData);
          videoId = data.id;
          addVideoPreview(data);
        } catch (error) {
          toastError(error);
        }

        continue;
      }

      if (videoId !== null) {
        toast('视频不能与图片同时发布', 'info');
        break;
      }

      if (mediaIds.length >= MAX_MEDIA) {
        toast(`最多上传 ${MAX_MEDIA} 张图片`, 'info');
        break;
      }

      try {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await api.postForm<UploadResponse>('/api/v1/uploads', formData);
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

    if (content === '' && mediaIds.length === 0 && videoId === null && !hasExistingMedia) {
      toast('写点什么或配张图吧', 'info');
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
      if (editPostId !== '') {
        await api.patch(`/api/v1/posts/${editPostId}`, {
          content,
          visibility: visibilitySelect?.value ?? 'public',
          topics,
          media_ids: mediaIds,
        });

        toast('已保存修改', 'success');
        window.location.href = `/posts/${editPostId}`;
        return;
      }

      const { data } = await api.post<{ id: number; url: string }>('/api/v1/posts', {
        content,
        visibility: visibilitySelect?.value ?? 'public',
        topics,
        media_ids: videoId !== null ? [videoId] : mediaIds,
        community_id: communityId === '' ? 0 : Number(communityId),
      });

      localStorage.removeItem(DRAFT_PREFIX + (communityId === '' ? 'post' : `circle-${communityId}`));
      toast('发布成功', 'success');
      window.location.href = data.url;
    } catch (error) {
      // 内联错误区：toast 会消失，错误需要留得住
      if (errorBox !== null) {
        errorBox.textContent = error instanceof Error ? error.message : '发布失败，请稍后重试';
        errorBox.hidden = false;
      }

      toastError(error);
      if (submitButton !== null) {
        submitButton.disabled = false;
        setButtonLoading(submitButton, false);
      }
    }
  };

  const onKeydown = (event: KeyboardEvent): void => {
    if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
      event.preventDefault();
      void onSubmit(event);
    }
  };

  input.addEventListener('input', autoGrow);
  topicInput?.addEventListener('keydown', onTopicKeydown);
  fileInput?.addEventListener('change', () => void onFileChange());
  form.addEventListener('submit', onSubmit);
  input.addEventListener('keydown', onKeydown);

  const cleanupEmoji = setupEmojiPanel(input, root);
  const cleanupMention = setupMention(input, root);
  const cleanupDraft = setupDraft(input, root, editPostId !== '', communityId);

  autoGrow();

  return () => {
    input.removeEventListener('input', autoGrow);
    topicInput?.removeEventListener('keydown', onTopicKeydown);
    form.removeEventListener('submit', onSubmit);
    input.removeEventListener('keydown', onKeydown);
    cleanupEmoji();
    cleanupMention();
    cleanupDraft();
  };
};