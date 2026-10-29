# MOFU 全站性能审计

审计日期：2026-10-23  
环境：Windows、Chrome、PHP 8.2.35、Node.js 24、本地 MariaDB  
范围：全站 GET 页面、后台页面、冷/热缓存首页、并发请求、前端交互与依赖安全检查

## 结论

当前不需要为了性能重写为 React、Next.js 或 Laravel。项目的主要瓶颈是资源体积、开发服务器并发方式和部分重复查询，不是 Slim/Twig/Swup 这套整体架构本身。

本轮优化后：

- 首页冷缓存首次传输从约 5.2 MB 降到约 254 KB（中位数，减少 95%）。
- 首页 LCP 从约 2.77 秒降到约 2.27 秒（本地中位数，减少约 18%）。
- 首页 load 事件从约 5.89 秒降到约 2.26 秒（本地中位数，减少约 62%）。
- 主 JS 从 205.5 KB / gzip 58.8 KB 降到 100.5 KB / gzip 31.4 KB。
- Material Icons 字体从 3.83 MB 降到 123 KB。
- 首页不再下载 816 KB 的 Twikoo 完整客户端，只请求轻量评论计数 API。
- 成长中心单次请求的 SQL 数量约从 150 降到约 94，并仍在继续优化空间。
- `npm audit` 与 Composer 安全审计均为 0 已知漏洞。

## 测试结果

| 项目 | 结果 |
| --- | --- |
| 已登录 GET 页面扫描 | 72 条路由全部 HTTP 200，无模板 500 |
| 浏览器控制台 | 未发现业务页面脚本异常 |
| PHPUnit | 103 tests / 2394 assertions |
| Vitest | 19 tests |
| TypeScript | `strict` 模式通过 |
| 16 路并发页面请求 | 16/16 成功 |
| npm audit | 0 vulnerabilities |
| Composer audit | 0 advisories |

测试中的音乐外链目前返回 HTTP 404，并带 `X-Frame-Options: SAMEORIGIN`。因此音乐页和悬浮音乐播放器的 iframe 无法正常展示，这是外部部署或 `MUSIC_APP_URL` 配置问题，不应通过放宽本站 CSP 解决。

## 首页基准

基准数字来自同一台本地 Windows 开发机，使用 Chrome Performance Timeline 与 PerformanceObserver。开发服务器、Docker 和后台负载会影响绝对值，应主要比较前后变化。

| 指标 | 优化前 | 优化后中位数 |
| --- | ---: | ---: |
| 首次传输 | 5,200,114 B | 253,679 B |
| TTFB | 1,568 ms | 1,253 ms |
| DOMContentLoaded | 2,480 ms | 2,056 ms |
| Load | 5,887 ms | 2,259 ms |
| LCP | 2,772 ms | 2,272 ms |
| 最长任务 | 569 ms | 483 ms |

优化后的三次冷缓存测试中，第 2 次出现过一次 8.8 秒本地服务器长尾；另外两次 TTFB 分别为 1.25 秒和 1.19 秒。这个长尾来自本地 PHP/进程调度环境，生产环境必须用正式 Web 服务器重新测量，不能把开发服务器数据当成线上 SLA。

## 已完成优化

### 前端资源

- 用字体子集工具 [gen-material-icons.py](../scripts/gen-material-icons.py) 生成当前全站使用的 Material Symbols 图标字体。
- 增加 [icons.css](../src-client/styles/icons.css) 和可追踪的图标清单 [material-symbols-icons.txt](../src-client/styles/material-symbols-icons.txt)。
- 移除仅用于加载动画的整套 `@m3e/web` 依赖，改用原生 CSS spinner。
- 升级 Vite 8、Vitest 5，清除开发工具链安全漏洞，并利用新版 Rolldown 缩小主包。

### 评论与实时功能

- Twikoo 评论计数改为 [TwikooCountService.php](../app/Modules/Comments/TwikooCountService.php) 的站内批量代理。
- 评论计数结果缓存 5 分钟；浏览器端缓存 30 秒。
- Twikoo 完整脚本只在评论区进入视口时加载。
- 评论计数在首屏渲染后的空闲时间加载，并在 `Save-Data` 模式保留服务端计数。

### 后端

- 预览 PHP 默认启用 OPcache，包括 CLI server 的 `opcache.enable_cli`。
- 成长系统的成就进度、已有解锁状态和称号条件改为批量读取/写入。
- 新增缓存目录中的 Twikoo 计数缓存，不依赖数据库新增表。

### 本地开发与部署

- [preview.ps1](../bin/preview.ps1) 改为启动 8 个 PHP worker，并通过无第三方依赖的 [dev-server.mjs](../scripts/dev-server.mjs) 按活跃连接调度。
- 开发代理直接托管 `public/` 静态资源，并对 CSS、JS、JSON、SVG 使用 gzip。
- Apache `.htaccess` 增加 Brotli 优先、gzip 回退规则。
- 修复 Swup 切页后 `<title>` 不更新的问题。
- 补回桌面侧栏缺失的“板块”入口。

## 仍有优化空间

1. **CSS 分层**：首页 Chrome Coverage 只匹配到约 20% 的 CSS 声明；`pages.css` 仍混合所有页面的样式。下一步可按首页、帖子、设置、后台拆包，而不是继续压缩当前单文件。
2. **后端查询预计算**：成长中心仍聚合钱包、签到、任务、答题、称号和成就。可将每日统计写入 `user_stats`，页面读取时不再重新计算全部条件。
3. **实时通知**：正式部署不要用 `php -S`。优先使用 Nginx + PHP-FPM + OPcache；SSE 并发继续增长时，将长连接迁移到 FrankenPHP worker mode、RoadRunner，或独立 Mercure/Redis 推送服务。
4. **搜索**：数据量增长后，把数据库模糊搜索迁移到 Meilisearch 或 Typesense，并把热门列表/主题计数放 Redis。
5. **图片**：上传图片建议在入站时生成 AVIF/WebP 和响应式尺寸；当前首页 logo 与静态图还可以进一步压缩。
6. **性能门禁**：把 `npm run typecheck`、`npm test`、`npm run build`、PHPUnit 和资源体积预算接入 CI。

## 技术栈建议

| 方案 | 建议 | 原因 |
| --- | --- | --- |
| 保留 Slim + Twig + TypeScript + Swup | **首选** | 当前页面主要是服务端内容；首屏和 SEO 已经良好，继续迁移能带来的收益低于成本。 |
| React / Next.js 全量重写 | **暂不建议** | 会重建路由、SSR、无障碍、Swup 和现有模块，且大概率增加客户端运行成本。只有在产品转为高度交互型应用时再评估。 |
| Astro + Vue/Svelte islands | 可选 | 适合未来替换 Swup 局部模块、逐步组件化，并能保留按页输出静态/SSR HTML；不建议一次性迁移。 |
| Symfony / Laravel | 视团队与业务增长 | 主要收益是工程结构、后台、队列和生态，不是天然更快。若后台任务和团队规模明显增长可迁移；现有 Slim 规模下没有必要。 |
| FrankenPHP / RoadRunner | **优先于换框架** | 解决 PHP 并发和常驻 worker 成本，特别适合 SSE、图片处理与高并发 API。 |

## 复测命令

```powershell
npm run typecheck
npm test
npm run build
npm audit

.\.runtime\php-8.2.35\php.exe -c .\.runtime\php-8.2.35\php-preview.ini .\vendor\bin\phpunit

powershell -NoProfile -ExecutionPolicy Bypass -File .\bin\preview.ps1
```

图标或动态图标数据变化后，重新生成字体：

```powershell
python -m pip install fonttools brotli
python .\scripts\gen-material-icons.py
npm run build
```
