# MOFU社区 · 开发者教程

> 面向继续开发本项目的开发者：从环境搭建、运行调试到架构解析、二次开发与部署。
> 带「自动生成」注记的区段由 `scripts/gen-docs.mjs` 从源码提取，并在每次构建（`bash bin/fe-build.sh`）后自动刷新；其余为手写内容。机制说明见[文末](#16-文档自动化说明)。

## 目录

1. [项目概览与架构总览](#1-项目概览与架构总览)
2. [环境要求与准备](#2-环境要求与准备)
3. [快速开始](#3-快速开始)
4. [配置说明](#4-配置说明)
5. [数据库与迁移](#5-数据库与迁移)
6. [项目结构](#6-项目结构)
7. [后端：请求生命周期与模块化架构](#7-后端请求生命周期与模块化架构)
8. [路由一览](#8-路由一览)
9. [前端：应用外壳、页面模块与加载动画](#9-前端应用外壳页面模块与加载动画)
10. [设计系统与主题定制](#10-设计系统与主题定制)
11. [构建流程与产物](#11-构建流程与产物)
12. [测试](#12-测试)
13. [开发指南（常见任务）](#13-开发指南常见任务)
14. [部署指南](#14-部署指南)
15. [常见问题 FAQ](#15-常见问题-faq)
16. [文档自动化说明](#16-文档自动化说明)

---

## 1. 项目概览与架构总览

MOFU社区是一套面向高校场景的综合性社区平台：校园动态、匿名表白墙与树洞、关注与通知、个性化外观、内容审核与管理后台。全站采用一套 Apple 风格设计系统：系统字体栈、固定品牌强调色 `#FFCC38`、克制的圆角与阴影，以及 Light / Dark / System 三种外观。

**技术栈一览**

| 层 | 技术 |
| --- | --- |
| 后端 | PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12+ · Slim 4 · Twig 3 · vlucas/phpdotenv |
| 数据库 | MySQL / MariaDB（utf8mb4）· 项目内置迁移器 |
| 前端 | TypeScript（strict）· Vite 8 · Swup 4 · Three.js · nanostores |
| 设计系统 | Apple-inspired tokens · 系统字体栈 · Light / Dark / System · Material Symbols |
| 测试 | PHPUnit 10（后端）· Vitest 5（前端） |

> 安全基线：PHP2Shell（CVE-2026-109618 / CVE-2026-109619）修复版本为 8.2.35、8.3.36、8.4.27、8.5.12。PHP 8.3.35 / 8.4.26 / 8.5.11 仍然受影响；临时缓解与升级步骤见 `docs/security/php2shell.md`。

**架构总览**

```
浏览器请求
  └─ public/index.php                  # 单入口
       └─ Bootstrap\App::create()      # 组装应用
            ├─ Config（.env）           # 配置
            ├─ Container               # 依赖容器（Core 服务 + Services::register 自动装配）
            ├─ 中间件（外→内）          # 错误处理 → 路由 → Body 解析 → 会话 → CSRF
            └─ 路由                    # app/Modules/*/routes.php 自动装载
                 └─ 模块控制器          # 业务逻辑（Service / Repository）
                      └─ Twig 渲染      # SSR 输出首屏 HTML
```

前端是「SSR + 渐进增强」：首屏由 Twig 服务端渲染；`src-client/main.ts` 启动应用外壳（主题、导航、全局交互），随后由 **Swup** 接管站内导航（无刷新切页，仅替换 `#swup` 主内容），并按页面标识（`data-page`）懒加载对应的客户端模块。

已上线的核心能力（持续更新）：全站搜索（动态 / 用户 / 话题 / 表白墙）、账号安全（修改密码与登录设备管理）、头像上传与全站展示、通知偏好（点赞/评论/关注可分别关闭 + 免打扰时段）、隐私设置（私信权限与列表可见性）、拉黑（双向拦截关注与私信）、私信（SSE 即时刷新 + 轮询兜底）、封禁申诉（管理员审核后自动解封）、兴趣圈子（公开 / 审批加入，圈内动态仅成员可见并与全站信息流隔离）、社区板块（论坛 / 问答 / 互助 / 学习 / 失物 / 二手统一框架）、校园活动（直接 / 审核制报名、名额与封面图）、校园资讯（管理员发布，首次发布广播通知）、动态转发（快捷转发与引用卡）、浏览记录（去重回看与清空）、实时通知（SSE 未读推送，降级轮询）、动态视频（直传 MP4/WebM）、PWA 与离线（可安装、离线兜底）、备份工具（`bin/backup.php`）、个人中心（动态 / 收藏 / 点赞 / 评论 / 关注 / 粉丝）、动态编辑与链接分享。相关页面：`/search`、`/messages`、`/appeal`、`/circles`、`/boards`、`/events`、`/news`、`/history`、`/settings/security`、`/settings/notifications`、`/settings/privacy`、`/topics/{slug}`。

---

## 2. 环境要求与准备

| 依赖 | 版本 | 说明 |
| --- | --- | --- |
| PHP | ≥ 8.1 | 必需扩展：`pdo_mysql`、`mbstring`、`json`；缩略图需要 `gd`；Composer 安装依赖时通常还需要 `curl`、`zip` |
| Composer | 2.x | 安装 PHP 依赖 |
| Node.js | ≥ 20（开发环境使用 22） | 前端构建与测试 |
| MySQL / MariaDB | MySQL 5.7+ / MariaDB 10.3+ | 字符集 utf8mb4 |

---

## 3. 快速开始

```bash
# 1. 安装依赖
composer install
npm install

# 2. 配置环境（按需修改数据库连接等）
cp .env.example .env

# 3. 初始化数据库
php bin/migrate.php migrate

# 4. 构建前端资源（产物输出到 public/assets）
npm run build

# 5. 启动开发服务器
composer serve      # http://127.0.0.1:8000
```

**常见坑**

- **构建目录**：本项目的开发容器中，工作区位于共享存储（不支持可执行权限与符号链接），因此 `node_modules` 与构建过程托管在 `/root/mofu-build`。在此环境下请统一使用 `bash bin/fe-build.sh` 构建；普通机器上 `npm install && npm run build` 即可。
- `storage/` 与 `public/uploads/` 需要写权限（日志、缓存、上传）。
- 开发时如需 HMR：另开终端运行 `npm run dev`，并把 `.env` 中 `VITE_DEV_SERVER` 指向 Vite 地址（如 `http://127.0.0.1:5173`），前端将直接从 dev server 加载模块。

---

## 4. 配置说明

配置读取逻辑在 `app/Bootstrap/Config.php`（基于 vlucas/phpdotenv）。**自动生成**区段如下：

<!-- AUTO-GEN:env:start -->
共 32 项。配置读取逻辑见 `app/Bootstrap/Config.php`，未配置时使用默认值。

**基础**

| 变量 | 说明 | 示例 / 默认值 |
| --- | --- | --- |
| `APP_NAME` | — | `"MOFU社区"` |
| `APP_ENV` | — | `local` |
| `APP_DEBUG` | — | `true` |
| `APP_URL` | — | `http://127.0.0.1:8000` |
| `APP_KEY` | 通过 /install 向导安装时会自动生成。 | （留空） |

**数据库（MySQL / MariaDB）**

| 变量 | 说明 | 示例 / 默认值 |
| --- | --- | --- |
| `DB_HOST` | — | `127.0.0.1` |
| `DB_PORT` | — | `3306` |
| `DB_DATABASE` | — | `mofu` |
| `DB_USERNAME` | — | `mofu` |
| `DB_PASSWORD` | — | （留空） |

**会话**

| 变量 | 说明 | 示例 / 默认值 |
| --- | --- | --- |
| `SESSION_COOKIE` | — | `mofu_session` |
| `SESSION_LIFETIME` | 会话有效期（秒），默认 30 天 | `2592000` |

**前端构建**

| 变量 | 说明 | 示例 / 默认值 |
| --- | --- | --- |
| `VITE_DEV_SERVER` | 开发时填 Vite dev server 地址（如 http://127.0.0.1:5173）以获得 HMR；留空则读取构建产物 manifest | （留空） |

**上传**

| 变量 | 说明 | 示例 / 默认值 |
| --- | --- | --- |
| `UPLOAD_MAX_MB` | — | `8` |
| `UPLOAD_VIDEO_MAX_MB` | 视频上传上限（MB）；部署环境需同步调整 php.ini 的 upload_max_filesize / post_max_size | `60` |

**品牌默认主题色（HEX，苹果系统蓝；用户可在主题中心覆盖）**

| 变量 | 说明 | 示例 / 默认值 |
| --- | --- | --- |
| `THEME_DEFAULT_COLOR` | — | `#FFCC38` |

**音乐播放器（独立应用，部署在 Cloudflare Pages）**

| 变量 | 说明 | 示例 / 默认值 |
| --- | --- | --- |
| `MUSIC_APP_URL` | 填了就会在 /music 内嵌播放器；留空则显示占位说明。代码在 music-app/，部署见该目录 README | （留空） |
| `TWIKOO_ENV_ID` | Twikoo 评论系统（留空 TWIKOO_ENV_ID 则只用站内评论） | （留空） |
| `TWIKOO_SCRIPT_URL` | 评论脚本地址：默认用站内自托管副本（public/vendor/twikoo.all.min.js），可改为自建 CDN | `/vendor/twikoo.all.min.js` |
| `TWIKOO_LANG` | 评论语言：zh-CN 已内嵌在主包里（不产生额外请求） | `zh-CN` |
| `TWIKOO_EMOTION_CDN` | 表情包（OwO JSON）：默认站内自带的 Apple 表情包，前端通过覆写 GET_CONFIG 生效 | `/vendor/emoji-apple.json` |
| `MAIL_MAILER` | 生产请改为 smtp 并填写下面几项 | `log` |
| `MAIL_HOST` | — | （留空） |
| `MAIL_PORT` | — | `465` |
| `MAIL_ENCRYPTION` | 加密：ssl（465 直连）\| tls（587 STARTTLS）\| 留空（25 明文） | `ssl` |
| `MAIL_USERNAME` | — | （留空） |
| `MAIL_PASSWORD` | — | （留空） |
| `MAIL_FROM` | 发件人地址（留空时用 no-reply@<当前域名>） | （留空） |
| `APP_KEY` | 应用密钥：用于邮箱验证码哈希等；安装向导会自动生成；留空时退化为由数据库口令派生（仅服务端可见） | （留空） |
| `EZFP_API` | 易支付（V1，MD5 签名）：接口地址 / 商户 ID / 商户密钥 | `https://www.ezfp.cn/` |
| `EZFP_PID` | — | （留空） |
| `EZFP_KEY` | — | （留空） |
<!-- AUTO-GEN:env:end -->

> 生产环境务必：关闭 `APP_DEBUG`、设置强数据库密码、按域名设置 `APP_URL`。

---

## 5. 数据库与迁移

项目自带轻量迁移器（`app/Core/Database/Migrator.php`），迁移文件位于 `migrations/`，按文件名顺序执行，已应用的迁移记录在 `migrations` 表中。

```bash
php bin/migrate.php migrate        # 执行所有未应用的迁移
php bin/migrate.php status         # 查看迁移状态
php bin/migrate.php rollback [N]   # 回退最近 N 个（默认 1）
# 也可以使用 composer 脚本：
composer migrate
composer migrate:status
```

> 没有命令行环境的虚拟主机：访问 `/install` 由 Web 安装向导在页面内执行同样的迁移（见[部署指南](#14-部署指南)）。

**自动生成**的数据表清单：

<!-- AUTO-GEN:tables:start -->
共创建 78 张数据表（其中 2 张已在后续迁移中删除）。

| 迁移文件 | 创建的数据表 |
| --- | --- |
| `001_users_and_sessions.sql` | `users`、`user_profiles`、`user_sessions` |
| `002_roles_and_permissions.sql` | `roles`、`permissions`、`role_permissions`、`user_roles` |
| `003_content_core.sql` | `media`、`posts`、`post_media`、`topics`、`post_topics` |
| `004_comments_and_reactions.sql` | `comments`、`reactions` |
| `005_notifications_and_moderation.sql` | `notifications`、`reports`、`moderation_records` |
| `006_preferences_themes_system.sql` | `user_preferences`、`user_themes`、`system_settings`、`audit_logs` |
| `007_follows.sql` | `follows` |
| `008_confessions.sql` | `confessions`、`confession_comments`、`confession_media` |
| `009_reactions_extend.sql` | （结构 / 数据变更，无新表） |
| `010_settings_update.sql` | （结构 / 数据变更，无新表） |
| `011_messages_and_blocks.sql` | `conversations`、`messages`、`user_blocks` |
| `012_user_appeals.sql` | `user_appeals` |
| `013_communities_and_boards.sql` | `communities`、`community_members`、`boards`、`board_entries`、`board_entry_comments` |
| `014_events.sql` | `events`、`event_signups` |
| `015_news.sql` | `news` |
| `016_phase4.sql` | `post_views` |
| `017_backgrounds.sql` | （结构 / 数据变更，无新表） |
| `018_indexes.sql` | （结构 / 数据变更，无新表） |
| `019_appeal_token.sql` | （结构 / 数据变更，无新表） |
| `020_board_logo.sql` | （结构 / 数据变更，无新表） |
| `021_brand_color.sql` | （结构 / 数据变更，无新表） |
| `022_community_systems.sql` | `user_bans`、`announcements`、`announcement_reads`、`user_wallets`、`wallet_transactions`、`level_configs`、`user_levels`、`user_stats`、`titles`、`user_titles`、`achievements`、`user_achievements`、`check_ins`、`check_in_rewards`、`shop_products`、`shop_orders`、`user_inventory`、`games`、`game_records`、`growth_daily_counters` |
| `023_membership_and_coins.sql` | `email_verifications`、`quiz_questions`、`quiz_attempts`、`post_coins` |
| `024_user_tasks.sql` | `user_tasks` |
| `025_drop_games.sql` | 删除：`game_records`、`games` |
| `026_integrity_and_fk.sql` | （结构 / 数据变更，无新表） |
| `027_search_and_list_index.sql` | （结构 / 数据变更，无新表） |
| `028_flash_level.sql` | （结构 / 数据变更，无新表） |
| `029_wallet_decimals_and_paid_posts.sql` | `post_purchases` |
| `030_message_recall.sql` | （结构 / 数据变更，无新表） |
| `031_message_media.sql` | （结构 / 数据变更，无新表） |
| `032_tags.sql` | `tags`、`post_tags` |
| `033_levels_and_permissions.sql` | （结构 / 数据变更，无新表） |
| `034_memberships.sql` | `membership_orders` |
| `035_payment_and_shop.sql` | `payment_orders` |
| `036_invites.sql` | `invite_codes` |
| `037_jury.sql` | `jury_applications`、`jury_members`、`jury_cases`、`jury_votes`、`jury_stipends` |
| `038_jail_and_lab.sql` | `lab_features`、`lab_access` |
| `039_nickname.sql` | `nickname_change_log` |
| `040_view_reward_and_special_follow.sql` | `view_reward_settlements` |
| `041_flash_term.sql` | （结构 / 数据变更，无新表） |
| `042_payment_payload.sql` | （结构 / 数据变更，无新表） |
| `043_lab_seed.sql` | （结构 / 数据变更，无新表） |
| `044_quiz_category.sql` | （结构 / 数据变更，无新表） |
| `045_system_manage_permission.sql` | （结构 / 数据变更，无新表） |
| `046_scene_banners_and_profile_decor.sql` | （结构 / 数据变更，无新表） |
| `047_fixed_apple_brand.sql` | （结构 / 数据变更，无新表） |
<!-- AUTO-GEN:tables:end -->

---

## 6. 项目结构

**自动生成**的目录速览（根目录 + `app`、`src-client` 展开一层）：

<!-- AUTO-GEN:tree:start -->
```text
├─ app/  # PHP 应用（Bootstrap / Core / Install / Modules / Views）
│  ├─ Bootstrap/  # 启动、容器、配置与错误处理
│  ├─ Core/  # 框架内核：数据库、HTTP、安全、视图
│  ├─ Install/  # Web 安装向导（门禁 / 环境自检 / .env 生成）
│  ├─ Modules/  # 业务模块
│  └─ Views/  # Twig 模板（layouts / components / pages）
├─ bin/  # 命令行工具（迁移、构建、开发启动）
├─ docs/  # 项目文档（本教程 + 双语 README）
├─ font/
├─ migrations/  # 数据库迁移（按序执行的 SQL 文件）
├─ music-app/
├─ public/  # Web 根目录（单入口 + 静态资源）
├─ scripts/  # 工程脚本（构建与文档生成）
├─ src-client/  # 前端 TypeScript 源码
│  ├─ core/  # 前端内核：API、状态、导航与全局交互
│  ├─ modules/  # 页面级客户端模块（懒加载）
│  ├─ pwa/
│  ├─ styles/  # 样式（设计令牌 / 布局 / 页面 / 组件层）
│  ├─ theme/  # Light / Dark / System 外观引擎
│  ├─ types/
│  ├─ main.ts
│  ├─ sw.ts
│  └─ types.ts
├─ storage/  # 运行时数据（日志 / 缓存，部署时需可写）
├─ tests/  # 测试（PHPUnit + Vitest）
├─ 数据/
├─ .env.example  # 环境配置样例（复制为 .env 使用）
├─ .gitignore
├─ 10.zip
├─ CHANGELOG.md
├─ README.md
├─ SECURITY.md
├─ VERSION
├─ app-download.html
├─ composer.json
├─ exectest.sh
├─ logo.png
├─ mofu-deploy.zip
├─ music-app.zip
├─ package.json
├─ phpunit.xml
├─ tsconfig.json
├─ vite.config.ts
├─ 秋奈字体.ttf
├─ 维护.html
└─ 题目.txt
```
<!-- AUTO-GEN:tree:end -->

---

## 7. 后端：请求生命周期与模块化架构

### 7.1 启动流程（`app/Bootstrap/`）

1. `public/index.php` 定义 `MOFU_ROOT` 并预检 `vendor/`，加载 Composer autoload；随后进入**安装门禁**（`app/Install/InstallGate.php`，见第 14 节）：站点未完成安装时接管请求（跳转 `/install` 向导或进入向导本身），已安装（`storage/install.lock` 存在）则继续；
2. `App::create()` 依次：设置时区（Asia/Shanghai）→ 读取配置 → 创建容器 → 注册核心服务（Logger、PDO、Query、ViteAssets、TwigRenderer、SessionManager、Auth、UserRepository）→ `Services::register()` 注册业务服务与控制器（构造器自动装配）；
3. 创建 Slim 应用并挂载中间件（洋葱模型，后添加者在外层）：
   `错误处理 → 路由 → Body 解析 → 会话（SessionMiddleware）→ CSRF（CsrfMiddleware）→ 处理器`；
4. 装载路由：遍历 `app/Modules/*/routes.php`，每个文件返回 `callable(SlimApp, Container)`。

### 7.2 模块结构（`app/Modules/`）

每个业务模块自包含：`*Controller`（页面/接口）、`*Service` / `*Repository`（业务与数据访问）、`routes.php`（路由注册）。API 控制器继承 `Core\Http\ApiController`，统一 JSON 响应与错误格式。

### 7.3 框架内核（`app/Core/`）

| 目录 | 内容 |
| --- | --- |
| `Database/` | `Connection`（PDO，关闭模拟预处理）、`Query`（查询构建与分页）、`Migrator`（迁移） |
| `Http/` | `HttpException`、`Ctx`（请求上下文）、`Json`、`ApiController`、`Middleware/`（会话与 CSRF） |
| `Security/` | `SessionManager`（数据库会话）、`Csrf`、`Guard`（权限）、`Auth`、`RateLimiter`（限流） |
| `Support/` | `Logger`、`Text`（昵称/摘要等）、`AuditLogger`（审计）、`AliasGenerator` |
| `View/` | `TwigRenderer`、`ViteAssets`（桥接构建产物/开发服务器）、`ViewContext`、`Present`、`Page` |

### 7.4 安全要点

- **会话**：持久化在 `user_sessions` 表，`SESSION_COOKIE` 可配置，登录时轮换会话 ID；
- **CSRF**：全站双提交令牌，所有变更请求校验 `_csrf`；表单与 `window.__MOFU__.csrf` 均可用；
- **权限**：`Guard::require($user, '权限点')` 基于角色/权限表；管理后台各页均有独立权限点；
- **限流**：`RateLimiter` 覆盖登录、发帖、评论、互动、举报等接口；
- **审计**：管理操作写入审计日志（`AuditLogger`）。

> 新增代码注意：本项目 PDO 关闭了模拟预处理，**同一条 SQL 中不允许同一命名占位符出现多次**（会抛 `HY093`），需要为每处使用不同参数名。

---

## 8. 路由一览

**自动生成**（来自各模块 `routes.php`）：

<!-- AUTO-GEN:routes:start -->
共 265 条路由。约定：页面路由直接返回 HTML（SSR）；`/api/v1` 前缀为 JSON 接口，由前端模块异步调用。

| 模块 | 方法 | 路径 | 处理器 |
| --- | --- | --- | --- |
| Admin | `GET` | `/admin` | `AdminController::index()` |
| Admin | `GET` | `/admin/dashboard` | `AdminController::dashboard()` |
| Admin | `GET` | `/admin/users` | `AdminController::users()` |
| Admin | `GET` | `/admin/posts` | `AdminController::posts()` |
| Admin | `GET` | `/admin/comments` | `AdminController::comments()` |
| Admin | `GET` | `/admin/confessions` | `AdminController::confessions()` |
| Admin | `GET` | `/admin/reports` | `AdminController::reports()` |
| Admin | `GET` | `/admin/appeals` | `AdminController::appeals()` |
| Admin | `GET` | `/admin/circles` | `AdminController::circles()` |
| Admin | `GET` | `/admin/boards` | `AdminController::boardEntries()` |
| Admin | `GET` | `/admin/board-settings` | `AdminController::boardSettings()` |
| Admin | `GET` | `/admin/team` | `AdminController::team()` |
| Admin | `GET` | `/admin/banners` | `AdminController::banners()` |
| Admin | `GET` | `/admin/comments/export-twikoo` | `AdminController::exportCommentsTwikoo()` |
| Admin | `GET` | `/admin/events` | `AdminController::events()` |
| Admin | `GET` | `/admin/news` | `AdminController::news()` |
| Admin | `GET` | `/admin/announcements` | `AdminController::announcements()` |
| Admin | `GET` | `/admin/titles` | `AdminController::titles()` |
| Admin | `GET` | `/admin/tags` | `AdminController::tags()` |
| Admin | `GET` | `/admin/jury` | `AdminController::jury()` |
| Admin | `GET` | `/admin/lab` | `AdminController::lab()` |
| Admin | `GET` | `/admin/shop` | `AdminController::shop()` |
| Admin | `GET` | `/admin/checkin` | `AdminController::checkin()` |
| Admin | `GET` | `/admin/membership` | `AdminController::membership()` |
| Admin | `GET` | `/admin/growth` | `AdminController::growth()` |
| Admin | `GET` | `/admin/economy` | `AdminController::economy()` |
| Admin | `POST` | `/admin/announcements` | `AdminController::saveAnnouncement()` |
| Admin | `POST` | `/admin/announcements/{id:[0-9]+}/save` | `AdminController::saveAnnouncement()` |
| Admin | `POST` | `/admin/announcements/{id:[0-9]+}/delete` | `AdminController::deleteAnnouncement()` |
| Admin | `POST` | `/admin/titles` | `AdminController::saveTitle()` |
| Admin | `POST` | `/admin/tags` | `AdminController::saveTag()` |
| Admin | `POST` | `/admin/tags/{id:[0-9]+}/save` | `AdminController::saveTag()` |
| Admin | `POST` | `/admin/tags/{id:[0-9]+}/delete` | `AdminController::deleteTag()` |
| Admin | `POST` | `/admin/jury/applications/{id:[0-9]+}/decide` | `AdminController::decideJuryApplication()` |
| Admin | `POST` | `/admin/jury/members/{id:[0-9]+}/rate` | `AdminController::rateJuryMember()` |
| Admin | `POST` | `/admin/jury/members/{id:[0-9]+}/remove` | `AdminController::removeJuryMember()` |
| Admin | `POST` | `/admin/jury/cases` | `AdminController::openJuryCase()` |
| Admin | `POST` | `/admin/jury/cases/{id:[0-9]+}/close` | `AdminController::closeJuryCase()` |
| Admin | `POST` | `/admin/jury/stipend` | `AdminController::payJuryStipend()` |
| Admin | `POST` | `/admin/jury/settle` | `AdminController::settleJuryYear()` |
| Admin | `POST` | `/admin/lab` | `AdminController::saveLab()` |
| Admin | `POST` | `/admin/lab/{id:[0-9]+}/save` | `AdminController::saveLab()` |
| Admin | `POST` | `/admin/lab/{id:[0-9]+}/toggle` | `AdminController::toggleLab()` |
| Admin | `POST` | `/admin/lab/{id:[0-9]+}/delete` | `AdminController::deleteLab()` |
| Admin | `POST` | `/admin/titles/grant` | `AdminController::grantTitle()` |
| Admin | `POST` | `/admin/titles/achievements` | `AdminController::saveAchievement()` |
| Admin | `POST` | `/admin/titles/{id:[0-9]+}/save` | `AdminController::saveTitle()` |
| Admin | `POST` | `/admin/titles/{id:[0-9]+}/delete` | `AdminController::deleteTitle()` |
| Admin | `POST` | `/admin/shop/products` | `AdminController::saveProduct()` |
| Admin | `POST` | `/admin/shop/products/{id:[0-9]+}/save` | `AdminController::saveProduct()` |
| Admin | `POST` | `/admin/shop/products/{id:[0-9]+}/action` | `AdminController::productAction()` |
| Admin | `POST` | `/admin/checkin/rewards` | `AdminController::saveCheckinRewards()` |
| Admin | `POST` | `/admin/membership/levels` | `AdminController::saveLevels()` |
| Admin | `POST` | `/admin/membership/questions` | `AdminController::saveQuestion()` |
| Admin | `POST` | `/admin/membership/questions/{id:[0-9]+}/delete` | `AdminController::deleteQuestion()` |
| Admin | `POST` | `/admin/membership/grant` | `AdminController::grantGrowth()` |
| Admin | `GET` | `/admin/users/{id:[0-9]+}/ban` | `AdminController::userBanForm()` |
| Admin | `POST` | `/admin/users/{id:[0-9]+}/moderate` | `AdminController::userModerate()` |
| Admin | `GET` | `/admin/news/new` | `AdminController::newsNew()` |
| Admin | `POST` | `/admin/users/{id:[0-9]+}/status` | `AdminController::setUserStatus()` |
| Admin | `POST` | `/admin/users/{id:[0-9]+}/role` | `AdminController::setUserRole()` |
| Admin | `POST` | `/admin/posts/{id:[0-9]+}/status` | `AdminController::setPostStatus()` |
| Admin | `POST` | `/admin/posts/{id:[0-9]+}/delete` | `AdminController::deletePost()` |
| Admin | `POST` | `/admin/comments/{id:[0-9]+}/remove` | `AdminController::removeComment()` |
| Admin | `POST` | `/admin/confessions/{id:[0-9]+}/moderate` | `AdminController::moderateConfession()` |
| Admin | `POST` | `/admin/reports/{id:[0-9]+}/resolve` | `AdminController::resolveReport()` |
| Admin | `POST` | `/admin/appeals/{id:[0-9]+}/resolve` | `AdminController::resolveAppeal()` |
| Admin | `POST` | `/admin/circles/{id:[0-9]+}/active` | `AdminController::setCircleActive()` |
| Admin | `POST` | `/admin/circles/{id:[0-9]+}/delete` | `AdminController::deleteCircle()` |
| Admin | `POST` | `/admin/boards/create` | `AdminController::createBoardEntry()` |
| Admin | `POST` | `/admin/boards/{id:[0-9]+}/save` | `AdminController::saveBoardEntry()` |
| Admin | `POST` | `/admin/boards/{id:[0-9]+}/status` | `AdminController::setEntryStatus()` |
| Admin | `POST` | `/admin/boards/{id:[0-9]+}/delete` | `AdminController::deleteEntry()` |
| Admin | `POST` | `/admin/boards/{id:[0-9]+}/logo` | `AdminController::saveBoardLogo()` |
| Admin | `POST` | `/admin/team` | `AdminController::saveTeam()` |
| Admin | `POST` | `/admin/banners` | `AdminController::saveBanners()` |
| Admin | `POST` | `/admin/events/{id:[0-9]+}/status` | `AdminController::setEventStatus()` |
| Admin | `POST` | `/admin/events/{id:[0-9]+}/delete` | `AdminController::deleteEvent()` |
| Admin | `POST` | `/admin/news` | `AdminController::newsCreate()` |
| Admin | `GET` | `/admin/news/{id:[0-9]+}/edit` | `AdminController::newsEdit()` |
| Admin | `POST` | `/admin/news/{id:[0-9]+}` | `AdminController::newsUpdate()` |
| Admin | `POST` | `/admin/news/{id:[0-9]+}/status` | `AdminController::newsStatus()` |
| Admin | `POST` | `/admin/news/{id:[0-9]+}/pin` | `AdminController::newsPin()` |
| Admin | `POST` | `/admin/news/{id:[0-9]+}/delete` | `AdminController::newsDelete()` |
| Admin | `GET` | `/admin/system` | `AdminController::system()` |
| Admin | `POST` | `/admin/system/migrate` | `AdminController::runMigrations()` |
| Admin | `POST` | `/admin/system/backup` | `AdminController::exportBackup()` |
| Admin | `GET` | `/admin/changelog` | `AdminController::changelog()` |
| Admin | `POST` | `/admin/changelog` | `AdminController::saveChangelog()` |
| Announcements | `GET` | `/api/v1/announcements` | — |
| Announcements | `POST` | `/api/v1/announcements/read` | — |
| Announcements | `POST` | `/api/v1/announcements/{id:[0-9]+}/dismiss` | — |
| Appeals | `GET` | `/appeal` | `AppealController::show()` |
| Appeals | `POST` | `/appeal` | `AppealController::submit()` |
| Auth | `GET` | `/login` | — |
| Auth | `POST` | `/login` | — |
| Auth | `POST` | `/logout` | — |
| Auth | `GET` | `/register` | — |
| Auth | `POST` | `/register` | — |
| Auth | `GET` | `/register/verify` | — |
| Auth | `POST` | `/register/verify` | — |
| Auth | `GET` | `/password/reset` | — |
| Auth | `POST` | `/password/reset` | — |
| Auth | `POST` | `/password/reset/verify` | — |
| Boards | `GET` | `/boards` | `BoardController::index()` |
| Boards | `GET` | `/boards/{slug}/new` | `BoardController::new()` |
| Boards | `POST` | `/boards/{slug}` | `BoardController::create()` |
| Boards | `GET` | `/boards/{slug}` | `BoardController::board()` |
| Boards | `GET` | `/boards/{slug}/{id:[0-9]+}` | `BoardController::show()` |
| Boards | `POST` | `/boards/{slug}/{id:[0-9]+}/state` | `BoardController::toggleState()` |
| Boards | `POST` | `/boards/{slug}/{id:[0-9]+}/delete` | `BoardController::delete()` |
| Boards | `POST` | `/boards/{slug}/{id:[0-9]+}/comments` | `BoardController::comment()` |
| Comments | `GET` | `/api/v1/posts/{id:[0-9]+}/comments` | `CommentApiController::index()` |
| Comments | `POST` | `/api/v1/comments` | `CommentApiController::store()` |
| Comments | `DELETE` | `/api/v1/comments/{id:[0-9]+}` | `CommentApiController::destroy()` |
| Communities | `GET` | `/circles` | `CommunityController::index()` |
| Communities | `GET` | `/circles/new` | `CommunityController::new()` |
| Communities | `POST` | `/circles` | `CommunityController::create()` |
| Communities | `GET` | `/circles/{slug}` | `CommunityController::show()` |
| Communities | `GET` | `/circles/{slug}/new` | `CommunityController::newPost()` |
| Communities | `POST` | `/circles/{slug}/join` | `CommunityController::join()` |
| Communities | `POST` | `/circles/{slug}/leave` | `CommunityController::leave()` |
| Communities | `POST` | `/circles/{slug}/update` | `CommunityController::updateInfo()` |
| Communities | `POST` | `/circles/{slug}/members/{id:[0-9]+}/approve` | `CommunityController::approve()` |
| Communities | `POST` | `/circles/{slug}/members/{id:[0-9]+}/reject` | `CommunityController::reject()` |
| Communities | `POST` | `/circles/{slug}/members/{id:[0-9]+}/remove` | `CommunityController::remove()` |
| Communities | `GET` | `/api/v1/communities/{id:[0-9]+}/posts` | `CommunityApiController::posts()` |
| Confessions | `GET` | `/wall` | `ConfessionController::wall()` |
| Confessions | `GET` | `/wall/new` | `ConfessionController::composeWall()` |
| Confessions | `GET` | `/wall/{id:[0-9]+}` | `ConfessionController::show()` |
| Confessions | `GET` | `/hole` | `ConfessionController::hole()` |
| Confessions | `GET` | `/hole/new` | `ConfessionController::composeHole()` |
| Confessions | `GET` | `/hole/{id:[0-9]+}` | `ConfessionController::show()` |
| Confessions | `GET` | `/my/confessions` | `ConfessionController::mine()` |
| Confessions | `GET` | `/api/v1/confessions` | `ConfessionApiController::index()` |
| Confessions | `POST` | `/api/v1/confessions` | `ConfessionApiController::store()` |
| Confessions | `GET` | `/api/v1/confessions/{id:[0-9]+}` | `ConfessionApiController::show()` |
| Confessions | `DELETE` | `/api/v1/confessions/{id:[0-9]+}` | `ConfessionApiController::destroy()` |
| Confessions | `GET` | `/api/v1/confessions/{id:[0-9]+}/comments` | `ConfessionApiController::comments()` |
| Confessions | `POST` | `/api/v1/confession-comments` | `ConfessionApiController::commentStore()` |
| Confessions | `DELETE` | `/api/v1/confession-comments/{id:[0-9]+}` | `ConfessionApiController::commentDestroy()` |
| Events | `GET` | `/events` | `EventController::index()` |
| Events | `GET` | `/events/mine` | `EventController::mine()` |
| Events | `GET` | `/events/new` | `EventController::newForm()` |
| Events | `POST` | `/events` | `EventController::create()` |
| Events | `GET` | `/events/{id:[0-9]+}` | `EventController::show()` |
| Events | `GET` | `/events/{id:[0-9]+}/edit` | `EventController::editForm()` |
| Events | `POST` | `/events/{id:[0-9]+}` | `EventController::update()` |
| Events | `POST` | `/events/{id:[0-9]+}/signup` | `EventController::signup()` |
| Events | `POST` | `/events/{id:[0-9]+}/signup/cancel` | `EventController::cancelSignup()` |
| Events | `POST` | `/events/{id:[0-9]+}/signups/{userId:[0-9]+}/{action:approve|reject|remove}` | `EventController::manageSignup()` |
| Events | `POST` | `/events/{id:[0-9]+}/cancel` | `EventController::cancel()` |
| Events | `POST` | `/events/{id:[0-9]+}/delete` | `EventController::delete()` |
| Follows | `POST` | `/api/v1/follows/toggle` | `FollowApiController::toggle()` |
| Growth | `GET` | `/growth` | `GrowthController::index()` |
| Growth | `GET` | `/me/growth` | `GrowthController::index()` |
| Growth | `GET` | `/shop` | `ShopController::index()` |
| Growth | `GET` | `/api/v1/growth` | — |
| Growth | `GET` | `/api/v1/growth/wallet` | — |
| Growth | `POST` | `/api/v1/growth/checkin` | — |
| Growth | `GET` | `/api/v1/growth/calendar` | — |
| Growth | `POST` | `/api/v1/growth/title` | — |
| Growth | `GET` | `/api/v1/shop` | — |
| Growth | `POST` | `/api/v1/shop/{id:[0-9]+}/purchase` | — |
| Growth | `POST` | `/api/v1/shop/{id:[0-9]+}/pay` | — |
| Growth | `GET` | `/api/v1/shop/orders` | — |
| Growth | `GET` | `/api/v1/posts/{id:[0-9]+}/coins` | — |
| Growth | `POST` | `/api/v1/posts/{id:[0-9]+}/coins` | — |
| History | `GET` | `/history` | `HistoryController::index()` |
| History | `POST` | `/history/clear` | `HistoryController::clear()` |
| Home | `GET` | `/` | `HomeController::index()` |
| Home | `GET` | `/about` | `HomeController::about()` |
| Home | `GET` | `/about/changelog` | `HomeController::changelog()` |
| Home | `GET` | `/robots.txt` | `HomeController::robots()` |
| Home | `GET` | `/sitemap.xml` | `HomeController::sitemap()` |
| Home | `GET` | `/music` | `HomeController::music()` |
| Home | `GET` | `/konbini` | `HomeController::konbini()` |
| Invites | `GET` | `/invites` | `InviteController::index()` |
| Invites | `GET` | `/api/v1/invites` | — |
| Invites | `POST` | `/api/v1/invites/purchase` | — |
| Jail | `GET` | `/jail` | `JailController::index()` |
| Jury | `GET` | `/jury` | — |
| Jury | `POST` | `/jury/apply` | — |
| Jury | `POST` | `/jury/cases/{id:[0-9]+}/vote` | — |
| Lab | `GET` | `/lab` | — |
| Lab | `POST` | `/lab/{key:[a-z0-9\-_]+}/join` | — |
| Lab | `POST` | `/lab/{key:[a-z0-9\-_]+}/leave` | — |
| Media | `POST` | `/api/v1/uploads` | `MediaApiController::upload()` |
| Membership | `GET` | `/membership` | `MembershipController::index()` |
| Membership | `GET` | `/api/v1/membership` | — |
| Membership | `POST` | `/api/v1/membership/tasks/{source:[a-z_]+}` | — |
| Membership | `GET` | `/api/v1/membership/quiz` | — |
| Membership | `POST` | `/api/v1/membership/quiz/submit` | — |
| Membership | `POST` | `/api/v1/membership/premium-bonus` | — |
| Membership | `POST` | `/api/v1/membership/purchase` | — |
| Messages | `GET` | `/messages` | `MessageController::index()` |
| Messages | `GET` | `/messages/with/{username}` | `MessageController::openWith()` |
| Messages | `GET` | `/messages/{id:[0-9]+}` | `MessageController::show()` |
| Messages | `GET` | `/api/v1/messages/unread-count` | `MessageApiController::unreadCount()` |
| Messages | `GET` | `/api/v1/conversations/{id:[0-9]+}/messages` | `MessageApiController::messages()` |
| Messages | `POST` | `/api/v1/conversations/{id:[0-9]+}/messages` | `MessageApiController::send()` |
| Messages | `POST` | `/api/v1/messages/{id:[0-9]+}/recall` | `MessageApiController::recall()` |
| Messages | `POST` | `/api/v1/conversations/{id:[0-9]+}/read` | `MessageApiController::markRead()` |
| Moderation | `GET` | `/banned` | `BannedController::show()` |
| Moderation | `GET` | `/api/v1/me/ban` | `BanApiController::status()` |
| News | `GET` | `/news` | `NewsController::index()` |
| News | `GET` | `/news/{id:[0-9]+}` | `NewsController::show()` |
| Notifications | `GET` | `/notifications` | `MessageController::inbox()` |
| Notifications | `GET` | `/api/v1/notifications` | `NotificationApiController::index()` |
| Notifications | `GET` | `/api/v1/notifications/unread-count` | `NotificationApiController::unreadCount()` |
| Notifications | `POST` | `/api/v1/notifications/read` | `NotificationApiController::markRead()` |
| Payment | `GET` | `/pay/{orderNo}` | `PaymentController::pay()` |
| Payment | `GET` | `/payment/result` | `PaymentController::result()` |
| Payment | `GET` | `/api/v1/payment/ezfp/notify` | `PaymentController::notify()` |
| Posts | `GET` | `/posts/{id:[0-9]+}` | `PostController::show()` |
| Posts | `GET` | `/posts/{id:[0-9]+}/edit` | `PostController::edit()` |
| Posts | `GET` | `/compose` | `PostController::compose()` |
| Posts | `GET` | `/topics/{slug}` | `PostController::topic()` |
| Posts | `GET` | `/api/v1/feed` | `PostApiController::feed()` |
| Posts | `POST` | `/api/v1/posts` | `PostApiController::store()` |
| Posts | `GET` | `/api/v1/posts/{id:[0-9]+}` | `PostApiController::show()` |
| Posts | `PATCH` | `/api/v1/posts/{id:[0-9]+}` | `PostApiController::update()` |
| Posts | `DELETE` | `/api/v1/posts/{id:[0-9]+}` | `PostApiController::destroy()` |
| Posts | `POST` | `/api/v1/posts/{id:[0-9]+}/repost` | `PostApiController::repost()` |
| Posts | `POST` | `/api/v1/posts/{id:[0-9]+}/purchase` | `PostApiController::purchase()` |
| Posts | `DELETE` | `/api/v1/posts/{id:[0-9]+}/repost` | `PostApiController::undoRepost()` |
| Reactions | `POST` | `/api/v1/reactions/toggle` | `ReactionApiController::toggle()` |
| Reports | `POST` | `/api/v1/reports` | `ReportApiController::store()` |
| Search | `GET` | `/search` | `SearchController::index()` |
| Search | `GET` | `/api/v1/search/suggest` | `SearchController::suggest()` |
| Settings | `GET` | `/settings` | `SettingsController::index()` |
| Settings | `GET` | `/settings/profile` | `SettingsController::profile()` |
| Settings | `POST` | `/settings/profile` | `SettingsController::saveProfile()` |
| Settings | `POST` | `/settings/avatar` | `SettingsController::uploadAvatar()` |
| Settings | `POST` | `/settings/cover` | `SettingsController::uploadCover()` |
| Settings | `POST` | `/settings/cover/remove` | `SettingsController::removeCover()` |
| Settings | `GET` | `/settings/security` | `SettingsController::security()` |
| Settings | `POST` | `/settings/security` | `SettingsController::changePassword()` |
| Settings | `POST` | `/settings/security/email` | `SettingsController::sendEmailChange()` |
| Settings | `POST` | `/settings/security/email/verify` | `SettingsController::verifyEmailChange()` |
| Settings | `POST` | `/settings/security/sessions/revoke-others` | `SettingsController::revokeOtherSessions()` |
| Settings | `POST` | `/settings/security/sessions/{id:[a-f0-9]{64}}/revoke` | `SettingsController::revokeSession()` |
| Settings | `GET` | `/settings/notifications` | `SettingsController::notifications()` |
| Settings | `POST` | `/settings/notifications` | `SettingsController::saveNotifications()` |
| Settings | `GET` | `/settings/privacy` | `SettingsController::privacy()` |
| Settings | `POST` | `/settings/privacy` | `SettingsController::savePrivacy()` |
| Settings | `GET` | `/settings/decorations` | `SettingsController::decorations()` |
| Settings | `POST` | `/settings/decorations` | `SettingsController::saveDecorations()` |
| Settings | `GET` | `/settings/background` | `SettingsController::background()` |
| Settings | `POST` | `/settings/background` | `SettingsController::saveBackground()` |
| Settings | `POST` | `/settings/background/upload` | `SettingsController::uploadBackground()` |
| Stream | `GET` | `/api/v1/stream` | `StreamController::stream()` |
| Tags | `GET` | `/tags` | `TagController::index()` |
| Tags | `GET` | `/tags/{slug}` | `TagController::show()` |
| Themes | `GET` | `/settings/appearance` | `ThemeController::index()` |
| Themes | `GET` | `/api/v1/themes` | `ThemeApiController::index()` |
| Themes | `POST` | `/api/v1/themes` | `ThemeApiController::save()` |
| Themes | `POST` | `/api/v1/themes/{id:[0-9]+}/activate` | `ThemeApiController::activate()` |
| Themes | `DELETE` | `/api/v1/themes/{id:[0-9]+}` | `ThemeApiController::destroy()` |
| Users | `GET` | `/me` | `UserController::me()` |
| Users | `GET` | `/u/{username:[a-z0-9_]+}` | `UserController::show()` |
| Users | `GET` | `/u/{username:[a-z0-9_]+}/posts` | `UserController::show()` |
| Users | `GET` | `/avatar/{hash:[0-9a-fA-F]{32,64}}` | `UserController::avatar()` |
| Users | `POST` | `/api/v1/blocks/toggle` | `BlockApiController::toggle()` |
| Users | `GET` | `/api/v1/users/search` | `UserController::search()` |
<!-- AUTO-GEN:routes:end -->

---

## 9. 前端：应用外壳、页面模块与加载动画

### 9.1 启动顺序（`src-client/main.ts`）

1. 导入 Material Symbols 子集字体与全局样式；
2. `bootstrap()`：`initTheme()`（应用 Light / Dark / System 外观）→ `setupGlobalActions()`（全局事件委托：点赞/收藏/关注/举报/灯箱/用户菜单等）→ `setupFormLoading()`（原生表单提交的按钮加载态）→ `setupNavDrawer()`（移动端抽屉导航）→ `setupSwup()`（平滑切页）；
3. Swup 每次完成切页（`page:view`）后：重新挂载当前页面的客户端模块，并刷新未读通知角标。

### 9.2 页面模块

页面模块按 `data-page` 标识懒加载，`init()` 返回清理函数（teardown），切页时自动调用。**自动生成**的映射表：

<!-- AUTO-GEN:frontend-modules:start -->
| 页面标识（`data-page`） | 客户端模块 |
| --- | --- |
| `home` | `src-client/modules/feed.ts` |
| `compose` | `src-client/modules/compose.ts` |
| `post` | `src-client/modules/post-page.ts` |
| `wall` | `src-client/modules/confessions.ts` |
| `hole` | `src-client/modules/confessions.ts` |
| `settings` | `src-client/modules/settings.ts` |
| `search` | `src-client/modules/search.ts` |
| `messages` | `src-client/modules/messages.ts` |
| `admin` | `src-client/modules/admin.ts` |
| `growth` | `src-client/modules/growth.ts` |
| `shop` | `src-client/modules/shop.ts` |
| `about` | `src-client/modules/about.ts` |
| `konbini` | `src-client/modules/konbini.ts` |
<!-- AUTO-GEN:frontend-modules:end -->

### 9.3 Swup 约定

- 仅替换 `#swup` 容器内的主内容；应用外壳（导航、主题、Toast、灯箱）持久存在；
- `@swup/head-plugin` 保持 head 资源不重复加载；`@swup/preload-plugin` 预取站内链接；`@swup/a11y-plugin` 处理焦点与无障碍；`@swup/scroll-plugin` 管理滚动位置；
- 页面切换加载指示器：`visit:start` 延迟 1000ms 显示顶部加载指示器（避免快速/预加载导航闪烁），`visit:end` / `visit:abort` / `visit:fail` 隐藏。

### 9.4 加载动画体系

| 场景 | 实现 | 说明 |
| --- | --- | --- |
| 按钮加载（表单提交、加载更多、关注、外观保存等） | `core/loading.ts` 的 `setButtonLoading()` + `.is-loading` | 按钮内嵌入 `.mofu-spinner`，文字/图标透明但保持尺寸 |
| 原生表单 | `setupFormLoading()`（document submit 监听） | 未被 JS 拦截的表单提交自动进入按钮加载态 |
| 页面切换 | `.page-loading`（`app.twig`）+ Swup 钩子 | 顶部居中指示器，延迟出现 |
| 静默防重（点赞/收藏的乐观 UI） | `.is-busy` | 仅禁指针，不显示动画 |

> 修改加载交互时请复用 `setButtonLoading()`，不要在模块内手写 `classList.add('is-loading')`。

---

## 10. 设计系统与主题定制

- **设计语言**：Apple iOS / iPadOS / macOS 取向的克制界面。设计令牌集中在 `src-client/styles/base.css`，共享组件覆盖层在 `src-client/styles/design-system.css`；页面 CSS 只负责局部布局，不得另起一套颜色/字体/圆角。
- **品牌色**：固定 `#FFCC38`（`--color-accent`），主按钮/选中态/焦点使用；黄色底上的文字统一使用深色 `#241D0B`。中文字面正文不能使用纯黄，使用高对比文字令牌 `--color-accent-text`。
- **外观**：Light / Dark / System 三种模式，通过 `<html data-appearance>` + `color-scheme` + `light-dark()` 令牌实现；System 模式自动响应操作系统。`/settings/appearance` 只保存外观模式，游客保存在 `localStorage`，登录用户持久化到数据库。
- **字体**：不下载、不托管 SF Pro / SF Mono。使用平台系统字体栈（`-apple-system, BlinkMacSystemFont, "SF Pro Text"...`），Windows / Android / Linux 依次回退到 Segoe UI / 系统无衬线 / 系统中文字体；代码使用 `ui-monospace` 栈。
- **字号阶梯**：`--font-size-caption-2` (11) / `caption` (12) / `footnote` (13) / `subheadline` (15) / `body` (16) / `title-3` (20) / `title-2` (24) / `title-1` (28) / `large-title` (34)，并保留 `--mofu-fs-*` 兼容别名。
- **形状与层级**：圆角 6 / 8 / 12 / 16 / 20 / 24 / pill；阴影三档 `--shadow-sm/md/lg`，普通卡片默认无阴影，仅浮层使用 `md/lg`。
- **图标**：Material Symbols 连字图标（`<span class="material-symbols-outlined">home</span>`），统一 20px、400 字重，激活态用 `font-variation-settings: 'FILL' 1`。
- **组件合同**：
  - 按钮族：`.filled-btn`（品牌黄 + 深色文字）、`.tonal-btn`（中性表面）、`.outlined-btn`、`.text-btn`、`.icon-btn`，统一 40/32px 高度与 10px 圆角；
  - 导航：桌面 macOS 式侧栏，移动端 iOS 式全宽底部标签栏（非悬浮胶囊）；当前项使用浅黄容器；
  - 列表：首页动态、表白墙、通知、会话、板块帖子采用连续分组列表 + 细分隔线，不把每条内容都做成悬浮卡片；
  - 设置：`.settings-row` 分组列表、`.switch` 51×31 开关、`[data-tooltip]`、`[data-counter]`、通用对话框 `openDialog()` / `confirmDialog()`；
  - 后台表格：桌面为表格，**≤ 1023px 自动转为信息卡**（字段名由单元格 `data-label` 生成，模板只维护一份表格结构）；
  - 键盘可达性：全站 `:focus-visible` 焦点环，Tabs 组 ←/→/Home/End 漫游，对话框/用户菜单/导航抽屉的焦点圈与还原。

---

## 11. 构建流程与产物

`bash bin/fe-build.sh` 是统一构建入口，依次执行：

1. 同步源码到构建目录（`/root/mofu-build`，见[快速开始的说明](#3-快速开始)）并在缺依赖时安装；
2. `vite build` 输出到 `public/assets`（`emptyOutDir`）；
3. 打包 Service Worker：esbuild 将 `src-client/sw.ts` 输出为 `public/sw.js`，并注入构建版本号（缓存名随构建自动更新）；
4. 自动刷新本教程的**自动区段**（`scripts/gen-docs.mjs`）。

> `vite.config.ts` 中构建 `base` 为 `/assets/`，与 PHP 侧 `ViteAssets`（硬编码 `/assets/` 前缀）对应；若改动发布路径需同步两处，否则会出现字体/样式 404。

> **PWA 与离线**：缓存策略集中在 `src-client/pwa/rules.ts`（纯函数、有 Vitest 单测），SW 入口为 `src-client/sw.ts`；预缓存离线页 `public/offline.html`，导航请求 network-first 断网回退；`/assets/*` cache-first，`/uploads/*` cache-first + FIFO 上限。图标由 `php scripts/gen-pwa-icons.php` 生成到 `public/icons/`；manifest 为 `public/manifest.webmanifest`（Apache 拒绝 `.json` 后缀，故不使用 .json；`.htaccess` 已补 MIME 与 sw.js `no-cache`）。

**自动生成**的最近一次构建产物：

<!-- AUTO-GEN:build:start -->
最近构建时间：2026/10/21 23:46:57

| 文件 | 说明 | 大小 |
| --- | --- | --- |
| `main-B43tn5Am.js` | 应用入口 JS（含 Swup 与全站交互） | 200.7 KB |
| `main-KlWqyPvU.css` | 全站样式（设计令牌 / 布局 / 组件） | 236.6 KB |
| `manifest.json` | 构建清单（`ViteAssets` 读取） | 3.8 KB |
| `material-symbols-outlined-Bgl3Icaq.woff2` | 图标字体：Material Symbols | 3.7 MB |
| `about-D163O3bi.js` | 页面模块懒加载 chunk | 2.9 KB |
| `admin-DrP2c4bB.js` | 页面模块懒加载 chunk | 4.0 KB |
| `compose-ClPf7cj7.js` | 页面模块懒加载 chunk | 9.9 KB |
| `confessions-Dn01JNTs.js` | 页面模块懒加载 chunk | 8.9 KB |
| `feed-BwU-GpNJ.js` | 页面模块懒加载 chunk | 11.9 KB |
| `growth-CRt778tO.js` | 页面模块懒加载 chunk | 4.6 KB |
| `konbini-BDNS5Qhw.js` | 页面模块懒加载 chunk | 507.1 KB |
| `messages-dr7aAg8L.js` | 页面模块懒加载 chunk | 12.0 KB |
| `post-page-QLk_6edu.js` | 页面模块懒加载 chunk | 1.9 KB |
| `search-B3OolmOX.js` | 页面模块懒加载 chunk | 3.3 KB |
| `settings-DXJdIObc.js` | 页面模块懒加载 chunk | 1.3 KB |
| `shop-yu3ySRkQ.js` | 页面模块懒加载 chunk | 0.8 KB |
| `theme-center-Cd4jkGgG.js` | 页面模块懒加载 chunk | 2.8 KB |
<!-- AUTO-GEN:build:end -->

---

## 12. 测试

```bash
composer test    # 后端：PHPUnit（tests/Unit + tests/Feature）
npm test         # 前端：Vitest（tests/frontend）
```

- `tests/Unit`：纯单元测试（文本处理、主题变量、迁移解析等）；
- `tests/Feature/HttpFlowTest`：通过完整中间件链验证核心用户旅程（**需要可用的数据库**，测试数据随机生成并清理）；
- 修改前端后建议先跑 `tsc --noEmit`（在构建目录）确认类型无回归。

---

## 13. 开发指南（常见任务）

**新增一个业务模块**

1. 创建 `app/Modules/<Name>/`：控制器（页面控制器与/或 `*ApiController`）、Service/Repository、`routes.php`（返回 `callable(SlimApp, Container)`）；
2. 在 `app/Bootstrap/Services.php` 的 `AUTOWIRED` 列表登记服务与控制器（无标量参数的类会自动装配）；
3. 视图放 `app/Views/pages/`，需要时在 `layouts/app.twig` 增加导航入口。

**新增一个页面（含客户端交互）**

1. 路由 + 控制器返回 `$this->page->render($request, 'pages/xxx.twig', [...])`；
2. Twig 首行 `{% block page_id %}xxx{% endblock %}` —— 它就是前端加载模块的 `data-page` 标识；
3. 在 `src-client/modules/` 新建模块（`init()` 返回 teardown），并登记到 `src-client/main.ts` 的 `pages` 映射；
4. 样式按需加入 `src-client/styles/pages.css`，遵循既定令牌与命名（BEM 风格）。

**新增 API**

在模块 `routes.php` 的 `/api/v1` group 内注册；控制器继承 `Core\Http\ApiController`，用 `Guard::requireLogin()` / `Guard::require()` 校验身份与权限，`RateLimiter` 按需限流。

**代码约定**

- 后端：`declare(strict_types=1)`；命名空间 `Mofu\` 对应 `app/`（PSR-4）；SQL 占位符不复用同名参数（见 7.4）；
- 前端：TypeScript strict；不引入前端框架，保持原生 TS + Swup；模块只做本页职责，全局行为放 `core/global-actions.ts`；
- 修改 `src-client/` 或 Twig 后需要重新构建（或启用 `VITE_DEV_SERVER` 开发模式）。

---

## 14. 部署指南

全新部署有两条路径：

**方式 A：Web 安装向导（推荐，适合虚拟主机）** —— 上传代码（含 `public/assets` 构建产物与 `vendor/`）后直接访问站点域名，会自动进入 `/install`：环境自检 → 数据库与站点配置 → 初始化（执行迁移 + 创建超级管理员）→ 完成并写入 `storage/install.lock` 锁定向导。全程无需命令行；完成后再次访问 `/install` 返回 404。

**方式 B：CLI 流程（自有服务器 / CI）** —— 按下文步骤 1-9。

1. **Web 根指向 `public/`**：Nginx `try_files $uri /index.php?$query_string;`；Apache 启用 rewrite 到 `index.php`；
2. **生产配置**：`.env` 设 `APP_ENV=production`、`APP_DEBUG=false`、数据库连接、`APP_URL`；
3. **构建**：`composer install --no-dev --optimize-autoloader`、`npm ci && npm run build`（或对应环境的 fe-build 流程）；
4. **迁移**：`php bin/migrate.php migrate`；
5. **权限**：`storage/`、`public/uploads/` 可写；其余目录只读更佳；
6. **缓存**：`public/assets/` 内文件名带内容哈希，可放心设置长缓存（如 `Cache-Control: immutable`）；HTML 不缓存；
7. **后台任务**：暂无队列；通知与审计均同步写入，若迁移到大队列场景再引入；
8. **上传限制**：视频直传需同步调整 PHP 的 `upload_max_filesize` / `post_max_size` 与 `UPLOAD_VIDEO_MAX_MB`；
9. **实时通知**：SSE 需 PHP-FPM / Apache 允许长连接（单请求最长 25 秒），Nginx 反代已带 `X-Accel-Buffering: no` 头；若环境不允许长连接，前端会自动降级为轮询。

### 14.1 备份与恢复

备份：`php bin/backup.php`（可加 `--out=DIR`、`--skip-uploads`、`--keep=N`）。

产出（默认 `storage/backups/<时间戳>/`）：`database.sql`（纯 PHP 导出，不依赖 mysqldump）、`uploads.zip`（上传的图片与视频）、`manifest.json`（元信息）。工具会自动清理同级目录中带 `manifest.json` 的旧备份（保留最近 `--keep` 份，默认 5）。

恢复：

```bash
mysql -u <用户> -p <库名> < storage/backups/<时间戳>/database.sql
# 将 uploads.zip 解压内容放回 public/uploads/
```

---

## 15. 常见问题 FAQ

**Q：图标全部显示成文字（如 "menu"、"home"）？**
Material Symbols 字体未加载。检查构建产物 CSS 中 `@font-face` 的 `url()` 是否为 `/assets/` 前缀（历史 bug 根因：vite `base` 配置与文件实际路径不一致导致字体 404），并确认浏览器网络面板中 `material-symbols-outlined-*.woff2` 返回 200。

**Q：页面样式丢失 / JS 404？**
`public/assets/manifest.json` 缺失或过期。重新运行 `bash bin/fe-build.sh`（或 `npm run build`）。

**Q：数据库连接失败？**
确认 MariaDB/MySQL 已启动、`.env` 连接信息正确，然后 `php bin/migrate.php status` 检查连通与迁移状态。

**Q：未登录点开发布 / 通知 / 设置等页面会怎样？**
会 302 跳转到登录页并带上回跳地址（如 `/login?redirect=%2Fcompose`），登录成功后自动回到原来要去的页面；接口（`/api/*`）未登录仍返回 401 JSON，不会跳转。权限不足的页返回 403，并给出「返回后台 / 返回首页」入口（错误页会自动识别当前登录态与主题）。

**Q：后台导航为什么少了几个菜单？**
后台导航按当前账号的权限点渲染：例如内容审核员（moderator）只看到仪表盘、动态管理、评论管理、表白墙审核、举报处理（以及圈子/板块/活动管理），不会出现点进去必 403 的菜单；用户管理 / 申诉管理需 `user.manage`，资讯管理需 `news.manage`。

**Q：访问站点自动跳转到 /install 安装向导？**
说明站点被判定为「未完成安装」：没有 `.env`（全新部署，按向导完成即可），或数据库可连接但尚无迁移记录（半装状态，向导会提示继续）。若站点已在运行且只是缺少锁文件，会在首次请求时自动补写 `storage/install.lock`；数据库临时连不上时门禁不会劫持站点（走普通错误页），可手动访问 `/install` 诊断。

**Q：安装时报 `SQLSTATE[42S01] … Table 'users' already exists`？**
目标数据库不是空库：里面已有 `users` 等 MOFU 数据表（或其他程序的表），但没有 MOFU 的迁移记录表 `schema_migrations`，于是迁移从 001 建表时冲突。向导现在会直接识别这种情况并停止安装（不再抛原始 SQL 错误），按提示处理：
1. 残留测试库 / 误选的库 → 在主机面板删除该库并重建空库（或清空其中所有表），回到向导第 2 步确认库名后重试；
2. 库中数据重要且原站曾正常运行 → 先备份，从备份恢复 `schema_migrations` 表后再续装；
3. 该库属于其他程序（例如库中是一批带前缀的表）→ 改用主机面板新建的专用空数据库；另外删除本次安装尝试留下的空 `schema_migrations` 表（向导会在提示页里标注）。

**Q：安装过程中可以关闭浏览器吗？**
不建议。向导已对安装步骤启用 `ignore_user_abort`（关闭页面不会中断迁移），但仍请等到“安装完成”页再关闭，避免中途网络异常造成半装（半装可重新进入向导致续装）。

**Q：装好后首页 500，日志里是 `Unknown column 'p.community_id'`（或类似的缺表/缺字段）？**
说明安装没有跑完（迁移中断），站点却被当成已安装锁定了。处理：删除 `storage/install.lock`，再访问首页会回到安装向导，第 3 步「完成安装」会补跑剩余迁移并创建管理员。若向导提示数据库不可直接安装，则改在 phpMyAdmin 中清空该库所有表后再重装。现有门禁已改为「全部迁移都已应用」才算已安装，不会再出现半装被锁死。

**Q：登录 / 发帖时报 403「安全校验失败，请刷新页面后重试。」？**
这是 CSRF 校验未通过，常见原因：
1. **用纯 HTTP 访问而 APP_ENV=production**：旧版本会话 cookie 带 `Secure`，浏览器会直接丢弃 → 每次请求都是新会话、令牌永远对不上。解决：改用 `https://` 访问（主机一般有免费 SSL），或更新到已修复版本（cookie 的 Secure 现按实际请求协议判断）；
2. 主机 / CDN 缓存了 HTML 页面（页面内嵌的令牌属于别的会话）：新版页面已统一发送 `Cache-Control: no-store, private`，并建议在主机面板关闭对动态页面的缓存；
3. 浏览器禁用了 cookie，或页面放置过久会话已过期：刷新页面重新登录。

**Q：安装完成后想重新运行向导 / 访问 /install 提示 404？**
`/install` 只对未完成安装的站点开放（完成时会写入 `storage/install.lock`）。需要全新重装时：清空数据库、删除根目录 `.env` 与 `storage/install.lock` 后再访问 `/install`；仅想看环境自检时，删除锁文件后访问即可（向导检测到站点已在运行会进入只读模式，不会覆盖数据）。

**Q：上传图片失败？**
检查 `UPLOAD_MAX_MB` 与 PHP 的 `upload_max_filesize` / `post_max_size` 是否匹配，`public/uploads/` 是否可写，以及服务器是否安装 `gd`（生成缩略图）。视频直传不转码、不抽帧，仅校验 MIME 与大小，上限见 `UPLOAD_VIDEO_MAX_MB`；若视频上传失败，优先检查 PHP 的两个上传上限是否 ≥ `UPLOAD_VIDEO_MAX_MB` + 表单冗余。

**Q：登录/提交表单报错？**
多为 CSRF 校验失败：确认表单带 `_csrf`（模板已内置），且会话 cookie（`SESSION_COOKIE`）未被浏览器策略拦截。

**Q：改了前端代码但页面没变化？**
构建产物未更新。重新构建，或在 `.env` 配置 `VITE_DEV_SERVER` 使用开发服务器（HMR）。

**Q：如何修改密码 / 管理登录设备？**
登录后访问 `/settings/security`：可修改密码（需验证当前密码，可选同时退出其他所有设备），并可查看与逐一撤销其他登录设备。

**Q：搜索范围包含哪些内容？**
`/search` 覆盖公开动态、用户、话题与已通过审核的表白墙 / 树洞内容，并在服务端做权限过滤（私密动态、未过审投稿、封禁账号不会出现）；支持按类型筛选、热门话题入口与本地搜索历史（存于浏览器 localStorage）。

**Q：私信是实时的吗？**
混合模式：未读角标与私信新消息通过 SSE（`GET /api/v1/stream`）在线即时推送（SSE 不可用或连续失败时自动降级为 30 秒轮询兜底）；消息页仍保留 8 秒轮询兜底，SSE 在线时收到事件会立即拉取新消息。请勿宣传为「毫秒级实时聊天」。

**Q：拉黑后会怎样？**
拉黑后双方不能互相关注或互发私信（含已有会话），现有双向关注关系会自动解除；解除拉黑不会恢复原关注关系。拉黑名单在 `/settings/privacy` 管理。

**Q：账号被封禁 / 停用后还能申诉吗？**
可以。登录被拒时会给出申诉入口（或直接访问 `/appeal`），使用「用户名 + 密码」验证身份后提交理由；管理员在后台「申诉管理」处理，通过后账号立即恢复并收到系统通知。注意：封禁对既有登录会话**即时生效**。

**Q：免打扰时段如何工作？**
在 `/settings/notifications` 设置启停与起止时间（支持跨天，如 23:00 → 07:00；起止相同视为不启用）。时段内**点赞 / 评论 / 关注**三类通知不再送达（直接不产生，非延迟发送）；审核结果、举报处理与系统通知不受影响。

---

## 16. 文档自动化说明

本教程中的「自动区段」由标记界定：

```html
<!-- AUTO-GEN:<区段名>:start -->
（由生成器写入）
<!-- AUTO-GEN:<区段名>:end -->
```

- 生成器：`scripts/gen-docs.mjs`（纯 Node，无第三方依赖）；
- 区段：`env`（环境变量）、`tables`（数据表）、`routes`（路由）、`frontend-modules`（前端模块）、`tree`（目录树）、`build`（构建产物）；
- 触发方式：
  - 每次构建后自动刷新（`bin/fe-build.sh` 末尾调用）；
  - 手动刷新：`npm run docs`（或 `node scripts/gen-docs.mjs`）。
- 注意：**不要直接编辑自动区段内的内容**——下次构建会被覆盖；应修改数据源（`.env.example`、路由文件、迁移、源码）或调整生成器。

