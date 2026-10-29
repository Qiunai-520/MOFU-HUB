<h1 align="center">MOFU社区</h1>

> 采用 Apple 风格设计系统的现代化校园社区平台

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2.35%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.2.35+">
  <img src="https://img.shields.io/badge/MySQL%20%2F%20MariaDB-8.0%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL / MariaDB">
  <img src="https://img.shields.io/badge/TypeScript-5.6-3178C6?logo=typescript&logoColor=white" alt="TypeScript">
  <img src="https://img.shields.io/badge/Vite-8-646CFF?logo=vite&logoColor=white" alt="Vite 8">
  <img src="https://img.shields.io/badge/Apple%20Design-%23FFCC38-FFCC38" alt="Apple-inspired design system">
  <img src="https://img.shields.io/badge/License-Proprietary-lightgrey" alt="License: Proprietary">
</p>

<p align="center">
  <b>中文</b> · <a href="docs/i18n/README.en.md">English</a>
</p>

---

MOFU社区是一套面向高校场景的综合性社区平台：校园动态、匿名表白墙与树洞、关注与通知、个性化外观、内容审核与管理后台。后端采用 PHP + Slim 的 SSR 架构，前端以 TypeScript + Swup 实现应用外壳与平滑切页。全站使用一套 Apple 风格设计系统：系统字体栈、系统中性色、固定品牌强调色 `#FFCC38`、克制的阴影与圆角，以及 Light / Dark / System 三种外观。

## 目录

- [功能特性](#功能特性)
- [技术栈](#技术栈)
- [快速开始](#快速开始)
- [常用命令](#常用命令)
- [环境与构建注意事项](#环境与构建注意事项)
- [目录结构](#目录结构)
  - [顶层结构](#顶层结构)
  - [app/ — 后端应用](#app--后端应用)
  - [src-client/ — 前端源码](#src-client--前端源码)
  - [public/ — Web 根与静态资源](#public--web-根与静态资源)
  - [bin/ — 命令行工具](#bin--命令行工具)
  - [migrations/ — 数据库迁移](#migrations--数据库迁移)
  - [scripts/ — 工程脚本](#scripts--工程脚本)
  - [tests/ — 测试](#tests--测试)
  - [docs/ — 文档](#docs--文档)
  - [music-app/ — 独立音乐播放器](#music-app--独立音乐播放器)
  - [根目录文件](#根目录文件)
- [环境变量](#环境变量)
- [数据库与迁移](#数据库与迁移)
- [文档](#文档)
- [路线图](#路线图)
- [贡献指南](#贡献指南)
- [致谢](#致谢)
- [许可证](#许可证)

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
- **外观中心** — Light / Dark / System 三种外观、多套外观保存与跨设备同步；游客可保存到本机。品牌强调色固定为 `#FFCC38`，不随外观变化
- **Apple 风格体验** — Swup 无刷新切页、系统字体栈、iOS 式移动端标签栏、macOS 式桌面侧栏、统一的分组列表 / 表单 / 弹窗与 Material Symbols 图标
- **3D 街角场景** — `/konbini` 使用 Three.js 程序化搭建雨夜便利店、室内货架、自动门、售卖机、街灯、电线与湿地反光，支持拖动环视、缩放和店灯切换；代码按页面懒加载
- **管理后台** — 仪表盘、用户（状态与角色）、动态、评论、表白墙审核、举报处理、申诉管理、圈子管理、板块管理、活动管理、资讯管理
- **安全** — 数据库会话、全站 CSRF、基于权限点的访问控制、接口限流、审计日志、上传校验

## 技术栈

| 层 | 技术 |
| --- | --- |
| 后端 | PHP ≥ 8.2.35 · Slim 4 · Twig 3 · vlucas/phpdotenv |
| 数据库 | MySQL / MariaDB（utf8mb4）· 项目内置迁移器 |
| 前端 | TypeScript（strict）· Vite 8 · Swup 4 · Three.js · nanostores |
| 设计系统 | Apple-inspired tokens · 系统字体栈 · Light / Dark / System · Material Symbols |
| 测试 | PHPUnit 10 · Vitest 5 |

## 快速开始

> 需要：PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 或对应分支的更高版本（`pdo_mysql`、`mbstring`、`json`、`fileinfo`、`openssl`、`gd`）、Composer、Node.js ≥ 20、MySQL / MariaDB。PHP 8.1 及更早版本不再支持。

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

### Windows 本地一键预览

已安装 Docker Desktop 时，直接运行：

```powershell
powershell -ExecutionPolicy Bypass -File .\bin\preview.ps1 -Seed
```

脚本会：

- 首次运行时下载官方 **PHP 8.2.35**（PHP2Shell 修复版）到 `.runtime/`，不会提交到 Git
- 如果 `.env` 对应端口没有数据库，自动启动本地 MariaDB 容器
- 自动执行迁移
- `-Seed` 在**空本地库**写入演示数据
- 启用 OPcache，并以 8 个 PHP worker + 静态资源代理启动 `http://127.0.0.1:8000`

演示账号（仅本地、仅 `-Seed` 时创建）：

```text
邮箱：preview@example.com
密码：MofuPreview123!
```

按 `Ctrl+C` 停止预览服务器；数据库容器会保留。下次预览仍运行同一命令。若 8000 端口被占用，可使用 `-Port 8080`。

> `.runtime/` 只用于 Windows 本地预览，不是生产运行时。生产必须按 [PHP2Shell 处置说明](docs/security/php2shell.md) 在服务器上升级 PHP。

## 常用命令

| 命令 | 说明 |
| --- | --- |
| `composer serve` | 启动 PHP 开发服务器（`127.0.0.1:8000`） |
| `bash bin/dev-start.sh` | 启动 MariaDB + 开发服务器（本地一体启动） |
| `php bin/migrate.php migrate \| status \| rollback` | 数据库迁移 / 状态 / 回退 |
| `php bin/make-admin.php <用户名>` | 把已有账号提升为超级管理员 |
| `php bin/backup.php` | 备份数据库与上传文件（默认 `storage/backups/`，支持 `--skip-uploads` / `--keep=N`） |
| `npm run build` | 构建前端（Vite） |
| `bash bin/fe-build.sh` | 完整构建：前端 + Service Worker + 文档刷新 |
| `npm run dev` | Vite 开发服务器（HMR，配合 `.env` 的 `VITE_DEV_SERVER`） |
| `npm run docs` | 刷新开发者教程的自动区段 |
| `npm run typecheck` | TypeScript 严格类型检查 |
| `php scripts/gen-pwa-icons.php` | 重新生成 PWA 图标与 favicon |
| `composer test` | 后端测试（PHPUnit） |
| `composer security` | 部署安全门禁：PHP 版本、扩展、调试开关、APP_KEY、上传目录等 |
| `composer audit --locked` | 检查 Composer 依赖安全公告（需要访问 Packagist） |
| `npm test` | 前端测试（Vitest） |

## 环境与构建注意事项

以下几点在受限环境（容器、无 root、虚拟主机）里最容易卡住，单独记在这里。

### PHP 扩展

除快速开始列出的扩展外，`gd` 不能省：活动封面、资讯封面、视频封面都走 GD 压缩管线，缺它相关测试会直接 `Call to undefined function imagecreatetruecolor()`。

### 安全检查与 PHP CVE

- **PHP2Shell（CVE-2026-109618 / CVE-2026-109619）**：影响未打补丁的 PHP，CVSS v4.0 9.2，可导致未认证远程代码执行。修复版本为 PHP **8.2.35 / 8.3.36 / 8.4.27 / 8.5.12**。
- 项目最低 PHP 版本已提升到 **8.2.35**，Composer 平台版本也固定为 8.2.35。PHP 8.3.35、8.4.26、8.5.11 仍然受影响，不能只比较 `8.2.35` 这个数字。
- **PHP 核心 CVE 不能通过修改本仓库代码修复**：必须在服务器上升级 PHP 到对应分支的修复版本，并重启所有 PHP 工作进程。应用代码无法替代运行时升级。
- 不能立即升级时的临时缓解、服务器配置和入侵排查步骤见 [PHP2Shell 处置说明](docs/security/php2shell.md)。临时缓解不等同于修复。
- 部署前运行：
  ```bash
  composer security          # PHP 版本 / 扩展 / 调试开关 / APP_KEY / 上传目录
  composer audit --locked    # Composer 依赖安全公告
  ```
- 生产环境必须设置 `APP_ENV=production`、`APP_DEBUG=false`、随机 `APP_KEY`、HTTPS，并关闭 `expose_php`。
- 上传目录必须禁止脚本执行；项目自带 `public/uploads/.htaccess`，Nginx 部署需自行添加等价规则。
- 漏洞报告与 CVE 处理流程见 [SECURITY.md](SECURITY.md)。

### 受限环境里启动 MariaDB

无 root / 受限环境（如 PRoot）下，`root@localhost` 走 `unix_socket` 认证会报 `ERROR 1698 Access denied`，而 `--skip-grant-tables` 又不允许 `CREATE USER` / `GRANT`（`ERROR 1290`）。把建库建号语句交给 `--init-file`（以特权身份执行，可 `GRANT`）：

```bash
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
cat > /tmp/mofu-init.sql <<'SQL'
CREATE DATABASE IF NOT EXISTS mofu DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'mofu'@'%' IDENTIFIED BY '你的密码';
GRANT ALL PRIVILEGES ON mofu.* TO 'mofu'@'%';
FLUSH PRIVILEGES;
SQL
mariadbd --user=mysql --bind-address=127.0.0.1 --port=3306 --init-file=/tmp/mofu-init.sql
```

停服务用 `kill "$(cat /run/mysqld/mysqld.pid)"`：终端里 `Ctrl+C` 往往只结束前台进程，服务仍在跑，重新启动时会报 `Can't lock aria control file`。

### 测试数据库

注册接口只授予普通用户角色；管理员由安装向导或 `bin/make-admin.php` 显式创建。测试不再依赖“首个注册用户自动成为超管”，可直接在完成迁移的空测试库上运行。

### 前端构建（`bash bin/fe-build.sh`）

脚本串起 Vite 产物 → Service Worker → 文档刷新。一个坑：

- 脚本用 `node node_modules/esbuild/bin/esbuild …` 调用 esbuild。若该文件是平台二进制（ELF）而非 JS 启动器（用 `npm install --ignore-scripts --no-bin-links` 装的依赖会这样），这一步报 `SyntaxError: Invalid or unexpected token`。改用平台包里的可执行文件即可：
  ```bash
  ./node_modules/@esbuild/linux-arm64/bin/esbuild src-client/sw.ts --bundle --minify \
    --format=iife --target=es2022 --define:__SW_VERSION__="'$(date +%s)'" --outfile=public/sw.js
  ```

### 打包发布包

口径：解压后站点根指向 `mofu/public/`；纳入 `app/`、`bin/`、`vendor/`、`migrations/`、`public/`（含 `public/assets` 构建产物与 `public/uploads` 上传文件）、`composer.json`、`composer.lock`、`.env.example`、`README.md` 以及空的 `storage/{backups,cache,logs,sessions}`；不纳入 `.env`、`.git`、`node_modules`、`src-client`、`tests`、`docs`。

容器内通常没有 `zip`，用 Python 的 `zipfile`（`ZipFile.write` 会保留权限位）：

```python
import os, time, zipfile

ROOT = os.getcwd()
DIRS = ['app', 'bin', 'vendor', 'migrations', 'public']
FILES = ['composer.json', 'composer.lock', '.env.example', 'README.md']
STORAGE = ['storage', 'storage/backups', 'storage/cache', 'storage/logs', 'storage/sessions']

def dir_entry(name):
    info = zipfile.ZipInfo(name, time.localtime()[:6])
    info.external_attr = (0o40755 << 16) | 0x10
    info.compress_type = zipfile.ZIP_STORED
    return info

with zipfile.ZipFile(os.path.join(ROOT, 'mofu-deploy.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    z.writestr(dir_entry('mofu/'), b'')
    for d in STORAGE:
        z.writestr(dir_entry('mofu/' + d + '/'), b'')
    for top in DIRS:
        for dp, dn, fn in os.walk(os.path.join(ROOT, top)):
            dn.sort()
            for f in sorted(fn):
                p = os.path.join(dp, f)
                z.write(p, 'mofu/' + os.path.relpath(p, ROOT))
    for f in FILES:
        z.write(os.path.join(ROOT, f), 'mofu/' + f)
```

两点收尾：

- 跑过 PHPUnit 后 `public/uploads/` 会多出测试生成的图片/视频（数据库已无引用）。打包前先挑出来确认无引用后删除，免得把测试产物发到线上：`find public/uploads -type f -newermt '<开始测试的时间>'`（判时间用 `find`，PHP 默认时区是 UTC 而 shell 往往是 CST，用 `strtotime` 会错 8 小时）。
- 更新到已有实例时先跑迁移；新增迁移可能被首页等页面直接依赖（例如 `028_flash_level` 加了 `users.is_flash`，信息流查询会用到）。

## 目录结构

### 顶层结构

```text
mofu-community/
├── app/            PHP 应用（Bootstrap / Core / Install / Modules / Views）
├── bin/            命令行工具（迁移、备份、构建、开发启动）
├── docs/           文档（开发者教程 + 双语 README）
├── font/           字体源文件（当前 Web 端使用系统字体栈，不再打包）
├── migrations/     数据库迁移（47 个 .sql）
├── public/         Web 根目录（单入口 + 构建产物 + 静态资源 + 上传）
├── scripts/        工程脚本（主题、图标、文档、字体子集）
├── src-client/     前端 TypeScript 源码
├── storage/        运行时数据（日志 / 缓存 / 备份 / 安装锁）
├── tests/          测试（PHPUnit Feature/Unit + Vitest 前端）
├── music-app/      独立的音乐播放器（部署于 Cloudflare Pages/Workers）
├── composer.json   PHP 依赖与脚本
├── package.json    前端依赖与脚本
├── vite.config.ts  Vite / Vitest 配置
├── phpunit.xml     PHPUnit 配置
├── tsconfig.json   TypeScript 配置
├── .env.example    环境变量模板
└── logo.png        品牌 Logo（图标的唯一事实来源）
```

### app/ — 后端应用

PSR-4 命名空间 `Mofu\`，根命名空间直接映射到 `app/`。

#### app/Bootstrap/ — 应用装配

```text
Bootstrap/
├── App.php            应用内核：构建 Slim 应用、挂载中间件与模块路由
├── Config.php         应用配置：.env + 默认值，支持 "a.b.c" 点路径读取
├── Container.php      极简服务容器：注册惰性工厂，按需实例化并缓存单例
├── ErrorHandler.php   统一错误处理：API 请求返回 JSON，页面请求返回 HTML 错误页
└── Services.php       业务模块服务注册中心（带标量参数的叶子服务在此显式注册）
```

#### app/Core/ — 核心基础设施

```text
Core/
├── Database/
│   ├── Connection.php  PDO 连接工厂
│   ├── Migrator.php    轻量迁移器（.sql 按文件名升序执行，支持 -- @@DOWN 回退段）
│   └── Query.php       PDO 薄封装：参数化查询、便捷 CRUD 与分页
├── Http/
│   ├── ApiController.php   API 控制器基类：当前用户与 JSON 响应便捷方法
│   ├── Ctx.php             请求上下文便捷访问器
│   ├── HttpException.php   携带 HTTP 状态码的业务异常
│   ├── Json.php            统一 JSON 响应格式（成功/失败结构）
│   └── Middleware/
│       ├── CsrfMiddleware.php     对变更类请求校验 CSRF token
│       └── SessionMiddleware.php  解析/创建访客会话并注入 SessionState
├── Security/
│   ├── Auth.php           认证服务：凭证校验与会话生命周期
│   ├── Csrf.php           CSRF token 生成与校验（token 存服务端会话）
│   ├── Guard.php          权限判定（基于用户 permissions 键）
│   ├── RateLimiter.php    基于文件的轻量限流（滑动窗口近似，无需 Redis）
│   ├── SessionManager.php 数据库会话：创建、解析、轮换与销毁
│   └── SessionState.php   请求生命周期内的会话状态
├── Support/
│   ├── AliasGenerator.php 匿名化名生成器（表白墙/树洞）
│   ├── AuditLogger.php    操作审计日志
│   ├── Logger.php         文件日志（上下文以 JSON 附加，按行追加）
│   ├── Text.php           文本辅助（相对时间、摘要等）
│   └── Backup/
│       └── DbDumper.php   纯 PHP 数据库导出（SHOW CREATE TABLE + 分批 INSERT）
└── View/
    ├── Page.php         页面渲染助手：组装公共上下文 + 渲染响应
    ├── Present.php      通用展示辅助
    ├── TwigRenderer.php Twig 渲染（模板自动转义）
    ├── ViewContext.php  页面公共上下文的组装
    └── ViteAssets.php   Vite 资源桥接（dev server 模块脚本 / 生产 manifest）
```

#### app/Install/ — Web 安装向导

```text
Install/
├── EnvChecker.php    安装向导的环境自检（PHP 版本、扩展、目录可写等）
├── EnvFileWriter.php .env 生成器（键白名单重写、双引号转义、原子替换 + 备份）
├── InstallGate.php   安装门禁：index.php 构建 Slim 前调用，决定放行/重定向/自愈加锁
└── Installer.php     安装主流程：环境自检 → 数据库配置 → 迁移与管理员 → 完成加锁
```

#### app/Modules/ — 业务模块

每个模块目录包含控制器、服务与独立的 `routes.php`（由 `Bootstrap/App.php` 统一挂载）。

| 模块 | 职责 | 主要文件 |
| --- | --- | --- |
| `Admin/` | 管理后台：仪表盘、用户、动态、评论、表白墙审核、举报与申诉处理、圈子/板块/活动/资讯管理、团队名单与首页轮播维护、评论导出 Twikoo | `AdminController.php` `routes.php` |
| `Appeals/` | 封禁/停用账号申诉：用户验证身份提交，管理员通过/驳回，通过后恢复账号并通知 | `AppealController.php` `AppealService.php` `routes.php` |
| `Auth/` | 注册、登录、登出与会话建立 | `AuthController.php` `routes.php` |
| `Boards/` | 统一板块框架：论坛/问答/互助/学习/失物/二手，通过 `kind` 与 `extra(JSON)` 差异化 | `BoardController.php` `BoardService.php` `routes.php` |
| `Comments/` | 站内评论：列表、发布、删除与导出（→ Twikoo 导入格式） | `CommentApiController.php` `CommentController.php` `CommentService.php` `CommentRepository.php` `CommentPresenter.php` `CommentExporter.php` `routes.php` |
| `Communities/` | 兴趣圈子：创建、加入（公开/审批）、退出、成员管理与可见性判定 | `CommunityController.php` `CommunityApiController.php` `CommunityService.php` `routes.php` |
| `Confessions/` | 表白墙与树洞：匿名/署名投稿、审核、匿名评论（含身份隔离与化名） | `ConfessionController.php` `ConfessionApiController.php` `ConfessionService.php` `ConfessionRepository.php` `ConfessionPresenter.php` `routes.php` |
| `Events/` | 校园活动：发布、报名（直接/审核制）、名单管理与取消 | `EventController.php` `EventService.php` `routes.php` |
| `Follows/` | 关注/取关 | `FollowApiController.php` `FollowService.php` `routes.php` |
| `History/` | 浏览记录：记录去重、回看与清空、可见性过滤 | `HistoryController.php` `HistoryService.php` `routes.php` |
| `Home/` | 首页、关于页、`robots.txt` / `sitemap.xml`、音乐页 | `HomeController.php` `routes.php` |
| `Media/` | 图片上传：MIME 与结构双重校验、重编码为 WebP、生成缩略图 | `MediaApiController.php` `MediaService.php` `routes.php` |
| `Messages/` | 一对一私信：会话、消息、未读与发送策略 | `MessageController.php` `MessageApiController.php` `MessageService.php` `routes.php` |
| `News/` | 校园资讯：发布/隐藏/置顶/编辑，首次发布幂等广播通知 | `NewsController.php` `NewsService.php` `routes.php` |
| `Notifications/` | 跨板块统一通知系统与未读计数 | `NotificationApiController.php` `NotificationService.php` `routes.php` |
| `Posts/` | 动态：发布/编辑/删除/转发、话题、信息流组装、展示结构转换 | `PostController.php` `PostApiController.php` `PostService.php` `PostRepository.php` `PostPresenter.php` `FeedService.php` `routes.php` |
| `Reactions/` | 点赞/收藏切换（唯一键 + 事务保证幂等） | `ReactionApiController.php` `ReactionService.php` `routes.php` |
| `Reports/` | 内容举报提交 | `ReportApiController.php` `ReportService.php` `routes.php` |
| `Search/` | 全站搜索（动态/用户/话题/表白墙）+ 实时建议接口 | `SearchController.php` `SearchService.php` `routes.php` |
| `Settings/` | 用户资料、安全、通知与隐私偏好；系统设置；首页轮播与团队名单管理 | `SettingsController.php` `SettingsService.php` `PreferenceService.php` `HomeBanners.php` `AboutTeam.php` `routes.php` |
| `Stream/` | SSE 实时通知（未读计数推送，含 `?once=1` 快照） | `StreamController.php` `routes.php` |
| `Themes/` | 外观中心：Light / Dark / System、外观保存/激活、首帧 color-scheme | `ThemeController.php` `ThemeApiController.php` `ThemeService.php` `ThemeBootstrap.php` `ThemeVars.php` `routes.php` |
| `Users/` | 个人主页与用户相关：收藏/点赞列表、拉黑、搜索 | `UserController.php` `UserRepository.php` `FavoriteService.php` `BlockApiController.php` `BlockService.php` `routes.php` |

#### app/Views/ — Twig 模板

```text
Views/
├── components/            可复用片段
│   ├── avatar.twig          头像
│   ├── comment.twig         评论条目
│   ├── confession-card.twig 表白墙/树洞卡片
│   ├── empty-state.twig     空状态占位
│   ├── entry-card.twig      板块帖子卡片
│   ├── event-card.twig      活动卡片
│   ├── load-more.twig       加载更多控件
│   ├── news-card.twig       资讯卡片
│   ├── page-back.twig       返回按钮
│   ├── pagination.twig      分页
│   ├── post-card.twig       动态卡片（与 post-card-render.ts 保持同步）
│   ├── settings-nav.twig    设置页导航
│   ├── user-card.twig       用户卡片
│   └── user-row.twig        用户列表行
├── layouts/               页面骨架
│   ├── app.twig             主站应用外壳（导航/回到顶部/主题）
│   ├── admin.twig           后台布局
│   └── install.twig         安装向导布局（自含样式）
└── pages/                 页面模板
    ├── home.twig            首页（信息流 + 轮播）
    ├── post.twig            动态详情
    ├── compose.twig         发布动态
    ├── topic.twig           话题页
    ├── search.twig          搜索结果
    ├── history.twig         浏览记录
    ├── login.twig           登录
    ├── register.twig        注册
    ├── appeal.twig          封禁申诉
    ├── me.twig              个人资料页
    ├── profile.twig         用户主页
    ├── about.twig           关于
    ├── music.twig           音乐页（内嵌播放器）
    ├── error.twig           错误页
    ├── install.twig         Web 安装向导
    ├── admin/               后台页面（dashboard / users / posts / comments / confessions /
    │                        reports / appeals / circles / boards / board-settings / team /
    │                        banners / events / news / news-form）
    ├── settings/            设置页（profile / security / notifications / privacy /
    │                        appearance / background）
    ├── confessions/         表白墙/树洞（board / compose / detail / mine）
    ├── circles/             兴趣圈子（index / new / show）
    ├── boards/              社区板块（index / board / new / show）
    ├── events/              校园活动（index / new / show / mine）
    ├── news/                校园资讯（index / show）
    └── messages/            私信（index / show）
```

### src-client/ — 前端源码

由 Vite 构建，入口 `main.ts`；别名 `@` 指向 `src-client`。

```text
src-client/
├── main.ts             入口：装配 Swup、按 data-page 挂载页面模块
├── types.ts            全局共享类型（用户、分页等）
├── sw.ts               Service Worker 源码（esbuild 打包为 public/sw.js）
├── core/               通用运行时
│   ├── api.ts             API 请求封装与 ApiError
│   ├── banner.ts          横幅轮播（自动/圆点/滑动，尊重 reduced-motion）
│   ├── dialog.ts          通用对话框（M3 Dialog / 移动端底部面板，含焦点管理）
│   ├── dom.ts             DOM/模板辅助
│   ├── error-boundary.ts  渲染降级 UI
│   ├── form-enhance.ts    表单增强（字数统计等，幂等）
│   ├── global-actions.ts  全局事件委托（灯箱等）
│   ├── i18n.ts            语言切换与 DOM 重译
│   ├── load-more.ts       列表加载更多（抓取下一页 SSR HTML）
│   ├── loading.ts         按钮加载态（加载指示器）
│   ├── nav.ts             路由 → 主导航分区状态机
│   ├── realtime.ts        SSE 实时通知与降级轮询
│   ├── skeleton.ts        列表骨架屏
│   ├── store.ts           nanostores 全局状态（用户/主题/未读等）
│   ├── swup-setup.ts      Swup 初始化（容器、持久化与插件）
│   └── toast.ts           轻提示
├── modules/            页面级模块（按 data-page 挂载）
│   ├── about.ts            关于页交互
│   ├── admin.ts            后台行编辑器（团队名单 / 首页轮播共用）
│   ├── comment-render.ts   评论渲染
│   ├── compose.ts          发布页（表情面板等）
│   ├── confessions.ts      表白墙/树洞页
│   ├── feed.ts             信息流交互
│   ├── messages.ts         私信页（记忆上次标签）
│   ├── post-card-render.ts 客户端动态卡片渲染（与 post-card.twig 同步）
│   ├── post-page.ts        动态详情页（含 Twikoo 集成）
│   ├── report-dialog.ts    举报对话框
│   ├── search.ts           搜索实时建议
│   ├── settings.ts         设置页模块路由
│   ├── theme-center.ts     外观中心交互（Light / Dark / System）
│   └── types.ts            模块类型定义
├── pwa/
│   ├── globals.d.ts       构建期注入的 SW 版本号类型声明
│   └── rules.ts           Service Worker 缓存策略（纯函数，可测）
├── theme/
│   ├── generate.ts        外观模式与固定品牌色（不再生成 HCT 调色板）
│   └── manager.ts         外观应用/持久化（登录与游客两种）
└── styles/
    ├── base.css           设计令牌（颜色/字体/间距/圆角/阴影/动效）
    ├── layout.css         布局（外壳/导航/栅格）
    ├── pages.css          页面级样式
    └── design-system.css  Apple 风格组件覆盖层
```

### public/ — Web 根与静态资源

文档根目录直接指向 `public/`。

```text
public/
├── index.php            单入口（含 InstallGate 门禁与依赖缺失提示）
├── router.php           php -S 开发服务器路由脚本
├── .htaccess            Apache 重写规则
├── manifest.webmanifest PWA 清单
├── sw.js                Service Worker（构建产物）
├── offline.html         离线兜底页
├── favicon.svg          站点图标
├── logo.png             站点 Logo
├── assets/              前端构建产物（JS/CSS/字体/图标的哈希文件 + manifest.json）
├── icons/               PWA 图标（192 / 512 / maskable-512 / apple-touch-icon）
├── i18n/                前端语言包（zh-CN.json、en.json）
├── vendor/              第三方自托管脚本（twikoo.all.min.js）
└── uploads/             用户上传（按日期目录，Web 可访问）
```

### bin/ — 命令行工具

```text
bin/
├── migrate.php      迁移命令行：migrate / status / rollback [N]
├── backup.php       备份工具：纯 PHP 导出数据库 + 归档上传文件（含保留策略）
├── make-admin.php   把已有账号提升为超级管理员
├── dev-start.sh     本地开发一体启动（MariaDB + PHP 开发服务器）
├── fe-build.sh      完整前端构建：Vite + Service Worker + 主题产物 + 文档刷新
└── composer.phar    本地 Composer 副本（应对无全局 composer 的环境）
```

### migrations/ — 数据库迁移

按文件名升序执行；`.sql` 文件用 `-- @@DOWN` 标记分隔回退段。

```text
migrations/
├── 001_users_and_sessions.sql          users / user_profiles / user_sessions
├── 002_roles_and_permissions.sql       roles / permissions / role_permissions / user_roles
├── 003_content_core.sql                media / posts / post_media / topics / post_topics
├── 004_comments_and_reactions.sql      comments / reactions
├── 005_notifications_and_moderation.sql notifications / reports / moderation_records
├── 006_preferences_themes_system.sql   user_preferences / user_themes / system_settings / audit_logs
├── 007_follows.sql                     follows
├── 008_confessions.sql                 confessions / confession_comments / confession_media
├── 009_reactions_extend.sql            扩展 reaction 支持的对象类型
├── 010_settings_update.sql             system_settings 调整
├── 011_messages_and_blocks.sql         conversations / messages / user_blocks
├── 012_user_appeals.sql                user_appeals
├── 013_communities_and_boards.sql      communities / community_members / boards / board_entries / board_entry_comments
├── 014_events.sql                      events / event_signups
├── 015_news.sql                        news
├── 016_phase4.sql                      post_views（浏览记录 / 转发）
├── 017_backgrounds.sql                 用户背景图偏好
├── 018_indexes.sql                     索引优化
├── 019_appeal_token.sql                申诉令牌
├── 020_board_logo.sql                  板块 Logo
├── 021_brand_color.sql                 品牌色设置
└── 047_fixed_apple_brand.sql           固定 #FFCC38 品牌强调色
```

### scripts/ — 工程脚本

```text
scripts/
├── gen-pwa-icons.php    生成 PWA 图标与 favicon（基于根目录 logo.png）
└── gen-docs.mjs         开发者文档生成器（刷新 tutorial.md 的 AUTO-GEN 区段）
```

### tests/ — 测试

```text
tests/
├── bootstrap.php               PHPUnit 引导
├── Support/
│   ├── HttpTestCase.php        HTTP 集成测试基类（内核启动、会话/CSRF 脚手架）
│   └── TestIp.php              为每个模拟请求注入独立 IP，避免误触限流
├── Feature/                    端到端集成测试
│   ├── HttpFlowTest.php          核心用户旅程（完整中间件链）
│   ├── UpgradeFlowTest.php       搜索、账号安全、通知偏好、个人中心、动态编辑
│   ├── MessagingFlowTest.php     私信、拉黑、隐私设置
│   ├── AppealFlowTest.php        封禁申诉与会话即时失效
│   ├── PhaseThreeFlowTest.php    兴趣圈子与统一板块框架
│   ├── EventFlowTest.php         校园活动报名与审核
│   ├── NewsFlowTest.php          校园资讯发布与广播幂等
│   ├── RepostFlowTest.php        转发
│   ├── HistoryFlowTest.php       浏览记录
│   ├── StreamApiTest.php         SSE 实时通知接口
│   ├── VideoFlowTest.php         视频直传
│   ├── CoinAndLevelFlowTest.php  投币流程、经验封顶、进阶答题门槛与站内头像端点
│   └── BackupDumpTest.php        备份导出
├── Unit/                       单元测试
│   ├── TextTest.php              文本辅助（相对时间等）
│   ├── ThemeVarsTest.php         主题变量校验与 CSS 生成
│   ├── MigratorParseTest.php     迁移文件解析
│   ├── SessionSecurityTest.php   会话安全
│   └── InstallEnvTest.php        .env 生成
└── frontend/                   Vitest 前端测试
    ├── generate.test.ts          主题颜色/变量生成
    └── pwa-rules.test.ts         Service Worker 缓存规则
```

### docs/ — 文档

```text
docs/
├── tutorial.md            开发者教程（环境搭建、架构、路由与模块、构建部署、FAQ；
│                          部分区段由 gen-docs.mjs 随构建自动刷新）
├── performance-audit.md   全站性能基线、优化记录与框架建议
└── i18n/
    └── README.en.md       英文版 README（本文件）
```

### music-app/ — 独立音乐播放器

`/music` 页面通过 iframe 内嵌使用；独立部署在 **Cloudflare Pages / Workers**，基于开源项目 Solara 2.0 改造（配色、字体、图标改为 MOFU 品牌体系与自托管资源）。

```text
music-app/
├── index.html / login.html      页面骨架
├── manifest.json / favicon.*    PWA 清单与图标
├── wrangler.toml                Cloudflare Workers/Pages 配置
├── src/worker.ts                Workers 适配入口
├── functions/                   Pages Functions（_middleware、api/、palette、proxy 等）
├── css/
│   ├── style.css / desktop.css / mobile.css / mofu-theme.css
│   ├── tokens/                  设计令牌
│   ├── layout/                  桌面布局
│   ├── components/              播放器控件 / 弹窗 / 舞台 / 搜索
│   └── mobile/                  移动端（base / controls / modals / search / sheet / stage）
├── js/
│   ├── index.js / app.js / state.js / constants.js / dom.js / i18n.js / mobile.js
│   ├── core/                    音频、Media Session、音质、本地存储
│   ├── features/                收藏、歌词、播放列表、搜索、设置
│   ├── mobile/                  移动端核心、手势、搜索、面板、舞台、工具栏
│   └── visual/                  极光背景、取色、聚光灯
├── fonts/                       自托管 Material Symbols
└── README.md                    子项目说明与出处/许可声明
```

### 根目录文件

```text
composer.json       PHP 依赖、PSR-4 自动加载与 composer 脚本
composer.lock       依赖锁定
package.json        前端依赖与 npm 脚本
package-lock.json   依赖锁定
vite.config.ts      Vite 构建 / Vitest 配置（base=/assets/，输出 public/assets）
tsconfig.json       TypeScript 配置
phpunit.xml         PHPUnit 配置
.env.example        环境变量模板（见下）
.gitignore          忽略规则
logo.png            品牌 Logo（图标生成的唯一事实来源）
font/               字体源文件（当前 Web 端使用系统字体栈，不再打包这些文件）
storage/            运行时数据（日志 / 缓存 / 备份 / install.lock）
```

## 环境变量

复制 `.env.example` 为 `.env` 后按需修改（虚拟主机可用 Web 安装向导自动生成）。

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `APP_NAME` | `MOFU社区` | 站点名称 |
| `APP_ENV` | `local` | 运行环境 |
| `APP_DEBUG` | `true` | 调试模式（错误详情） |
| `APP_URL` | `http://127.0.0.1:8000` | 站点根地址 |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | 数据库地址与端口 |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `mofu` / `mofu` / 空 | 数据库名与凭据 |
| `SESSION_COOKIE` | `mofu_session` | 会话 Cookie 名 |
| `SESSION_LIFETIME` | `2592000` | 会话有效期（秒，默认 30 天） |
| `VITE_DEV_SERVER` | 空 | 填 Vite dev server 地址以启用 HMR；留空读取构建 manifest |
| `UPLOAD_MAX_MB` | `8` | 图片上传上限（MB） |
| `UPLOAD_VIDEO_MAX_MB` | `60` | 视频上传上限（MB，需同步 `php.ini`） |
| `THEME_DEFAULT_COLOR` | `#FFCC38` | 固定品牌强调色（外观仅可切换浅色 / 深色 / 跟随系统） |
| `MUSIC_APP_URL` | 空 | 音乐播放器地址；留空则 `/music` 显示占位 |
| `TWIKOO_ENV_ID` | 空 | Twikoo 环境 ID；留空则只用站内评论 |
| `TWIKOO_SCRIPT_URL` | `/vendor/twikoo.all.min.js` | 评论脚本地址（默认自托管） |
| `TWIKOO_LANG` | `zh-CN` | 评论语言（已内嵌于主包） |

> 生产部署务必设置随机 `APP_KEY`、强数据库密码，并关闭 `APP_DEBUG`。

## 数据库与迁移

- 迁移文件为普通 `.sql`，命名 `NNN_名称.sql`，按文件名升序执行。
- 单个文件内 `-- @@DOWN` 之前为 UP 段、之后为 DOWN 段；无标记时整个文件视为 UP（不可回退）。

```bash
php bin/migrate.php migrate      # 执行所有未应用的迁移
php bin/migrate.php status       # 查看迁移状态
php bin/migrate.php rollback [N] # 回退最近 N 个迁移（默认 1）
```

## 文档

- [开发者教程](docs/tutorial.md) — 环境搭建、架构解析、路由与模块、构建部署、FAQ（部分区段随构建自动刷新）
- [性能审计](docs/performance-audit.md) — 冷/热缓存基线、并发测试、优化记录与技术栈建议
- [English README](docs/i18n/README.en.md)

## 路线图

- ✅ **第一阶段 · 基础平台** — 架构、Apple 风格设计系统、Light / Dark / System 外观、响应式外壳、SSR、Swup、注册登录、首页与动态、点赞评论、基础后台
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

- [Solara 2.0 / MusicApi](https://github.com/Qiunai-520/MusicApi) — 音乐播放器（`music-app/`）上游
- [Three.js](https://threejs.org/) — `/konbini` 程序化 3D 街景（MIT）
- Slim Framework、Twig、Vite、Swup
- Material Symbols（图标字体）

## 许可证

私有软件（Proprietary）。未经授权，不得复制、分发或用于任何商业用途。详见 `composer.json` 中的 `license` 声明。

