import type { MofuUser } from '@/types';

/**
 * 站点统一的 Twikoo 接入。
 *
 * 评论只在 Twikoo 上渲染（站内评论 UI 已下线），本模块负责：
 *  - 扫描页面上所有 [data-twikoo] 挂载点并按需初始化（帖子详情 / 树洞 / 板块回复共用）；
 *  - 已登录用户把账号昵称、头像、邮箱注入 Twikoo 的评论者信息；
 *  - 覆写 GET_CONFIG 响应里的 EMOTION_CDN，改用站内自带表情包；
 *  - 对外提供 getCommentCounts() 给帖子卡片显示评论数。
 */

interface TwikooGlobal {
  init: (opts: Record<string, unknown>) => Promise<unknown>;
  getCommentsCount?: (opts: Record<string, unknown>) => Promise<Array<{ url: string; count: number }>>;
}

const twikooGlobal = (): TwikooGlobal | undefined =>
  (window as unknown as { twikoo?: TwikooGlobal }).twikoo;

/**
 * 与 Twikoo 服务端配套的备选脚本源。
 * 首选配置里的 scriptUrl（默认站内自托管副本 public/vendor/twikoo.all.min.js），
 * 缺失/不可用时才回退 CDN；版本必须与后端一致（本项目后端为 2.0.12）。
 */
const TWIKOO_CDN_FALLBACKS: readonly string[] = [
  'https://registry.npmmirror.com/twikoo/2.0.12/files/dist/twikoo.all.min.js',
  'https://cdn.jsdelivr.net/npm/twikoo@2.0.12/dist/twikoo.all.min.js',
  'https://unpkg.com/twikoo@2.0.12/dist/twikoo.all.min.js',
];

const SKELETON = `
<div class="twikoo-skeleton" aria-hidden="true">
  <span class="twikoo-skeleton__bar"></span>
  <span class="twikoo-skeleton__bar twikoo-skeleton__bar--short"></span>
  <span class="twikoo-skeleton__hint">正在加载评论…</span>
</div>`;

const SCRIPT_READY_TIMEOUT = 8000;

/** 配置覆写标记：Twikoo 服务端配置无法直接改，靠覆写 GET_CONFIG 响应实现 */
let configPatched = false;

/**
 * 覆写 GET_CONFIG 响应：
 *  - EMOTION_CDN：换成站内 Apple 表情包；
 *  - GRAVATAR_CDN：换成站点自身，使头像走本站 /avatar/<邮箱 md5>（见 UserController::avatar）。
 *    Twikoo 2.x 只按邮箱 md5 拼 Gravatar 地址，不认自定义头像字段，
 *    不换源的话已登录账号的站内头像不会出现在评论里。
 *
 * 注意：Twikoo 固定拼 `https://`，因此本站需跑在 https 下（生产环境满足）。
 */
function installConfigOverride(envId: string, emotionCdn: string): void {
  if (configPatched || envId === '') {
    return;
  }

  configPatched = true;

  const patches: Record<string, string> = {
    GRAVATAR_CDN: window.location.host,
    // 允许评论带图（服务端图床上传另需在 Twikoo 后端配置，见注释）
    SHOW_IMAGE: 'true',
  };

  if (emotionCdn !== '') {
    patches['EMOTION_CDN'] = emotionCdn;
  }

  const original = window.fetch.bind(window);

  window.fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const response = await original(input, init);

    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
    const body = typeof init?.body === 'string' ? init.body : '';

    if (url !== envId || !body.includes('GET_CONFIG')) {
      return response;
    }

    try {
      const payload = (await response.clone().json()) as Record<string, unknown>;
      const result = payload['result'] as Record<string, unknown> | undefined;
      let patched = false;

      for (const candidate of [payload['config'], result?.['config']]) {
        if (candidate !== null && typeof candidate === 'object') {
          for (const [key, value] of Object.entries(patches)) {
            (candidate as Record<string, unknown>)[key] = value;
          }

          patched = true;
        }
      }

      if (!patched) {
        return response;
      }

      return new Response(JSON.stringify(payload), {
        status: response.status,
        statusText: response.statusText,
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
      });
    } catch {
      return response;
    }
  };
}

/**
 * 已登录用户：把账号身份写进 Twikoo 的评论者信息（localStorage['twikoo']）。
 * 昵称 / 邮箱 / 网址三个输入框由样式隐藏——它们只对游客有意义。
 * 头像是**当前登录账号自己的头像**。
 */
export function seedTwikooIdentity(user: MofuUser): void {
  let meta: Record<string, unknown> = {};

  try {
    const raw = localStorage.getItem('twikoo');
    const parsed: unknown = raw === null ? null : JSON.parse(raw);
    meta = parsed !== null && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : {};
  } catch {
    meta = {};
  }

  meta['nick'] = user.nickname !== '' ? user.nickname : user.username;

  if (typeof user.email === 'string' && user.email !== '') {
    meta['mail'] = user.email;
  }

  meta['link'] = new URL(user.profile_url ?? `/u/${user.username}`, window.location.origin).href;

  if (typeof user.avatar_url === 'string' && user.avatar_url !== '') {
    meta['avatar'] = new URL(user.avatar_url, window.location.origin).href;
  } else {
    // 没有上传头像：不写 avatar，让 Twikoo 按邮箱 md5 请求本站头像端点，
    // 由服务端返回同款首字头像（避免往评论里存 data: URI）
    delete meta['avatar'];
  }

  try {
    localStorage.setItem('twikoo', JSON.stringify(meta));
  } catch {
    // 隐私模式等写入失败：忽略，Twikoo 仍允许用户手填
  }
}

/** 评论头像重写：Twikoo 按 `<任意 CDN>/avatar/<邮箱哈希>` 取图，这里改指本站端点 */
const AVATAR_SRC = /^https?:\/\/[^/]+\/avatar\/([0-9a-f]{32,64})(\?.*)?$/i;

/**
 * 把已渲染的评论头像换成站点自己的头像端点。
 * Twikoo 会把提交时的头像地址（`https://weavatar.com/avatar/<哈希>?...`）存进评论，
 * 老评论不会因为改了配置就跟着变，所以在渲染后重写一遍。
 */
function rewriteAvatarHost(root: ParentNode): void {
  for (const img of root.querySelectorAll<HTMLImageElement>('img[src]')) {
    const src = img.getAttribute('src') ?? '';
    const match = src.match(AVATAR_SRC);

    if (match === null || match[1] === undefined) {
      continue;
    }

    try {
      if (new URL(img.src, window.location.origin).host === window.location.host) {
        continue;
      }
    } catch {
      continue;
    }

    img.setAttribute('src', `${window.location.origin}/avatar/${match[1]}${match[2] ?? ''}`);
  }
}

let scriptPromise: Promise<boolean> | null = null;
/** 按需注入 Twikoo 脚本（幂等）。返回脚本是否可用。 */
export function loadTwikooScript(): Promise<boolean> {
  if (typeof twikooGlobal()?.init === 'function') {
    return Promise.resolve(true);
  }

  if (scriptPromise !== null) {
    return scriptPromise;
  }

  const config = window.__MOFU__?.twikoo;
  const sources = [config?.scriptUrl ?? '', ...TWIKOO_CDN_FALLBACKS].filter((src) => src !== '');

  scriptPromise = new Promise<boolean>((resolve) => {
    const inject = (index: number): void => {
      const src = sources[index];

      if (src === undefined) {
        resolve(false);

        return;
      }

      const script = document.createElement('script');
      script.src = src;
      script.async = true;
      script.onload = () => resolve(typeof twikooGlobal()?.init === 'function');
      script.onerror = () => inject(index + 1);
      document.head.appendChild(script);
    };

    inject(0);
  });

  return scriptPromise;
}

/** 探测 Twikoo 后端是否可达：不可达就不要初始化，避免只剩一个空壳 */
async function backendReachable(envId: string): Promise<boolean> {
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
}

async function initMount(mount: HTMLElement): Promise<void> {
  const config = window.__MOFU__?.twikoo;
  const section = mount.closest<HTMLElement>('[data-comment-path]') ?? mount.parentElement;
  const envId = mount.dataset['envId'] ?? section?.dataset['twikooEnv'] ?? config?.envId ?? '';

  if (envId === '') {
    mount.removeAttribute('hidden');
    mount.innerHTML = '<p class="comments__guest">评论功能尚未配置（缺少 TWIKOO_ENV_ID）。</p>';

    return;
  }

  // 评论区路径由服务端下发，保证「一个评论区只显示这一处的评论」。
  // 不能用 location.pathname —— swup 换页时它可能还是上一页的值。
  const path = section?.dataset['commentPath'] ?? window.location.pathname;

  const viewer = window.__MOFU__?.user ?? null;

  if (viewer !== null) {
    seedTwikooIdentity(viewer);
    mount.classList.add('twikoo--authed');
  }

  mount.removeAttribute('hidden');

  if (mount.childElementCount === 0) {
    mount.innerHTML = SKELETON;
  }

  const fail = (message: string): void => {
    mount.innerHTML = `<p class="comments__guest">${message} <button class="text-btn text-btn--sm" type="button" data-twikoo-retry>重试</button></p>`;

    mount.querySelector<HTMLButtonElement>('[data-twikoo-retry]')?.addEventListener('click', () => {
      // 允许重新初始化：清掉“已处理”标记与占位，再走一遍完整流程
      delete mount.dataset['mofuTwikoo'];
      mount.innerHTML = SKELETON;

      void initMount(mount);
    });
  };

  if (!(await backendReachable(envId))) {
    fail('评论服务当前不可达，请稍后再试。');

    return;
  }

  const ready = await loadTwikooScript();
  const twikoo = twikooGlobal();

  if (!ready || twikoo === undefined || typeof twikoo.init !== 'function') {
    fail('评论组件加载失败，请刷新重试。');

    return;
  }

  installConfigOverride(envId, config?.emotionCdn ?? '');

  try {
    const result: unknown = twikoo.init({
      envId,
      el: mount,
      path,
      href: window.location.origin + path,
      lang: config?.lang ?? 'zh-CN',
      onCommentLoaded: () => {
        mount.querySelector('.twikoo-skeleton')?.remove();
        rewriteAvatarHost(mount);
      },
    });

    if (result instanceof Promise) {
      await result;
    }

    mount.querySelector('.twikoo-skeleton')?.remove();
    rewriteAvatarHost(mount);

    // 翻页加载出来的评论也要换头像（只观察子节点变化，改 src 本身不会触发 childList）
    new MutationObserver(() => rewriteAvatarHost(mount)).observe(mount, { childList: true, subtree: true });

    // 标题里的评论数与卡片保持一致（Twikoo 存的是相对路径，必须按路径查）
    void getCommentCounts([path]).then((counts) => {
      const total = counts[path];
      const totalEl = document.querySelector<HTMLElement>('[data-comment-total]');

      if (total !== undefined && totalEl !== null) {
        totalEl.textContent = String(total);
      }
    });
  } catch (error) {
    console.error('[mofu] twikoo init failed', error);
    fail('评论加载失败，请刷新重试。');
  }
}

/** 初始化页面上所有尚未初始化的 [data-twikoo] 挂载点（可在 swup 换页后重复调用） */
export function setupTwikoo(): void {
  const mounts = document.querySelectorAll<HTMLElement>('[data-twikoo]:not([data-mofu-twikoo])');

  mounts.forEach((mount) => {
    // 先标记再观察：swup 首屏会先后触发 bootstrap 与首次 page view，
    // 若等 initMount 才标记，同一挂载点会被 setup 两次、渲染出两份评论区。
    mount.dataset['mofuTwikoo'] = '1';

    // 评论区进入视口前不加载任何评论脚本，首屏不产生评论相关的网络开销
    if (!('IntersectionObserver' in window)) {
      void initMount(mount);

      return;
    }

    // 观察「挂载点的父容器」而不是挂载点自己：挂载点初始带 hidden，
    // 隐藏元素永远不会产生交叉观察回调，会导致评论区永远不初始化（点开就是空白）。
    const target = mount.closest<HTMLElement>('[data-comment-path]') ?? mount.parentElement ?? mount;

    const observer = new IntersectionObserver((entries, self) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        self.disconnect();
        void initMount(mount);
      }
    }, { rootMargin: '200px' });

    observer.observe(target);
  });
}

/**
 * 批量取评论数（供帖子卡片）：键为传入的评论路径。
 *
 * Twikoo 存的 `url` 就是初始化时传的 `path`（如 `/posts/21`，相对路径），
 * **不能**拿绝对 URL 去查，否则永远匹配不上、计数恒为 0。
 */
export async function getCommentCounts(paths: string[]): Promise<Record<string, number>> {
  if (paths.length === 0) {
    return {};
  }

  const envId = window.__MOFU__?.twikoo?.envId ?? '';

  if (envId === '') {
    return {};
  }

  try {
    const query = new URLSearchParams({ paths: paths.join(',') });
    const response = await fetch(`/api/v1/comments/counts?${query.toString()}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });

    if (!response.ok) {
      return {};
    }

    const payload = (await response.json()) as { ok?: boolean; data?: unknown };

    if (!payload.ok || payload.data === null || typeof payload.data !== 'object') {
      return {};
    }

    return Object.fromEntries(
      Object.entries(payload.data as Record<string, unknown>)
        .map(([path, count]) => [path, Number(count)] as const)
        .filter(([, count]) => Number.isFinite(count) && count >= 0),
    );
  } catch (error) {
    console.warn('[mofu] twikoo counts proxy failed', error);

    return {};
  }
}

let countsTimer: number | undefined;
const pendingCountPaths = new Set<string>();
let countsObserver: MutationObserver | null = null;
const COUNTS_DELAY_MS = 2000;

function flushCounts(): void {
  const paths = [...pendingCountPaths];
  pendingCountPaths.clear();

  if (paths.length === 0) {
    return;
  }

  void getCommentCounts(paths).then((counts) => {
    for (const node of document.querySelectorAll<HTMLElement>('[data-twikoo-count]')) {
      const path = node.dataset['commentPath'] ?? '';

      if (path === '') {
        continue;
      }

      const count = counts[path];

      if (count !== undefined) {
        node.textContent = String(count);
      }
    }
  });
}

function scheduleCountsFlush(): void {
  window.clearTimeout(countsTimer);
  countsTimer = window.setTimeout(() => {
    const run = (): void => flushCounts();

    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(run, { timeout: 1500 });
    } else {
      run();
    }
  }, COUNTS_DELAY_MS);
}

/**
 * 帖子卡片上的评论数：由 Twikoo 计数填充。
 * 站内评论已下线，服务端渲染的 comment_count 不再作为评论数来源。
 */
export function setupTwikooCounts(): void {
  if ((window.__MOFU__?.twikoo?.envId ?? '') === '') {
    return;
  }

  const connection = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection;

  // 低流量模式下保留 SSR 计数，不为实时评论数下载 800 KB 运行库。
  if (connection?.saveData === true) {
    return;
  }

  document
    .querySelectorAll<HTMLElement>('[data-twikoo-count]:not([data-mofu-count-bound])')
    .forEach((node) => {
      node.dataset['mofuCountBound'] = '1';

      const path = node.dataset['commentPath'] ?? '';

      if (path === '') {
        return;
      }

      if (!('IntersectionObserver' in window)) {
        pendingCountPaths.add(path);
        scheduleCountsFlush();

        return;
      }

      // 卡片进入视口才计入本次批量查询，避免首屏对几十条帖子各发一次请求
      const observer = new IntersectionObserver((entries, self) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          self.disconnect();
          pendingCountPaths.add(path);
          scheduleCountsFlush();
        }
      }, { rootMargin: '200px' });

      observer.observe(node);
    });

  // 列表 AJAX 追加（加载更多、滚动加载）后也要补评论数：原先只在换页时刷新，新卡片会一直是占位符
  if (countsObserver === null && 'MutationObserver' in window) {
    countsObserver = new MutationObserver(() => {
      if (document.querySelector('[data-twikoo-count]:not([data-mofu-count-bound])') !== null) {
        setupTwikooCounts();
      }
    });

    countsObserver.observe(document.body, { childList: true, subtree: true });
  }
}
