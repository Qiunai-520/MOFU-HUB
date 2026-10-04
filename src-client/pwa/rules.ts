/**
 * Service Worker 缓存策略（纯函数，便于单元测试）。
 * 版本号由构建时注入（见 bin/fe-build.sh 的 esbuild --define）。
 */

/** 用户媒体（/uploads/*）运行时缓存上限（FIFO 淘汰） */
export const MEDIA_CACHE_MAX = 80;

/** 安装时预缓存的最小资源集 */
export const PRECACHE_URLS: readonly string[] = [
  '/offline.html',
  '/manifest.webmanifest',
  '/favicon.svg',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

export function staticCacheName(version: string): string {
  return `mofu-static-${version}`;
}

export function mediaCacheName(version: string): string {
  return `mofu-media-${version}`;
}

/** 带内容哈希的构建产物（可安全 cache-first） */
export function isHashedAssetRequest(url: URL, origin: string): boolean {
  return url.origin === origin && url.pathname.startsWith('/assets/');
}

/** 用户上传的媒体（头像 / 图片） */
export function isUploadRequest(url: URL, origin: string): boolean {
  return url.origin === origin && url.pathname.startsWith('/uploads/');
}

/**
 * 自托管第三方脚本（如 Twikoo，public/vendor/*）
 * 文件名不带哈希，所以用「跟随 SW 版本」的 cache-first：重新构建后版本号变化会整体失效。
 */
export function isVendorAssetRequest(url: URL, origin: string): boolean {
  return url.origin === origin && url.pathname.startsWith('/vendor/');
}

/** 仅 200 的完整响应可写入缓存 */
export function isCacheableResponse(response: { ok: boolean; status: number }): boolean {
  return response.ok && response.status === 200;
}

/**
 * FIFO 裁剪计划：保留最新的 max 条（keys 为插入顺序），
 * 返回需要删除的键列表。
 */
export function trimPlan(keys: readonly string[], max: number): string[] {
  if (keys.length <= max) {
    return [];
  }

  return keys.slice(0, keys.length - max);
}
