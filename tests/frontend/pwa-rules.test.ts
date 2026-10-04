import { describe, expect, it } from 'vitest';
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
} from '../../src-client/pwa/rules';

const ORIGIN = 'https://mofu.example';

function parse(path: string): URL {
  return new URL(path, ORIGIN);
}

describe('请求分类', () => {
  it('识别 /assets/ 内容哈希产物', () => {
    expect(isHashedAssetRequest(parse('/assets/main-abc123.js'), ORIGIN)).toBe(true);
    expect(isHashedAssetRequest(parse('/uploads/2026/10/photo.webp'), ORIGIN)).toBe(false);
    expect(isHashedAssetRequest(parse('/news'), ORIGIN)).toBe(false);
  });

  it('识别 /uploads/ 用户媒体', () => {
    expect(isUploadRequest(parse('/uploads/2026/10/avatar.webp'), ORIGIN)).toBe(true);
    expect(isUploadRequest(parse('/assets/main-abc123.js'), ORIGIN)).toBe(false);
  });

  it('识别 /vendor/ 自托管第三方脚本', () => {
    expect(isVendorAssetRequest(parse('/vendor/twikoo.all.min.js'), ORIGIN)).toBe(true);
    expect(isVendorAssetRequest(parse('/assets/main-abc123.js'), ORIGIN)).toBe(false);
    expect(isVendorAssetRequest(new URL('https://cdn.example/vendor/x.js'), ORIGIN)).toBe(false);
  });

  it('跨域请求一律不匹配', () => {
    const external = new URL('https://cdn.example/assets/main.js');
    expect(isHashedAssetRequest(external, ORIGIN)).toBe(false);
    expect(isUploadRequest(external, ORIGIN)).toBe(false);
    expect(isVendorAssetRequest(external, ORIGIN)).toBe(false);
  });
});

describe('缓存命名与裁剪', () => {
  it('缓存名包含版本号', () => {
    expect(staticCacheName('v1')).toBe('mofu-static-v1');
    expect(mediaCacheName('v1')).toBe('mofu-media-v1');
  });

  it('trimPlan：未超限不删除', () => {
    expect(trimPlan(['a', 'b'], 2)).toEqual([]);
    expect(trimPlan([], 5)).toEqual([]);
  });

  it('trimPlan：超限时按 FIFO 删除最旧条目', () => {
    expect(trimPlan(['a', 'b', 'c', 'd'], 2)).toEqual(['a', 'b']);
  });

  it('trimPlan：上限为 0 时全部删除', () => {
    expect(trimPlan(['a', 'b'], 0)).toEqual(['a', 'b']);
  });
});

describe('响应可缓存判定', () => {
  it('仅 200 的完整响应可缓存', () => {
    expect(isCacheableResponse({ ok: true, status: 200 })).toBe(true);
    expect(isCacheableResponse({ ok: false, status: 404 })).toBe(false);
    expect(isCacheableResponse({ ok: false, status: 500 })).toBe(false);
    expect(isCacheableResponse({ ok: false, status: 206 })).toBe(false);
  });
});

describe('预缓存清单', () => {
  it('包含离线页与 manifest', () => {
    expect(PRECACHE_URLS).toContain('/offline.html');
    expect(PRECACHE_URLS).toContain('/manifest.webmanifest');
  });

  it('媒体缓存上限为合理正整数', () => {
    expect(Number.isInteger(MEDIA_CACHE_MAX)).toBe(true);
    expect(MEDIA_CACHE_MAX).toBeGreaterThan(0);
  });
});
