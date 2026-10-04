import { api } from '@/core/api';
import { $, $$ } from '@/core/dom';
import { refreshUnreadCount } from '@/core/global-actions';
import { syncSegmentedControls } from '@/core/nav';
import { toastError } from '@/core/toast';
import type { PageModule } from './types';

const POLL_INTERVAL = 8000;

/**
 * 消息中心：私信 / 通知面板单页切换（不导航、不刷新）。
 * URL 用 replaceState 同步（不新增历史条目，避免与 swup 的历史管理冲突）。
 */
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
    }

    root.dataset.inboxTab = tab;
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

  root.addEventListener('click', onClick);
  syncSegmentedControls();

  return () => {
    root.removeEventListener('click', onClick);
  };
}

interface ChatMessage {
  id: number;
  mine: boolean;
  content: string;
  read: boolean;
  time: string;
}

/**
 * 私信会话模块：发送消息 + 轮询拉取新消息（非实时推送，按固定间隔拉取）。
 */
export const init: PageModule['init'] = () => {
  const teardowns: Array<() => void> = [];
  const inbox = setupInbox();

  if (inbox !== null) {
    teardowns.push(inbox);
  }

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

  let lastId = Number(root.dataset.lastId ?? 0);
  let polling = false;

  const scrollToBottom = (): void => {
    thread.scrollTop = thread.scrollHeight;
  };

  scrollToBottom();

  const appendMessage = (message: ChatMessage): void => {
    thread.querySelector('[data-empty-state]')?.remove();

    const item = document.createElement('div');
    item.className = message.mine ? 'msg msg--mine' : 'msg';
    item.dataset.messageId = String(message.id);

    const bubble = document.createElement('div');
    bubble.className = 'msg__bubble';
    bubble.textContent = message.content;

    const time = document.createElement('time');
    time.className = 'msg__time';
    time.textContent = message.time;

    item.append(bubble, time);
    thread.append(item);
  };

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

    if (content === '') {
      return;
    }

    if (sendButton !== null) {
      sendButton.disabled = true;
    }

    try {
      const { data } = await api.post<ChatMessage>(`/api/v1/conversations/${conversationId}/messages`, { content });

      appendMessage(data);
      lastId = Math.max(lastId, data.id);
      input.value = '';
      input.style.height = 'auto';
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

  void markRead();
  const timer = window.setInterval(() => void pull(), POLL_INTERVAL);

  return () => {
    window.removeEventListener('mofu:realtime', onRealtime);
    window.removeEventListener('mofu:realtime-status', onRealtimeStatus);
    window.clearInterval(timer);
    form?.removeEventListener('submit', onSubmit);
    input?.removeEventListener('keydown', onKeydown);
    input?.removeEventListener('input', autoGrow);
    stopAll();
  };
};
