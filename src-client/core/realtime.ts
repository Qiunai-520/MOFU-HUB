import { applyUnreadCounts, refreshUnreadCount } from '@/core/global-actions';
import { $user } from '@/core/store';

/**
 * 实时通知（SSE）：
 * - 登录用户连接 /api/v1/stream，收到 unread 事件后更新未读角标，
 *   并派发 window 自定义事件 mofu:realtime（私信页据此即时拉取新消息）；
 * - 连接持续失败（连续 3 次错误）时降级为 30 秒轮询兜底。
 */
const FALLBACK_POLL_MS = 30000;
const MAX_ERRORS = 3;

let fallbackTimer: number | null = null;

export type RealtimeMode = 'live' | 'polling';

/** 广播实时连接状态（live=SSE 在线；polling=已降级为定时刷新） */
function notifyStatus(mode: RealtimeMode): void {
  window.dispatchEvent(new CustomEvent('mofu:realtime-status', { detail: { mode } }));
}

export function setupRealtime(): void {
  if ($user.get() === null) {
    return;
  }

  if (!('EventSource' in window)) {
    notifyStatus('polling');

    return;
  }

  const source = new EventSource('/api/v1/stream');
  let errors = 0;

  source.onopen = () => {
    errors = 0;
    notifyStatus('live');
  };

  source.addEventListener('unread', (event) => {
    errors = 0;

    try {
      const data = JSON.parse((event as MessageEvent).data) as {
        notifications?: number;
        messages?: number;
      };

      const notifications = Number(data.notifications ?? 0);
      const messages = Number(data.messages ?? 0);

      applyUnreadCounts(notifications, messages);
      window.dispatchEvent(
        new CustomEvent('mofu:realtime', { detail: { notifications, messages } }),
      );
    } catch {
      // 忽略无法解析的数据
    }
  });

  source.onerror = () => {
    // EventSource 会自动重连；连续失败时降级轮询兜底
    errors += 1;

    if (errors >= MAX_ERRORS) {
      source.close();
      notifyStatus('polling');
      startFallbackPolling();
    }
  };
}

function startFallbackPolling(): void {
  if (fallbackTimer !== null) {
    return;
  }

  fallbackTimer = window.setInterval(() => {
    void refreshUnreadCount();
  }, FALLBACK_POLL_MS);
}
