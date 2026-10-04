/// <reference types="vitest/config" />
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// 产物由 PHP 托管在 /assets/ 前缀下（见 app/Core/View/ViteAssets.php），
// 因此构建时 base 必须是 /assets/，否则 CSS 内字体等资源 URL 会指向站点根而 404。
export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/assets/' : '/',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src-client', import.meta.url)),
    },
  },
  build: {
    manifest: 'manifest.json',
    outDir: 'public/assets',
    assetsDir: '',
    emptyOutDir: true,
    target: 'es2022',
    cssCodeSplit: true,
    sourcemap: false,
    rollupOptions: {
      input: {
        main: fileURLToPath(new URL('./src-client/main.ts', import.meta.url)),
      },
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    cors: true,
    allowedHosts: true,
  },
  test: {
    include: ['tests/frontend/**/*.test.ts'],
    environment: 'node',
    server: {
      deps: {
        // 该包内部使用无扩展名 ESM 导入，需要交给 Vite 解析器处理
        inline: ['@material/material-color-utilities'],
      },
    },
  },
}));
