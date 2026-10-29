import { api } from '@/core/api';
import { escapeHtml } from '@/core/dom';
import { $user } from '@/core/store';

interface Announcement {
  id: number;
  title: string;
  content: string;
  type: string;
  type_label: string;
  forced: boolean;
  closable: boolean;
  repeat_show: boolean;
  should_show: boolean;
  read: boolean;
}

const GUEST_KEY = 'mofu.announcements.read';

function guestRead(): number[] {
  try {
    const raw = localStorage.getItem(GUEST_KEY);
    const parsed: unknown = raw === null ? null : JSON.parse(raw);

    return Array.isArray(parsed) ? parsed.filter((id): id is number => typeof id === 'number') : [];
  } catch {
    return [];
  }
}

function rememberGuest(id: number): void {
  try {
    const ids = guestRead();
    if (!ids.includes(id)) {
      ids.push(id);
      localStorage.setItem(GUEST_KEY, JSON.stringify(ids.slice(-200)));
    }
  } catch {
    // 隐私模式：忽略，公告可能重复弹出
  }
}

async function markRead(id: number): Promise<void> {
  if ($user.get() === null) {
    rememberGuest(id);
    return;
  }

  try {
    await api.post('/api/v1/announcements/read', { ids: [id] });
  } catch {
    // 标记失败不阻塞展示
  }
}

function isCovered(): boolean {
  return document.querySelector('[data-announcement-modal]') !== null;
}

function show(item: Announcement, onDone: () => void): void {
  const modal = document.createElement('div');
  modal.className = 'announcement-modal';
  modal.setAttribute('data-announcement-modal', '');
  modal.innerHTML = `
    <div class="announcement-modal__panel" role="dialog" aria-modal="true" aria-labelledby="announcement-title">
        <span class="announcement-modal__type">${escapeHtml(item.type_label)}</span>
        <h2 class="announcement-modal__title" id="announcement-title">${escapeHtml(item.title)}</h2>
        <div class="announcement-modal__body">${escapeHtml(item.content)}</div>
        <div class="announcement-modal__foot">
            ${item.closable ? '<button class="text-btn" type="button" data-ann="later">稍后再看</button>' : ''}
            <button class="filled-btn" type="button" data-ann="ok">我知道了</button>
        </div>
    </div>`;

  document.body.append(modal);
  requestAnimationFrame(() => modal.classList.add('is-visible'));

  const close = (): void => {
    modal.classList.remove('is-visible');
    window.setTimeout(() => {
      modal.remove();
      onDone();
    }, 200);
  };

  modal.addEventListener('click', (event) => {
    const target = event.target as HTMLElement;

    if (target === modal && item.closable) {
      close();
      return;
    }

    const action = target.closest<HTMLElement>('[data-ann]')?.dataset['ann'];

    if (action === 'ok' || action === 'later') {
      close();
    }
  });

  document.addEventListener(
    'keydown',
    (event) => {
      if (event.key === 'Escape' && item.closable) {
        close();
      }
    },
    { once: true },
  );
}

/** 进入站点后展示需要弹出的公告；多条公告按优先级依次展示，避免同时堆叠 */
export async function setupAnnouncements(): Promise<void> {
  if (isCovered()) {
    return;
  }

  let items: Announcement[];

  try {
    const result = await api.get<Announcement[]>('/api/v1/announcements');
    items = result.data;
  } catch {
    return;
  }

  const read = $user.get() === null ? guestRead() : [];

  const queue = items.filter((item) => item.should_show && (item.repeat_show || !read.includes(item.id)));

  if (queue.length === 0) {
    return;
  }

  const next = (index: number): void => {
    const item = queue[index];

    if (item === undefined) {
      return;
    }

    void markRead(item.id);
    show(item, () => next(index + 1));
  };

  next(0);
}
