import type { PaginationMeta } from '@/types';

/** 统一请求超时：避免服务端挂起时请求永久 pending、按钮一直 loading */
const REQUEST_TIMEOUT_MS = 15000;

export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

interface ApiEnvelope<T> {
  ok: boolean;
  data?: T;
  meta?: Record<string, unknown>;
  error?: { code: string; message: string };
}

export interface ApiResult<T> {
  data: T;
  meta: Record<string, unknown> & { pagination?: PaginationMeta };
}

function csrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? window.__MOFU__?.csrf ?? '';
}

async function request<T>(method: string, url: string, body?: unknown, form?: FormData): Promise<ApiResult<T>> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-CSRF-Token': csrfToken(),
  };

  let payload: BodyInit | undefined;

  if (form !== undefined) {
    payload = form;
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    payload = JSON.stringify(body);
  }

  let response: Response;
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    response = await fetch(url, {
      method,
      headers,
      credentials: 'same-origin',
      body: payload,
      signal: controller.signal,
    });
  } catch {
    throw new ApiError('network_error', '网络异常或超时，请检查连接后重试', 0);
  } finally {
    window.clearTimeout(timer);
  }

  let envelope: ApiEnvelope<T> | null = null;

  try {
    envelope = (await response.json()) as ApiEnvelope<T>;
  } catch {
    envelope = null;
  }

  if (!response.ok || !envelope || envelope.ok !== true) {
    const code = envelope?.error?.code ?? `http_${response.status}`;
    const message = envelope?.error?.message ?? defaultMessage(response.status);
    throw new ApiError(code, message, response.status);
  }

  return {
    data: envelope.data as T,
    meta: (envelope.meta ?? {}) as Record<string, unknown> & { pagination?: PaginationMeta },
  };
}

function defaultMessage(status: number): string {
  switch (status) {
    case 401:
      return '请先登录';
    case 403:
      return '没有权限执行此操作';
    case 404:
      return '内容不存在';
    case 429:
      return '操作太频繁，请稍后再试';
    default:
      return '请求失败，请稍后再试';
  }
}

export const api = {
  get: <T>(url: string): Promise<ApiResult<T>> => request<T>('GET', url),
  post: <T>(url: string, body?: unknown): Promise<ApiResult<T>> => request<T>('POST', url, body),
  patch: <T>(url: string, body?: unknown): Promise<ApiResult<T>> => request<T>('PATCH', url, body),
  delete: <T>(url: string): Promise<ApiResult<T>> => request<T>('DELETE', url),
  postForm: <T>(url: string, form: FormData): Promise<ApiResult<T>> => request<T>('POST', url, undefined, form),
};

/**
 * 带上传进度的表单提交：fetch 无法获取上传进度，因此单走 XHR。
 * 返回值与 api.postForm 不同（直接返回 data），便于上传处直接使用。
 */
export function uploadWithProgress<T>(
  url: string,
  form: FormData,
  onProgress: (percent: number) => void,
): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-CSRF-Token', csrfToken());

    xhr.upload.addEventListener('progress', (event) => {
      if (event.lengthComputable && event.total > 0) {
        onProgress(Math.min(99, Math.round((event.loaded / event.total) * 100)));
      }
    });

    xhr.addEventListener('load', () => {
      let envelope: ApiEnvelope<T> | null = null;

      try {
        envelope = JSON.parse(xhr.responseText) as ApiEnvelope<T>;
      } catch {
        envelope = null;
      }

      if (xhr.status >= 200 && xhr.status < 300 && envelope !== null && envelope.ok === true) {
        onProgress(100);
        resolve(envelope.data as T);

        return;
      }

      const code = envelope?.error?.code ?? `http_${xhr.status}`;
      reject(new ApiError(code, envelope?.error?.message ?? defaultMessage(xhr.status), xhr.status));
    });

    xhr.addEventListener('error', () => reject(new ApiError('network_error', '网络异常或超时，请检查连接后重试', 0)));
    xhr.addEventListener('abort', () => reject(new ApiError('aborted', '上传已取消', 0)));

    xhr.send(form);
  });
}
