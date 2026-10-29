import { api } from '@/core/api';
import { $, $$ } from '@/core/dom';
import { refreshUnreadCount } from '@/core/global-actions';
import { syncSegmentedControls } from '@/core/nav';
import { toast, toastError } from '@/core/toast';
import type { PageModule } from './types';

const POLL_INTERVAL = 8000;

/** 私信输入区的快捷表情 */
const CHAT_EMOJIS = [
  '😀', '😂', '🥰', '😊', '😍', '🤔', '😎', '😭',
  '😅', '😉', '🙃', '🥳', '😴', '🤗', '😏', '😢',
  '👍', '👎', '👏', '🙏', '💪', '🤝', '👀', '🙌',
  '❤️', '🧡', '💛', '💚', '💙', '💜', '💖', '💯',
  '✨', '🔥', '🌈', '⭐', '🎉', '🎊', '🌸', '🍀',
  '⚡', '☀️', '🌙', '🍰', '🧋', '🎧', '🏀', '⚽',
];

/** 记住上次所在的标签（通知 / 私信），下次打开消息页直接回到那里 */
const INBOX_TAB_KEY = 'mofu.inbox.tab';

function readSavedInboxTab(): string | null {
  try {
    const value = window.localStorage.getItem(INBOX_TAB_KEY);

    return value === 'notifications' || value === 'messages' ? value : null;
  } catch {
    return null;
  }
}

function writeSavedInboxTab(tab: string): void {
  try {
    window.localStorage.setItem(INBOX_TAB_KEY, tab);
  } catch {
    // 隐私模式等写入失败的场景：忽略，不影响切换本身
  }
}

/**
 * 消息中心：私信 / 通知面板单页切换（不导航、不刷新）。
 * URL 用 replaceState 同步（不新增历史条目，避免与 swup 的历史管理冲突）。
 */
/**
 * 会话列表长按（移动端）/ 右键（桌面）卡片：查看主页 / 标为已读。
 * 用文档级委托实现，列表 AJAX 追加后依旧生效。
 */
function setupConversationMenu(): () => void {
  let menu: HTMLElement | null = null;
  let timer: number | undefined;
  let openedAt = 0;

  const close = (): void => {
    window.clearTimeout(timer);
    timer = undefined;
    menu?.remove();
    menu = null;
  };

  const open = (card: HTMLElement): void => {
    close();

    const conversationId = Number(card.dataset.conversationId ?? 0);
    const profile = card.dataset.profile ?? '';
    const unread = Number(card.dataset.unread ?? 0);

    menu = document.createElement('div');
    menu.className = 'card-menu conversation__menu';
    menu.setAttribute('role', 'menu');

    if (profile !== '') {
      const link = document.createElement('a');
      link.className = 'card-menu__item';
      link.setAttribute('role', 'menuitem');
      link.href = profile;
      link.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">person</span>查看主页';
      menu.append(link);
    }

    if (unread > 0 && conversationId > 0) {
      const read = document.createElement('button');
      read.type = 'button';
      read.className = 'card-menu__item';
      read.setAttribute('role', 'menuitem');
      read.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">mark_chat_read</span>标为已读';
      read.addEventListener('click', async () => {
        close();

        try {
          await api.post(`/api/v1/conversations/${conversationId}/read`);
          toast('已标为已读', 'success');
          card.querySelector('.conversation__unread')?.remove();
          card.querySelector('.conversation__preview')?.classList.remove('is-unread');
          card.dataset.unread = '0';
          void refreshUnreadCount();
        } catch (error) {
          toastError(error);
        }
      });
      menu.append(read);
    }

    if (menu.childElementCount === 0) {
      menu = null;

      return;
    }

    card.append(menu);
    card.classList.add('conversation--menu-open');
    openedAt = Date.now();
  };

  const onPointerDown = (event: PointerEvent): void => {
    const target = event.target;
    const card = target instanceof Element ? target.closest<HTMLElement>('.conversation') : null;

    if (card === null) {
      return;
    }

    window.clearTimeout(timer);
    timer = window.setTimeout(() => open(card), 450);
  };

  const cancel = (): void => {
    window.clearTimeout(timer);
    timer = undefined;
  };

  const onContextMenu = (event: MouseEvent): void => {
    const target = event.target;
    const card = target instanceof Element ? target.closest<HTMLElement>('.conversation') : null;

    if (card === null) {
      return;
    }

    event.preventDefault();
    cancel();
    open(card);
  };

  const onClick = (event: Event): void => {
    if (menu !== null && Date.now() - openedAt < 300) {
      // 长按后紧跟的那次 click：吃掉，别把菜单关了也别跳进会话
      event.preventDefault();
      event.stopPropagation();

      return;
    }

    if (menu !== null && event.target instanceof Element && event.target.closest('.conversation__menu') !== null) {
      return;
    }

    close();
  };

  document.addEventListener('pointerdown', onPointerDown);
  document.addEventListener('pointerup', cancel);
  document.addEventListener('pointercancel', cancel);
  document.addEventListener('contextmenu', onContextMenu);
  document.addEventListener('click', onClick, true);

  return () => {
    document.removeEventListener('pointerdown', onPointerDown);
    document.removeEventListener('pointerup', cancel);
    document.removeEventListener('pointercancel', cancel);
    document.removeEventListener('contextmenu', onContextMenu);
    document.removeEventListener('click', onClick, true);
    close();
  };
}

function setupInbox(): (() => void) | null {
  const root = $<HTMLElement>('[data-inbox]');

  if (root === null) {
    return null;
  }

  const panels = $$<HTMLElement>('[data-inbox-panel]', root);
  const buttons = $$<HTMLButtonElement>('[data-inbox-switch]', root);

  if (panels.length === 0 || buttons.length === 0) {
    return null;
  }

  const apply = (tab: string): void => {
    for (const panel of panels) {
      panel.hidden = panel.dataset.inboxPanel !== tab;
    }

    for (const button of buttons) {
      const active = button.dataset.inboxSwitch === tab;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
      button.tabIndex = active ? 0 : -1;
    }

    root.dataset.inboxTab = tab;
    writeSavedInboxTab(tab);
    syncSegmentedControls();

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
  };

  const onClick = (event: Event): void => {
    const button = (event.target as HTMLElement).closest<HTMLButtonElement>('[data-inbox-switch]');

    if (button === null) {
      return;
    }

    apply(button.dataset.inboxSwitch ?? 'messages');
  };

  const onKeydown = (event: KeyboardEvent): void => {
    const target = event.target;
    const button = target instanceof Element ? target.closest<HTMLButtonElement>('[data-inbox-switch]') : null;

    if (button === null || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
      return;
    }

    const current = buttons.indexOf(button);

    if (current < 0) {
      return;
    }

    let next = current;

    if (event.key === 'ArrowLeft') {
      next = (current - 1 + buttons.length) % buttons.length;
    } else if (event.key === 'ArrowRight') {
      next = (current + 1) % buttons.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else {
      next = buttons.length - 1;
    }

    event.preventDefault();
    buttons[next]?.focus();
    apply(buttons[next]?.dataset.inboxSwitch ?? 'messages');
  };

  // 服务端已按「有未读私信→私信，否则→通知」给出默认标签；
  // 这里再用上次记住的标签覆盖（仅在 /messages 且未显式指定 ?tab= 时）
  const initial = root.dataset.inboxTab === 'notifications' ? 'notifications' : 'messages';
  const explicit = new URL(window.location.href).searchParams.has('tab');
  const saved = readSavedInboxTab();

  if (window.location.pathname === '/messages' && !explicit && saved !== null && saved !== initial) {
    apply(saved);
  } else {
    writeSavedInboxTab(initial);
  }

  root.addEventListener('click', onClick);
  root.addEventListener('keydown', onKeydown);
  syncSegmentedControls();

  let refreshTimer: number | undefined;
  let refreshing = false;

  const refreshConversations = async (): Promise<void> => {
    if (refreshing || document.hidden) {
      return;
    }

    refreshing = true;

    try {
      const response = await fetch('/messages?tab=messages', {
        headers: { Accept: 'text/html' },
        credentials: 'same-origin',
      });

      if (!response.ok) {
        return;
      }

      const doc = new DOMParser().parseFromString(await response.text(), 'text/html');

      for (const selector of [
        '[data-inbox-panel="messages"] [data-list="conversations"]',
        '[data-inbox-panel="notifications"] [data-list="notifications"]',
      ]) {
        const current = root.querySelector<HTMLElement>(selector);
        const fresh = doc.querySelector<HTMLElement>(selector);

        if (current !== null && fresh !== null) {
          current.replaceWith(document.importNode(fresh, true));
        }
      }

      const freshNotificationTab = doc.querySelector<HTMLElement>('[data-inbox-switch="notifications"]');
      const notificationTab = root.querySelector<HTMLElement>('[data-inbox-switch="notifications"]');

      if (freshNotificationTab !== null && notificationTab !== null) {
        notificationTab.textContent = freshNotificationTab.textContent;
      }
    } catch {
      // 实时刷新失败时保留当前列表，下一次事件再试
    } finally {
      refreshing = false;
    }
  };

  const scheduleInboxRefresh = (): void => {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(() => void refreshConversations(), 320);
  };

  window.addEventListener('mofu:realtime-message', scheduleInboxRefresh);
  window.addEventListener('mofu:realtime', scheduleInboxRefresh);

  return () => {
    window.clearTimeout(refreshTimer);
    window.removeEventListener('mofu:realtime-message', scheduleInboxRefresh);
    window.removeEventListener('mofu:realtime', scheduleInboxRefresh);
    root.removeEventListener('click', onClick);
    root.removeEventListener('keydown', onKeydown);
  };
}

interface ChatMessage {
  id: number;
  conversation_id?: number;
  mine: boolean;
  content: string;
  read: boolean;
  receipts?: boolean;
  media?: { kind: string; url: string; thumb: string } | null;
  deleted?: boolean;
  time: string;
}

/**
 * 私信会话模块：发送消息 + 轮询拉取新消息（非实时推送，按固定间隔拉取）。
 */
/**
 * 长按（移动端）/ 右键（桌面）消息气泡：复制内容 / 撤回自己的消息。
 * 菜单复用卡片菜单的样式（.card-menu / .card-menu__item）。
 */
function setupMessageMenu(thread: HTMLElement): () => void {
  let menu: HTMLElement | null = null;
  let timer: number | undefined;
  let openedAt = 0;

  const close = (): void => {
    window.clearTimeout(timer);
    timer = undefined;
    menu?.remove();
    menu = null;
  };

  const open = (msg: HTMLElement): void => {
    close();

    const messageId = Number(msg.dataset.messageId ?? 0);
    const mine = msg.classList.contains('msg--mine');
    const deleted = msg.classList.contains('msg--deleted');
    // 服务端渲染的带 data-recallable；刚发送追加的节点按「自己的消息」判断
    const recallable = mine && !deleted && (msg.dataset.recallable ?? '1') !== '0';

    menu = document.createElement('div');
    menu.className = 'card-menu msg__menu';
    menu.setAttribute('role', 'menu');

    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'card-menu__item';
    copy.setAttribute('role', 'menuitem');
    copy.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">content_copy</span>复制';
    copy.addEventListener('click', () => {
      const text = msg.dataset.content ?? msg.querySelector<HTMLElement>('.msg__bubble')?.textContent ?? '';
      close();

      if (text === '') {
        return;
      }

      void navigator.clipboard?.writeText(text).then(
        () => toast('已复制', 'success'),
        () => toastError('复制失败，请长按手动选择'),
      );
    });
    menu.append(copy);

    if (recallable && messageId > 0) {
      const recall = document.createElement('button');
      recall.type = 'button';
      recall.className = 'card-menu__item card-menu__item--danger';
      recall.setAttribute('role', 'menuitem');
      recall.innerHTML = '<span class="material-symbols-outlined" aria-hidden="true">undo</span>撤回';
      recall.addEventListener('click', async () => {
        close();

        try {
          await api.post(`/api/v1/messages/${messageId}/recall`);
          toast('已撤回', 'success');

          const bubble = msg.querySelector<HTMLElement>('.msg__bubble');

          if (bubble !== null) {
            bubble.textContent = '消息已撤回';
          }

          msg.classList.add('msg--deleted');
          msg.dataset.recallable = '0';
          msg.dataset.content = '';
          msg.querySelector('.msg__read')?.remove();
        } catch (error) {
          toastError(error);
        }
      });
      menu.append(recall);
    }

    msg.append(menu);
    openedAt = Date.now();
  };

  const onPointerDown = (event: PointerEvent): void => {
    const target = event.target;
    const msg = target instanceof Element ? target.closest<HTMLElement>('.msg') : null;

    if (msg === null || !thread.contains(msg)) {
      return;
    }

    window.clearTimeout(timer);
    timer = window.setTimeout(() => open(msg), 450);
  };

  const cancel = (): void => {
    window.clearTimeout(timer);
    timer = undefined;
  };

  const onContextMenu = (event: MouseEvent): void => {
    const target = event.target;
    const msg = target instanceof Element ? target.closest<HTMLElement>('.msg') : null;

    if (msg === null || !thread.contains(msg)) {
      return;
    }

    event.preventDefault();
    cancel();
    open(msg);
  };

  const onDocumentClick = (): void => {
    // 长按后紧跟的那次 click 不该立刻把菜单关掉
    if (menu !== null && Date.now() - openedAt < 300) {
      return;
    }

    close();
  };

  thread.addEventListener('pointerdown', onPointerDown);
  thread.addEventListener('pointerup', cancel);
  thread.addEventListener('pointercancel', cancel);
  thread.addEventListener('contextmenu', onContextMenu);
  document.addEventListener('click', onDocumentClick);

  return () => {
    thread.removeEventListener('pointerdown', onPointerDown);
    thread.removeEventListener('pointerup', cancel);
    thread.removeEventListener('pointercancel', cancel);
    thread.removeEventListener('contextmenu', onContextMenu);
    document.removeEventListener('click', onDocumentClick);
    close();
  };
}

export const init: PageModule['init'] = () => {
  const teardowns: Array<() => void> = [];  const inbox = setupInbox();

  if (inbox !== null) {
    teardowns.push(inbox);
  }

  // 会话列表长按菜单：首页判断前就装上（该页没有 [data-chat]）
  teardowns.push(setupConversationMenu());

  const stopAll = (): void => {
    teardowns.forEach((fn) => fn());
  };

  const root = $<HTMLElement>('[data-chat]');

  if (root === null) {
    return stopAll;
  }

  const conversationId = Number(root.dataset.conversationId ?? 0);
  const thread = $<HTMLElement>('[data-chat-thread]', root);
  const form = $<HTMLFormElement>('[data-chat-form]', root);
  const input = $<HTMLTextAreaElement>('[data-chat-input]', root);
  const sendButton = $<HTMLButtonElement>('[data-chat-send]', root);

  if (conversationId <= 0 || thread === null) {
    return stopAll;
  }

  teardowns.push(setupMessageMenu(thread));

  let lastId = Number(root.dataset.lastId ?? 0);
  let polling = false;

  const scrollToBottom = (): void => {
    thread.scrollTop = thread.scrollHeight;
  };

  scrollToBottom();

  const appendMessage = (message: ChatMessage): void => {
    // SSE 直推与轮询可能同时送到同一条，按 id 去重
    if (thread.querySelector(`[data-message-id="${message.id}"]`) !== null) {
      return;
    }

    thread.querySelector('[data-empty-state]')?.remove();

    const item = document.createElement('div');
    item.className = message.mine ? 'msg msg--mine' : 'msg';
    item.dataset.messageId = String(message.id);
    item.dataset.content = message.content;

    const bubble = document.createElement('div');
    bubble.className = 'msg__bubble';

    if (message.media) {
      if (message.media.kind === 'video') {
        const video = document.createElement('video');
        video.className = 'msg__video';
        video.controls = true;
        video.preload = 'metadata';
        video.playsInline = true;
        video.src = message.media.url;
        bubble.append(video);
      } else {
        const img = document.createElement('img');
        img.className = 'msg__image';
        img.loading = 'lazy';
        img.alt = '';
        img.src = message.media.thumb !== '' ? message.media.thumb : message.media.url;
        bubble.append(img);
      }
    }

    if (message.content !== '') {
      const text = document.createElement('span');
      text.className = 'msg__text';
      text.textContent = message.content;
      bubble.append(text);
    }

    const meta = document.createElement('div');
    meta.className = 'msg__meta';

    const time = document.createElement('time');
    time.className = 'msg__time';
    time.textContent = message.time;
    meta.append(time);

    // 已读回执：仅自己的消息（含图片/视频），双方都开启时才展示——与 show.twig 一致
    if (message.mine && message.receipts === true && message.deleted !== true) {
      const read = document.createElement('span');
      read.className = message.read ? 'msg__read is-read' : 'msg__read';
      read.textContent = message.read ? '已读' : '未读';
      meta.append(read);
    }

    item.append(bubble, meta);
    thread.append(item);
  };

  // 表情：点按钮展开面板，点表情插入光标处
  const emojiToggle = $<HTMLButtonElement>('[data-action="toggle-chat-emoji"]', root);
  const emojiPanel = $<HTMLElement>('[data-chat-emoji-panel]', root);

  if (emojiToggle !== null && emojiPanel !== null && input !== null) {
    const insertEmoji = (emoji: string): void => {
      const start = input.selectionStart ?? input.value.length;
      const end = input.selectionEnd ?? start;

      input.setRangeText(emoji, start, end, 'end');
      input.focus();
      input.dispatchEvent(new Event('input', { bubbles: true }));
    };

    for (const emoji of CHAT_EMOJIS) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'emoji-panel__item';
      button.textContent = emoji;
      button.addEventListener('click', () => insertEmoji(emoji));
      emojiPanel.append(button);
    }

    emojiToggle.addEventListener('click', () => {
      const willOpen = emojiPanel.hidden;
      emojiPanel.hidden = !willOpen;
      emojiToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  }

  // 「+」附件：选中后先在输入框上方暂存预览，点「发送」时才上传并发出
  const fileInput = $<HTMLInputElement>('[data-chat-file]', root);
  const attachWrap = $<HTMLElement>('[data-chat-attachment]', root);
  const attachThumb = $<HTMLImageElement>('[data-chat-attachment-thumb]', root);
  const attachName = $<HTMLElement>('[data-chat-attachment-name]', root);
  const attachRemove = $<HTMLButtonElement>('[data-chat-attachment-remove]', root);

  let pendingFile: File | null = null;
  let pendingUrl = '';

  const clearAttachment = (): void => {
    pendingFile = null;

    if (pendingUrl !== '') {
      URL.revokeObjectURL(pendingUrl);
      pendingUrl = '';
    }

    if (attachWrap !== null) {
      attachWrap.hidden = true;
    }

    if (attachThumb !== null) {
      attachThumb.hidden = true;
      attachThumb.removeAttribute('src');
    }

    if (attachName !== null) {
      attachName.textContent = '';
    }

    if (fileInput !== null) {
      fileInput.value = '';
    }
  };

  fileInput?.addEventListener('change', () => {
    const file = fileInput.files?.[0] ?? null;

    if (file === null) {
      return;
    }

    pendingFile = file;

    if (pendingUrl !== '') {
      URL.revokeObjectURL(pendingUrl);
      pendingUrl = '';
    }

    if (file.type.startsWith('image/') && attachThumb !== null) {
      pendingUrl = URL.createObjectURL(file);
      attachThumb.src = pendingUrl;
      attachThumb.hidden = false;
    } else if (attachThumb !== null) {
      attachThumb.hidden = true;
      attachThumb.removeAttribute('src');
    }

    if (attachName !== null) {
      attachName.textContent = file.name;
    }

    if (attachWrap !== null) {
      attachWrap.hidden = false;
    }
  });

  attachRemove?.addEventListener('click', clearAttachment);

  const markRead = async (): Promise<void> => {
    try {
      await api.post(`/api/v1/conversations/${conversationId}/read`);
      void refreshUnreadCount();
    } catch {
      // 静默失败
    }
  };

  const pull = async (): Promise<void> => {
    if (polling) {
      return;
    }

    polling = true;

    try {
      const { data } = await api.get<ChatMessage[]>(
        `/api/v1/conversations/${conversationId}/messages?after_id=${lastId}`,
      );

      if (data.length > 0) {
        const atBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80;

        for (const message of data) {
          appendMessage(message);
          lastId = Math.max(lastId, message.id);
        }

        if (data.some((message) => !message.mine)) {
          void markRead();
        }

        if (atBottom) {
          scrollToBottom();
        }
      }
    } catch {
      // 轮询失败静默处理，下个周期重试
    } finally {
      polling = false;
    }
  };

  const onSubmit = async (event: Event): Promise<void> => {
    event.preventDefault();

    if (input === null) {
      return;
    }

    const content = input.value.trim();

    if (content === '' && pendingFile === null) {
      return;
    }

    if (sendButton !== null) {
      sendButton.disabled = true;
    }

    const file = pendingFile;

    try {
      let mediaId: number | null = null;

      if (file !== null) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('kind', file.type.startsWith('video') ? 'video' : 'image');
        const upload = await api.postForm<{ id: number }>('/api/v1/uploads', formData);
        mediaId = upload.data.id;
      }

      const body: { content: string; media_id?: number } = { content };

      if (mediaId !== null) {
        body.media_id = mediaId;
      }

      const { data } = await api.post<ChatMessage>(`/api/v1/conversations/${conversationId}/messages`, body);

      appendMessage(data);
      lastId = Math.max(lastId, data.id);
      input.value = '';
      input.style.height = 'auto';
      clearAttachment();
      scrollToBottom();
    } catch (error) {
      toastError(error);
    } finally {
      if (sendButton !== null) {
        sendButton.disabled = false;
      }
    }
  };

  const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      form?.requestSubmit();
    }
  };

  const autoGrow = (): void => {
    if (input === null) {
      return;
    }

    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 120)}px`;
  };

  form?.addEventListener('submit', onSubmit);
  input?.addEventListener('keydown', onKeydown);
  input?.addEventListener('input', autoGrow);

  let knownMessageCount = -1;

  const onRealtime = (event: Event): void => {
    const detail = (event as CustomEvent<{ messages?: number }>).detail;
    const count = Number(detail?.messages ?? -1);

    if (count >= 0 && count !== knownMessageCount) {
      knownMessageCount = count;
      void pull();
    }
  };

  window.addEventListener('mofu:realtime', onRealtime);

  // 实时连接状态：降级为定时刷新时给用户可见提示
  const statusHint = $<HTMLElement>('[data-chat-status]', root);

  const onRealtimeStatus = (event: Event): void => {
    const mode = (event as CustomEvent<{ mode?: string }>).detail?.mode;

    if (statusHint !== null) {
      statusHint.hidden = mode === 'live';
    }
  };

  window.addEventListener('mofu:realtime-status', onRealtimeStatus);

  /** SSE 直推的新消息：属于当前会话就直接追加（无需等轮询） */
  const onRealtimeMessage = (event: Event): void => {
    const detail = (event as CustomEvent<{ messages?: ChatMessage[] }>).detail;
    const list = Array.isArray(detail?.messages) ? detail.messages : [];
    let added = false;

    for (const message of list) {
      if (Number(message.conversation_id ?? 0) !== conversationId) {
        continue;
      }

      if (thread.querySelector(`[data-message-id="${message.id}"]`) !== null) {
        continue;
      }

      appendMessage(message);
      lastId = Math.max(lastId, message.id);
      added = true;
    }

    if (added) {
      scrollToBottom();
      void markRead();
    }
  };

  window.addEventListener('mofu:realtime-message', onRealtimeMessage);

  void markRead();
  const timer = window.setInterval(() => void pull(), POLL_INTERVAL);

  return () => {
    window.removeEventListener('mofu:realtime', onRealtime);
    window.removeEventListener('mofu:realtime-message', onRealtimeMessage);
    window.removeEventListener('mofu:realtime-status', onRealtimeStatus);
    window.clearInterval(timer);
    form?.removeEventListener('submit', onSubmit);
    input?.removeEventListener('keydown', onKeydown);
    input?.removeEventListener('input', autoGrow);
    stopAll();
  };
};
