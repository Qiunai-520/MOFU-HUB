/** 渲染友好的降级 UI（500 / 白屏兜底） */
export function showErrorFallback(): void {
  const root = document.getElementById('app-root') ?? document.body;
  root.innerHTML = `
    <div class="error-page" role="alert">
      <h1 class="error-page__code">500</h1>
      <h2 class="error-page__title">出了点问题</h2>
      <p class="error-page__detail">页面暂时不可用，请刷新或稍后再试。</p>
      <button class="filled-btn" id="error-retry">刷新页面</button>
    </div>
  `;
  const btn = document.getElementById('error-retry');
  btn?.addEventListener('click', () => location.reload());
}

/** 安装全局错误捕获：未处理的 JS 异常与 Promise  rejection */
export function installErrorBoundary(): void {
  if (typeof window === 'undefined') return;
  window.addEventListener('error', (event) => {
    console.error('[mofu] uncaught error', event.error);
    showErrorFallback();
  });
  window.addEventListener('unhandledrejection', (event) => {
    console.error('[mofu] unhandled rejection', event.reason);
    showErrorFallback();
  });
}