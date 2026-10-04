<h1 align="center">MOFU社区</h1>

> 基于 Google Material 3 Expressive 的现代化校园社区平台

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MySQL%20%2F%20MariaDB-8.0%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL / MariaDB">
  <img src="https://img.shields.io/badge/TypeScript-5.6-3178C6?logo=typescript&logoColor=white" alt="TypeScript">
  <img src="https://img.shields.io/badge/Vite-5-646CFF?logo=vite&logoColor=white" alt="Vite 5">
  <img src="https://img.shields.io/badge/Material%203%20Expressive-%40m3e%2Fweb-6750A4" alt="Material 3 Expressive">
  <img src="https://img.shields.io/badge/License-Proprietary-lightgrey" alt="License: Proprietary">
</p>

<p align="center">
  <b>中文</b> · <a href="docs/i18n/README.en.md">English</a>
</p>

---

MOFU社区是一套面向高校场景的综合性社区平台：校园动态、匿名表白墙与树洞、关注与通知、个性化主题中心、内容审核与管理后台。后端采用 PHP + Slim 的 SSR 架构，前端以 TypeScript + Swup 实现应用外壳与平滑切页，整体设计严格遵循 Google **Material 3 Expressive（M3E）** 规范。

## 功能特性

- **校园动态** — 图文动态发布（最多 9 图）、编辑、删除、话题标签、公开 / 仅自己可见；推荐、最新、热门、关注四条信息流
- **互动系统** — 点赞、收藏、评论与回复、内容举报、复制链接分享；乐观更新 + 后端校验 + 防重复提交
- **关注关系** — 关注 / 取关、粉丝与关注列表、推荐关注
- **全站搜索** — 动态、用户、话题与表白墙统一检索；权限过滤（私密内容与封禁账号不外泄）、搜索历史、热门话题
- **匿名板块** — 表白墙与树洞：完全匿名 / 署名投稿（游客亦可匿名投稿）、分类、审核流程、匿名评论与点赞、「我的投稿」状态追踪
- **通知中心** — 互动通知、未读角标、一键全部已读
- **个人主页** — 动态、收藏、点赞、评论、粉丝 / 关注列表多标签页；资料编辑与头像上传
- **账号与安全** — 修改密码、登录设备管理（退出指定设备或其他所有设备）、通知偏好（点赞 / 评论 / 关注可分别关闭 + 免打扰时段）、隐私设置（私信权限与列表可见性）
- **封禁申诉** — 被停用 / 封禁的账号可通过申诉页提交申诉；管理员审核通过后自动恢复账号状态并通知；封禁即时生效（既有会话立即失效）
- **私信** — 一对一私信：会话列表、未读角标、消息历史、SSE 在线时新消息即时到达（保留 8 秒轮询兜底）
- **拉黑** — 拉黑后双向拦截关注与私信、自动解除已有关注关系；拉黑名单可在隐私设置中管理
- **兴趣圈子** — 创建 / 加入兴趣圈子（自由加入或审批加入）；圈内动态复用动态系统（图片 / 话题 / 评论 / 点赞），仅成员可见并与全站信息流、搜索、他人主页隔离；成员审批与移除、圈主可修改圈子设置
- **社区板块** — 论坛、问答、互助、学习交流、失物招领、二手交易六板块统一框架；问答 / 互助可标记「已解决」，二手可标记「已售」，失物区分寻物 / 招领；帖子支持点赞、收藏与回复，板块内按状态筛选与「最新 / 热门」排序
- **校园活动** — 发起活动（时间 / 地点 / 名额 / 可选封面图，封面复用上传压缩管线）；报名支持直接报名或审核制（组织者可批准 / 拒绝 / 移出），名额已满与截止时间自动管控；活动可按「即将开始 / 进行中 / 已结束」浏览，支持组织者编辑、取消（自动通知已报名者）与删除；「我的活动」聚合我报名的与我发起的
- **校园资讯** — 管理员发布的资讯频道（通知公告 / 校园新闻 / 学习资源）：草稿与发布、置顶、封面图、浏览量统计；首次发布自动向全站活跃用户推送资讯通知（幂等，不重复发送）；后台支持发布 / 隐藏 / 置顶 / 编辑 / 删除
- **PWA 与离线** — 可安装到主屏幕（Web App Manifest + 自适应图标 + Apple Touch 图标）；Service Worker 缓存构建产物与图片，断网时导航回退离线页；`sw.js` 版本随构建自动更新，缓存分层（预缓存 / 哈希资源 / 用户媒体 FIFO）
- **转发** — 动态详情页一键转发到自己的动态流（可取消）：引用卡展示原帖作者与摘要/首图，原帖删除后显示占位；转发计数与「转发了你的动态」通知；圈内动态与私密动态不可转发
- **浏览记录** — 登录用户看过的动态自动记录（去重取最近一次），`/history` 回看与一键清空；已删除内容与圈内帖不出现
- **实时通知** — SSE 长连接推送未读角标（通知 + 私信），断线自动重连、连续失败降级 30 秒轮询兜底；开发环境需多 worker（见教程）
- **动态视频** — 动态支持直传 1 个视频（MP4 / WebM，不转码，上限 `UPLOAD_VIDEO_MAX_MB`），与图片互斥；Feed 与详情页原生播放器（受部署 PHP 上传限额约束）
- **备份工具** — `php bin/backup.php` 纯 PHP 导出数据库 + 打包上传文件（不依赖 mysqldump），带保留策略与恢复说明
- **Web 安装向导** — 无命令行的虚拟主机也能部署：访问 `/install` 完成环境自检、数据库配置、网页内执行迁移与创建超级管理员，装完自动锁定
- **主题中心** — 基于 HCT 的动态配色（8 大调色板）、明暗 / 对比度 / 密度 / 动效、壁纸取色、多套主题保存；游客可本机试用
- **M3E 体验** — Swup 无刷新切页、M3E 加载动画（按钮 / 表单 / 页面切换）、响应式应用外壳（桌面侧栏 + 移动端底部导航与 FAB）、Material Symbols 图标
- **管理后台** — 仪表盘、用户（状态与角色）、动态、评论、表白墙审核、举报处理、申诉管理、圈子管理、板块管理、活动管理、资讯管理
- **安全** — 数据库会话、全站 CSRF、基于权限点的访问控制、接口限流、审计日志、上传校验

## 技术栈

| 层 | 技术 |
| --- | --- |
| 后端 | PHP ≥ 8.1 · Slim 4 · Twig 3 · vlucas/phpdotenv |
| 数据库 | MySQL / MariaDB（utf8mb4）· 项目内置迁移器 |
| 前端 | TypeScript（strict）· Vite 5 · Swup 4 · @m3e/web |
| 设计系统 | Material 3 Expressive · HCT 动态配色 · Material Symbols · Roboto |
| 测试 | PHPUnit 10 · Vitest 2 |

## 快速开始

> 需要：PHP ≥ 8.1（`pdo_mysql`、`mbstring`、`json`、`gd`）、Composer、Node.js ≥ 20、MySQL / MariaDB。

> 虚拟主机 / 无命令行部署：上传代码后直接访问站点域名，会在 `/install` 提供 Web 安装向导（环境自检 → 数据库配置 → 自动迁移并创建管理员，装完自动锁定）。以下为本地开发流程：

```bash
# 1. 安装依赖
composer install
npm install

# 2. 配置环境（复制后按需修改数据库连接等）
cp .env.example .env

# 3. 初始化数据库
php bin/migrate.php migrate

# 4. 构建前端资源（输出到 public/assets）
npm run build

# 5. 启动开发服务器
composer serve      # http://127.0.0.1:8000
```

更完整的搭建、架构与二次开发说明见 **[开发者教程](docs/tutorial.md)**。

## 常用命令

| 命令 | 说明 |
| --- | --- |
| `composer serve` | 启动 PHP 开发服务器（`127.0.0.1:8000`） |
| `bash bin/dev-start.sh` | 启动 MariaDB + 开发服务器（本地一体启动） |
| `php bin/migrate.php migrate \| status \| rollback` | 数据库迁移 / 状态 / 回退 |
| `npm run build` | 构建前端（Vite） |
| `bash bin/fe-build.sh` | 完整构建：前端 + Service Worker + 主题产物 + 文档刷新 |
| `php bin/backup.php` | 备份数据库与上传文件（默认 `storage/backups/`，支持 `--skip-uploads` / `--keep=N`） |
| `npm run dev` | Vite 开发服务器（HMR，配合 `.env` 的 `VITE_DEV_SERVER`） |
| `npm run docs` | 刷新开发者教程的自动区段 |
| `composer test` | 后端测试（PHPUnit） |
| `npm test` | 前端测试（Vitest） |

## 项目结构

```text
app/           PHP 应用（Bootstrap / Core / Modules / Views）
bin/           命令行工具（迁移、构建、开发启动）
docs/          文档（开发者教程 + 双语 README）
migrations/    数据库迁移
public/        Web 根目录（单入口 + 构建产物 + 上传）
scripts/       工程脚本（主题产物、文档生成）
src-client/    前端 TypeScript 源码
storage/       运行时数据（日志 / 缓存）
tests/         测试（PHPUnit + Vitest）
```

## 文档

- [开发者教程](docs/tutorial.md) — 环境搭建、架构解析、路由与模块、构建部署、FAQ（部分区段随构建自动刷新）
- [English README](docs/i18n/README.en.md)

## 路线图

- ✅ **第一阶段 · 基础平台** — 架构、M3E 设计系统、动态配色、响应式外壳、SSR、Swup、注册登录、首页与动态、点赞评论、基础后台
- ✅ **第二阶段 · 特色社区** — 表白墙、树洞、话题与分类、收藏、关注、通知中心、内容审核、举报系统、个人中心、个性化主题
- ✅ **持续完善** — 全站搜索、账号安全与登录设备管理、头像上传、通知偏好、点赞 / 评论标签页、动态编辑与链接分享、私信、拉黑与隐私设置、封禁申诉与免打扰
- ✅ **第三阶段 · 校园生态** — 兴趣圈子、社区板块（论坛 / 问答 / 互助 / 学习 / 失物 / 二手）、校园活动与报名、校园资讯
- 🚧 **第四阶段 · 高级功能** — PWA 与离线体验、SSE 实时通知、浏览记录、转发、视频直传、备份工具已上线；后续可扩展实时聊天与更多离线能力

## 贡献指南

1. Fork 本仓库并创建特性分支（`feat/xxx`、`fix/xxx`）；
2. 遵循《开发者教程》中的代码约定（严格类型、设计令牌、模块边界）；
3. 提交前运行 `composer test`、`npm test`，并确保前端构建通过；
4. 提交 Pull Request，描述改动动机与验证方式。

## 致谢

- [m3e](https://github.com/matraic/m3e)（[文档](https://matraic.github.io/m3e)）— Material 3 Expressive Web Components
- Slim Framework、Twig、Vite、Swup
- Material Symbols、Roboto、Material Color Utilities

## 许可证

私有软件（Proprietary）。未经授权，不得复制、分发或用于任何商业用途。详见 `composer.json` 中的 `license` 声明。
