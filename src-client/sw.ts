/// <reference lib="webworker" />
/**
 * MOFU社区 Service Worker。
 *
 * 缓存策略：
 * - 预缓存：安装时缓存离线页、manifest 与图标；
 * - /assets/*（内容哈希）：cache-first；
 * - /uploads/*（用户媒体）：cache-first + FIFO 上限；
 * - 导航请求：network-first，断网回退 /offline.html；
 * - 其他同源 GET（含 /api/* 与 Swup 的 HTML 拉取）：直接放行，不介入。
 *
 * 产物：public/sw.js（esbuild 打包，bin/fe-build.sh 注入版本号）。
 */
import {
  MEDIA_CACHE_MAX,
  PRECACHE_URLS,
  isCacheableResponse,
  isHashedAssetRequest,
  isUploadRequest,
  isVendorAssetRequest,
  mediaCacheName,
  staticCacheName,
  trimPlan,
} from './pwa/rules';

const sw = self as unknown as ServiceWorkerGlobalScope;
const ORIGIN = sw.location.origin;
const STATIC_CACHE = staticCacheName(__SW_VERSION__);
const MEDIA_CACHE = mediaCacheName(__SW_VERSION__);

sw.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      await cache.addAll([...PRECACHE_URLS]);
      await sw.skipWaiting();
    })(),
  );
});

sw.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys();
      const keep = new Set([STATIC_CACHE, MEDIA_CACHE]);

      await Promise.all(
        names
          .filter((name) => name.startsWith('mofu-') && !keep.has(name))
          .map((name) => caches.delete(name)),
      );

      await sw.clients.claim();
    })(),
  );
});

sw.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.origin !== ORIGIN) {
    return;
  }

  // Service Worker 自身与 Range 请求不介入
  if (url.pathname === '/sw.js' || request.headers.has('range')) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(networkFirstNavigation(request));
    return;
  }

  if (isHashedAssetRequest(url, ORIGIN) || isVendorAssetRequest(url, ORIGIN)) {
    event.respondWith(cacheFirst(request, STATIC_CACHE));
    return;
  }

  if (isUploadRequest(url, ORIGIN)) {
    event.respondWith(cacheFirstMedia(request));
  }
});

async function networkFirstNavigation(request: Request): Promise<Response> {
  try {
    return await fetch(request);
  } catch {
    const offline = await caches.match('/offline.html');

    return offline ?? Response.error();
  }
}

async function cacheFirst(request: Request, cacheName: string): Promise<Response> {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);

  if (cached !== undefined) {
    return cached;
  }

  const response = await fetch(request);

  if (isCacheableResponse(response)) {
    void cache.put(request, response.clone());
  }

  return response;
}

async function cacheFirstMedia(request: Request): Promise<Response> {
  const cache = await caches.open(MEDIA_CACHE);
  const cached = await cache.match(request);

  if (cached !== undefined) {
    return cached;
  }

  const response = await fetch(request);

  if (!isCacheableResponse(response)) {
    return response;
  }

  void cache.put(request, response.clone()).then(() => pruneMediaCache(cache));

  return response;
}

async function pruneMediaCache(cache: Cache): Promise<void> {
  const keys = await cache.keys();
  const plan = trimPlan(
    keys.map((key) => key.url),
    MEDIA_CACHE_MAX,
  );

  if (plan.length === 0) {
    return;
  }

  const doomed = new Set(plan);
  await Promise.all(keys.filter((key) => doomed.has(key.url)).map((key) => cache.delete(key)));
}
