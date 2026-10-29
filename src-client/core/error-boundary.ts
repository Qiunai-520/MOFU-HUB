/** 渲染友好的降级 UI（500 / 白屏兜底） */
export function showErrorFallback(): void {
  const markup = `
    <div class="error-page" role="alert">
      <h1 class="error-page__code">500</h1>
      <h2 class="error-page__title">出了点问题</h2>
      <p class="error-page__detail">页面暂时不可用，请刷新或稍后再试。</p>
      <button class="filled-btn" id="error-retry">刷新页面</button>
    </div>
  `;

  const root = document.getElementById('app-root');

  if (root) {
    root.innerHTML = markup;
  } else if (!document.getElementById('error-fallback-bar')) {
    // 没有专用容器时只加一条提示，不再用它替换 document.body（那会连导航/外壳一起清掉，且无法恢复）
    const bar = document.createElement('div');
    bar.id = 'error-fallback-bar';
    bar.setAttribute('role', 'alert');
    bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:9999;padding:12px 16px;text-align:center;font-size:14px;background:var(--md-sys-color-error-container,#ffdad6);color:var(--md-sys-color-on-error-container,#410002)';
    bar.textContent = '页面出现异常，若无法正常使用请刷新。';
    document.body.appendChild(bar);
  }

  document.getElementById('error-retry')?.addEventListener('click', () => location.reload());
}

/** 安装全局错误捕获：未处理的 JS 异常与 Promise rejection */
export function installErrorBoundary(): void {
  if (typeof window === 'undefined') return;
  // 只对真正的脚本异常降级；资源加载失败（event.error 为空）不触发
  window.addEventListener('error', (event) => {
    if (!(event instanceof ErrorEvent) || event.error == null) return;
    console.error('[mofu] uncaught error', event.error);
    showErrorFallback();
  });
  // 未处理的 Promise 拒绝在项目里很常见（大量 void async()），只记录，不再整页替换
  window.addEventListener('unhandledrejection', (event) => {
    console.error('[mofu] unhandled rejection', event.reason);
  });
}