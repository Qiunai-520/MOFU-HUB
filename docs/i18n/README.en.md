<h1 align="center">MOFU Community</h1>

> A modern campus community platform with an Apple-inspired design system

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.2.35%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.2.35+">
  <img src="https://img.shields.io/badge/MySQL%20%2F%20MariaDB-8.0%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL / MariaDB">
  <img src="https://img.shields.io/badge/TypeScript-5.6-3178C6?logo=typescript&logoColor=white" alt="TypeScript">
  <img src="https://img.shields.io/badge/Vite-8-646CFF?logo=vite&logoColor=white" alt="Vite 8">
  <img src="https://img.shields.io/badge/Apple%20Design-%23FFCC38-FFCC38" alt="Apple-inspired design system">
  <img src="https://img.shields.io/badge/License-Proprietary-lightgrey" alt="License: Proprietary">
</p>

<p align="center">
  <a href="../../README.md">中文</a> · <b>English</b>
</p>

---

MOFU Community is a comprehensive campus community platform: a campus feed, an anonymous confession wall and "tree hollow", follows and notifications, a personalized appearance center, content moderation, and an admin console. The backend is a PHP + Slim SSR architecture; the frontend pairs TypeScript with Swup for an app shell and smooth page transitions. The whole site uses one Apple-inspired design system: a platform system-font stack, system neutrals, a fixed brand accent (`#FFCC38`), restrained radii and shadows, and Light / Dark / System appearance.

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Quick Start](#quick-start)
- [Common Commands](#common-commands)
- [Directory Structure](#directory-structure)
  - [Top-level Layout](#top-level-layout)
  - [app/ — Backend Application](#app--backend-application)
  - [src-client/ — Frontend Source](#src-client--frontend-source)
  - [public/ — Web Root & Static Assets](#public--web-root--static-assets)
  - [bin/ — CLI Tools](#bin--cli-tools)
  - [migrations/ — Database Migrations](#migrations--database-migrations)
  - [scripts/ — Engineering Scripts](#scripts--engineering-scripts)
  - [tests/ — Tests](#tests--tests)
  - [docs/ — Documentation](#docs--documentation)
  - [music-app/ — Standalone Music Player](#music-app--standalone-music-player)
  - [Root Files](#root-files)
- [Environment Variables](#environment-variables)
- [Database & Migrations](#database--migrations)
- [Documentation](#documentation)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Acknowledgements](#acknowledgements)
- [License](#license)

## Features

- **Campus feed** — rich posts (up to 9 images), editing, deletion, topic tags, public / private visibility; four streams: recommended, latest, hot, following
- **Interactions** — likes, favorites, comments and replies, content reports, copy-link sharing; optimistic updates + server-side validation + duplicate protection
- **Follows** — follow / unfollow, follower & following lists, follow suggestions
- **Site-wide search** — one query across posts, users, topics and confessions; permission-filtered (private content and banned accounts never leak), search history, hot topics
- **Anonymous boards** — confession wall and "tree hollow": fully anonymous / nickname-signed submissions (guests can also post anonymously), categories, moderation queue, anonymous comments and likes, "my submissions" status tracking
- **Notification center** — interaction notifications, unread badge, mark-all-as-read
- **Profiles** — posts, favorites, likes, comments, followers / following tabs; profile editing and avatar upload
- **Account & security** — password change, login device management (sign out a specific device or all other devices), notification preferences (likes / comments / follows can be toggled separately + do-not-disturb hours), privacy settings (DM policy and list visibility)
- **Ban appeals** — suspended / banned accounts can submit an appeal from the appeal page; once approved by an admin the account is restored automatically and notified; bans take effect immediately (existing sessions are invalidated at once)
- **Direct messages** — one-to-one DMs: conversation list, unread badges, message history, new messages arrive instantly while SSE is connected (8-second polling fallback retained)
- **Blocking** — blocking intercepts follows and DMs in both directions and automatically removes existing follow relations; manage the block list in privacy settings
- **Interest circles** — create / join circles (open or approval-based); in-circle posts reuse the post system (images / topics / comments / likes), are visible to members only and are isolated from the main feed, search and other users' profiles; membership approval & removal, and the circle owner can edit circle settings
- **Community boards** — one framework for six boards: forum, Q&A, mutual aid, study exchange, lost & found and marketplace; Q&A / mutual-aid posts can be marked "solved", marketplace items "sold", and lost & found distinguishes lost / found reports; posts support likes, favorites and replies, with per-board status filters and "latest / hot" sorting
- **Campus events** — create events (time / location / capacity / optional cover image, the cover reusing the upload & compression pipeline); sign-ups are either direct or approval-based (organizers can approve / reject / remove), with automatic capacity-full and deadline guards; browse events by "upcoming / ongoing / past", organizers can edit, cancel (automatically notifying signed-up members) and delete; "My events" aggregates the ones you joined and the ones you organized
- **Campus news** — an editorial channel for admins (notices / campus news / study resources): drafts & publishing, pinning, cover image, view counter; the first publish automatically pushes a news notification to all active users (idempotent, never re-sent); the admin console supports publish / hide / pin / edit / delete
- **PWA & offline** — installable to the home screen (Web App Manifest + adaptive icons + Apple Touch icon); a Service Worker caches build artifacts and images and falls back to the offline page for navigation when the network is gone; `sw.js` is versioned automatically on every build, with layered caching (precache / hashed assets / FIFO-capped user media)
- **Reposts** — one-tap repost from the post detail page into your own feed (undoable): a quote card shows the original author with excerpt/thumbnail, or a placeholder once the original is deleted; repost counter plus a "reposted your post" notification; in-circle and private posts cannot be reposted
- **Browsing history** — posts viewed by signed-in users are recorded automatically (deduplicated to the most recent visit), with `/history` to review and clear them in one tap; deleted content and in-circle posts never appear
- **Real-time notifications** — an SSE long connection pushes unread badges (notifications + DMs), reconnecting automatically on disconnect and degrading to a 30-second polling fallback after repeated failures; dev environments need multiple workers (see the tutorial)
- **Post videos** — posts support a single directly uploaded video (MP4 / WebM, no transcoding, capped by `UPLOAD_VIDEO_MAX_MB`) and cannot be combined with images; native player in the feed and detail page (constrained by the deployment's PHP upload limits)
- **Backup tool** — `php bin/backup.php` exports the database in pure PHP and archives uploads (no mysqldump dependency), with a retention policy and documented restore steps
- **Web install wizard** — deploy even on shared hosting without a CLI: visit `/install` to run environment checks, configure the database, execute migrations in-page and create the super admin, then it locks itself automatically
- **Appearance center** — Light / Dark / System modes, multiple saved appearances, cross-device sync; guests can save locally. The brand accent is fixed at `#FFCC38` and never changes with appearance
- **Apple-inspired experience** — Swup navigation without full reloads, a platform system-font stack, an iOS-style mobile tab bar, a macOS-style desktop sidebar, consistent grouped lists / forms / dialogs and Material Symbols icons
- **Admin console** — dashboard, users (status & roles), posts, comments, confession moderation, report handling, appeal management, circle management, board management, event management, news management
- **Security** — database-backed sessions, site-wide CSRF, permission-point based access control, API rate limiting, audit logs, upload validation

## Tech Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP ≥ 8.2.35 · Slim 4 · Twig 3 · vlucas/phpdotenv |
| Database | MySQL / MariaDB (utf8mb4) · built-in migrator |
| Frontend | TypeScript (strict) · Vite 8 · Swup 4 · nanostores |
| Design system | Apple-inspired tokens · system fonts · Light / Dark / System · Material Symbols |
| Testing | PHPUnit 10 · Vitest 5 |

## Quick Start

> Requirements: PHP 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12 or a later patch on the same branch (`pdo_mysql`, `mbstring`, `json`, `fileinfo`, `openssl`, `gd`), Composer, Node.js ≥ 20, MySQL / MariaDB. PHP 8.1 and older are unsupported.

> Shared hosting / no CLI: just upload the code and open your site — the `/install` web wizard handles environment checks → database setup → automatic migrations and admin creation, then locks itself. The steps below are the local development flow:

```bash
# 1. Install dependencies
composer install
npm install

# 2. Configure environment (copy and adjust, e.g. database credentials)
cp .env.example .env

# 3. Initialize the database
php bin/migrate.php migrate

# 4. Build frontend assets (output to public/assets)
npm run build

# 5. Start the dev server
composer serve      # http://127.0.0.1:8000
```

See the **[Developer Tutorial](../tutorial.md)** (Chinese) for setup details, architecture notes and extension guides.

## Common Commands

| Command | Description |
| --- | --- |
| `composer serve` | Start the PHP dev server (`127.0.0.1:8000`) |
| `bash bin/dev-start.sh` | Start MariaDB + dev server together (local all-in-one) |
| `php bin/migrate.php migrate \| status \| rollback` | Database migrations / status / rollback |
| `php bin/make-admin.php <username>` | Promote an existing account to super admin |
| `php bin/backup.php` | Back up the database and uploads (defaults to `storage/backups/`; supports `--skip-uploads` / `--keep=N`) |
| `npm run build` | Build frontend (Vite) |
| `bash bin/fe-build.sh` | Full build: frontend + Service Worker + theme artifacts + docs refresh |
| `npm run dev` | Vite dev server (HMR, with `VITE_DEV_SERVER` in `.env`) |
| `npm run docs` | Refresh auto-generated sections of the developer tutorial |
| `php scripts/gen-pwa-icons.php` | Regenerate the PWA icons and favicon |
| `composer test` | Backend tests (PHPUnit) |
| `composer security` | Deployment security gate: PHP version, extensions, debug flags, APP_KEY, upload directory |
| `composer audit --locked` | Check Composer dependencies against security advisories (network required) |
| `npm test` | Frontend tests (Vitest) |

## Directory Structure

### Top-level Layout

```text
mofu-community/
├── app/            PHP application (Bootstrap / Core / Install / Modules / Views)
├── bin/            CLI tools (migrations, backup, build, dev start)
├── docs/           Documentation (developer tutorial + bilingual README)
├── font/           Font source files (not bundled; the web app uses system fonts)
├── migrations/     Database migrations (21 .sql files)
├── public/         Web root (single entry + build output + static assets + uploads)
├── scripts/        Engineering scripts (theme, icons, docs, font subset)
├── src-client/     Frontend TypeScript source
├── storage/        Runtime data (logs / cache / backups / install lock)
├── tests/          Tests (PHPUnit Feature/Unit + Vitest frontend)
├── music-app/      Standalone music player (deployed on Cloudflare Pages/Workers)
├── composer.json   PHP dependencies & scripts
├── package.json    Frontend dependencies & scripts
├── vite.config.ts  Vite / Vitest configuration
├── phpunit.xml     PHPUnit configuration
├── tsconfig.json   TypeScript configuration
├── .env.example    Environment variable template
└── logo.png        Brand logo (single source of truth for icons)
```

### app/ — Backend Application

PSR-4 namespace `Mofu\`, with the root namespace mapping directly to `app/`.

#### app/Bootstrap/ — Application Wiring

```text
Bootstrap/
├── App.php            Application kernel: builds the Slim app, mounts middleware and module routes
├── Config.php         App config: .env + defaults, supports "a.b.c" dotted-path reads
├── Container.php      Minimal service container: registers lazy factories, instantiates and caches singletons on demand
├── ErrorHandler.php   Unified error handling: JSON for API requests, HTML error pages for page requests
└── Services.php       Business module service registry (leaf services with scalar parameters are registered explicitly here)
```

#### app/Core/ — Core Infrastructure

```text
Core/
├── Database/
│   ├── Connection.php  PDO connection factory
│   ├── Migrator.php    Lightweight migrator (.sql runs in ascending filename order, supports -- @@DOWN rollback section)
│   └── Query.php       Thin PDO wrapper: parameterized queries, convenience CRUD and pagination
├── Http/
│   ├── ApiController.php   API controller base class: current user and JSON response helpers
│   ├── Ctx.php             Request-context convenience accessors
│   ├── HttpException.php   Business exception carrying an HTTP status code
│   ├── Json.php            Unified JSON response format (success/failure structure)
│   └── Middleware/
│       ├── CsrfMiddleware.php     Validates the CSRF token on mutating requests
│       └── SessionMiddleware.php  Resolves / creates the visitor session and injects SessionState
├── Security/
│   ├── Auth.php            Auth service: credential verification and session lifecycle
│   ├── Csrf.php            CSRF token generation & validation (token stored in the server-side session)
│   ├── Guard.php           Authorization checks (based on the user's permissions keys)
│   ├── RateLimiter.php     File-based lightweight rate limiting (approximate sliding window, no Redis required)
│   ├── SessionManager.php  Database sessions: create, resolve, rotate and destroy
│   └── SessionState.php    Session state within a request lifecycle
├── Support/
│   ├── AliasGenerator.php  Anonymous alias generator (confession wall / tree hollow)
│   ├── AuditLogger.php     Operation audit log
│   ├── Logger.php          File logger (context appended as JSON, one line per record)
│   ├── Text.php            Text helpers (relative time, excerpts, etc.)
│   └── Backup/
│       └── DbDumper.php    Pure-PHP database export (SHOW CREATE TABLE + batched INSERT)
└── View/
    ├── Page.php          Page render helper: assembles shared context + renders the response
    ├── Present.php       Generic presentation helper
    ├── TwigRenderer.php  Twig rendering (auto-escaping templates)
    ├── ViewContext.php   Builds the shared page context
    └── ViteAssets.php    Vite asset bridge (dev-server module scripts / production manifest)
```

#### app/Install/ — Web Install Wizard

```text
Install/
├── EnvChecker.php     Environment self-check for the install wizard (PHP version, extensions, writable dirs, etc.)
├── EnvFileWriter.php  .env generator (key whitelist rewrite, double-quote escaping, atomic replace + backup)
├── InstallGate.php    Install gate: called by index.php before building Slim, decides pass / redirect / self-heal and lock
└── Installer.php      Main install flow: env check → database config → migrations & admin → finish and lock
```

#### app/Modules/ — Business Modules

Each module directory contains controllers, services and its own `routes.php` (mounted centrally by `Bootstrap/App.php`).

| Module | Responsibility | Key files |
| --- | --- | --- |
| `Admin/` | Admin console: dashboard, users, posts, comments, confession moderation, report & appeal handling, circle/board/event/news management, team roster and home banner maintenance, comment export to Twikoo | `AdminController.php` `routes.php` |
| `Appeals/` | Banned/suspended account appeals: users verify identity to submit, admins approve/reject, approval restores the account and notifies | `AppealController.php` `AppealService.php` `routes.php` |
| `Auth/` | Registration, login, logout and session establishment | `AuthController.php` `routes.php` |
| `Boards/` | Unified board framework: forum / Q&A / mutual aid / study / lost & found / marketplace, differentiated via `kind` and `extra(JSON)` | `BoardController.php` `BoardService.php` `routes.php` |
| `Comments/` | On-site comments: list, create, delete and export (→ Twikoo import format) | `CommentApiController.php` `CommentController.php` `CommentService.php` `CommentRepository.php` `CommentPresenter.php` `CommentExporter.php` `routes.php` |
| `Communities/` | Interest circles: create, join (open/approval), leave, membership management and visibility rules | `CommunityController.php` `CommunityApiController.php` `CommunityService.php` `routes.php` |
| `Confessions/` | Confession wall and tree hollow: anonymous/nickname-signed submissions, moderation, anonymous comments (with identity isolation and aliases) | `ConfessionController.php` `ConfessionApiController.php` `ConfessionService.php` `ConfessionRepository.php` `ConfessionPresenter.php` `routes.php` |
| `Events/` | Campus events: create, sign up (direct/approval-based), roster management and cancellation | `EventController.php` `EventService.php` `routes.php` |
| `Follows/` | Follow / unfollow | `FollowApiController.php` `FollowService.php` `routes.php` |
| `History/` | Browsing history: deduplicated recording, review & clear, visibility filtering | `HistoryController.php` `HistoryService.php` `routes.php` |
| `Home/` | Home page, about page, `robots.txt` / `sitemap.xml`, music page | `HomeController.php` `routes.php` |
| `Media/` | Image uploads: dual MIME & structure validation, re-encode to WebP, thumbnail generation | `MediaApiController.php` `MediaService.php` `routes.php` |
| `Messages/` | One-to-one direct messages: conversations, messages, unread counts and send policy | `MessageController.php` `MessageApiController.php` `MessageService.php` `routes.php` |
| `News/` | Campus news: publish / hide / pin / edit, idempotent broadcast notification on first publish | `NewsController.php` `NewsService.php` `routes.php` |
| `Notifications/` | Cross-board unified notification system and unread counts | `NotificationApiController.php` `NotificationService.php` `routes.php` |
| `Posts/` | Posts: create / edit / delete / repost, topics, feed assembly, presentation-structure transformation | `PostController.php` `PostApiController.php` `PostService.php` `PostRepository.php` `PostPresenter.php` `FeedService.php` `routes.php` |
| `Reactions/` | Like / favorite toggling (unique key + transaction for idempotency) | `ReactionApiController.php` `ReactionService.php` `routes.php` |
| `Reports/` | Content report submission | `ReportApiController.php` `ReportService.php` `routes.php` |
| `Search/` | Site-wide search (posts / users / topics / confessions) + live suggestion endpoint | `SearchController.php` `SearchService.php` `routes.php` |
| `Settings/` | User profile, security, notification and privacy preferences; system settings; home banner and team roster management | `SettingsController.php` `SettingsService.php` `PreferenceService.php` `HomeBanners.php` `AboutTeam.php` `routes.php` |
| `Stream/` | SSE real-time notifications (unread-count push, with a `?once=1` snapshot) | `StreamController.php` `routes.php` |
| `Themes/` | Appearance center: Light / Dark / System, save/activate, first-paint color-scheme | `ThemeController.php` `ThemeApiController.php` `ThemeService.php` `ThemeBootstrap.php` `ThemeVars.php` `routes.php` |
| `Users/` | Public profiles and user-related features: favorites / likes lists, blocking, search | `UserController.php` `UserRepository.php` `FavoriteService.php` `BlockApiController.php` `BlockService.php` `routes.php` |

#### app/Views/ — Twig Templates

```text
Views/
├── components/            Reusable partials
│   ├── avatar.twig          Avatar
│   ├── comment.twig         Comment item
│   ├── confession-card.twig Confession wall / tree hollow card
│   ├── empty-state.twig     Empty-state placeholder
│   ├── entry-card.twig      Board post card
│   ├── event-card.twig      Event card
│   ├── load-more.twig       Load-more control
│   ├── news-card.twig       News card
│   ├── page-back.twig       Back button
│   ├── pagination.twig      Pagination
│   ├── post-card.twig       Post card (kept in sync with post-card-render.ts)
│   ├── settings-nav.twig    Settings page navigation
│   ├── user-card.twig       User card
│   └── user-row.twig        User list row
├── layouts/               Page skeletons
│   ├── app.twig             Main site app shell (nav / FAB / theme)
│   ├── admin.twig           Admin layout
│   └── install.twig         Install wizard layout (self-contained styles)
└── pages/                 Page templates
    ├── home.twig            Home (feed + banner)
    ├── post.twig            Post detail
    ├── compose.twig         Compose post
    ├── topic.twig           Topic page
    ├── search.twig          Search results
    ├── history.twig         Browsing history
    ├── login.twig           Login
    ├── register.twig        Register
    ├── appeal.twig          Ban appeal
    ├── me.twig              Personal profile page
    ├── profile.twig         User profile page
    ├── about.twig           About
    ├── music.twig           Music page (embedded player)
    ├── error.twig           Error page
    ├── install.twig         Web install wizard
    ├── admin/               Admin pages (dashboard / users / posts / comments / confessions /
    │                        reports / appeals / circles / boards / board-settings / team /
    │                        banners / events / news / news-form)
    ├── settings/            Settings pages (profile / security / notifications / privacy /
    │                        appearance / background)
    ├── confessions/         Confession wall / tree hollow (board / compose / detail / mine)
    ├── circles/             Interest circles (index / new / show)
    ├── boards/              Community boards (index / board / new / show)
    ├── events/              Campus events (index / new / show / mine)
    ├── news/                Campus news (index / show)
    └── messages/            Direct messages (index / show)
```

### src-client/ — Frontend Source

Built by Vite, entry `main.ts`; the alias `@` points to `src-client`.

```text
src-client/
├── main.ts             Entry: wires up Swup, mounts page modules by data-page
├── types.ts            Shared global types (user, pagination, etc.)
├── sw.ts               Service Worker source (bundled to public/sw.js by esbuild)
├── core/               Shared runtime
│   ├── api.ts             API request wrapper and ApiError
│   ├── banner.ts          Banner carousel (autoplay / dots / swipe, respects reduced-motion)
│   ├── dialog.ts          Generic dialog (M3 Dialog / mobile bottom sheet, with focus management)
│   ├── dom.ts             DOM / template helpers
│   ├── error-boundary.ts  Render fallback UI
│   ├── form-enhance.ts    Form enhancements (character counters, etc., idempotent)
│   ├── global-actions.ts  Global event delegation (lightbox, etc.)
│   ├── i18n.ts            Language switching and DOM retranslation
│   ├── load-more.ts       List load-more (fetches the next SSR HTML page)
│   ├── loading.ts         Button loading state (loading indicator)
│   ├── nav.ts             Route → primary nav section state machine
│   ├── realtime.ts        SSE real-time notifications with fallback polling
│   ├── skeleton.ts        List skeleton screens
│   ├── store.ts           nanostores global state (user / theme / unread, etc.)
│   ├── swup-setup.ts      Swup initialization (containers, persistence and plugins)
│   └── toast.ts           Toast notifications
├── modules/            Page-level modules (mounted by data-page)
│   ├── about.ts            About page interactions
│   ├── admin.ts            Admin row editor (shared by team roster / home banners)
│   ├── comment-render.ts   Comment rendering
│   ├── compose.ts          Compose page (emoji panel, etc.)
│   ├── confessions.ts      Confession wall / tree hollow page
│   ├── feed.ts             Feed interactions
│   ├── messages.ts         Direct messages page (remembers the last tab)
│   ├── post-card-render.ts Client-side post-card rendering (kept in sync with post-card.twig)
│   ├── post-page.ts        Post detail page (with Twikoo integration)
│   ├── report-dialog.ts    Report dialog
│   ├── search.ts           Live search suggestions
│   ├── settings.ts         Settings page module router
│   ├── theme-center.ts     Appearance center interactions
│   └── types.ts            Module type definitions
├── pwa/
│   ├── globals.d.ts       Type declaration for the build-injected SW version
│   └── rules.ts           Service Worker cache strategy (pure functions, testable)
├── theme/
│   ├── generate.ts        M3 dynamic color roles and variable generation
│   └── manager.ts         Theme apply / persist (both signed-in and guest)
└── styles/
    ├── base.css           Base styles and design tokens
    ├── layout.css         Layout (shell / nav / grid)
    └── pages.css          Page-level styles
```

### public/ — Web Root & Static Assets

The document root points directly at `public/`.

```text
public/
├── index.php             Single entry (with the InstallGate guard and a missing-dependency notice)
├── router.php            php -S dev-server router script
├── .htaccess             Apache rewrite rules
├── manifest.webmanifest  PWA manifest
├── sw.js                 Service Worker (build artifact)
├── offline.html          Offline fallback page
├── favicon.svg           Site icon
├── logo.png              Site logo
├── assets/               Frontend build output (hashed JS/CSS/font/icon files + manifest.json)
├── icons/                PWA icons (192 / 512 / maskable-512 / apple-touch-icon)
├── i18n/                 Frontend language packs (zh-CN.json, en.json)
├── vendor/               Third-party self-hosted scripts (twikoo.all.min.js)
└── uploads/              User uploads (dated directories, web-accessible)
```

### bin/ — CLI Tools

```text
bin/
├── migrate.php      Migration CLI: migrate / status / rollback [N]
├── backup.php       Backup tool: pure-PHP database export + uploads archive (with retention policy)
├── make-admin.php   Promote an existing account to super admin
├── dev-start.sh     Local all-in-one dev start (MariaDB + PHP dev server)
├── fe-build.sh      Full frontend build: Vite + Service Worker + theme artifacts + docs refresh
└── composer.phar    Local Composer copy (for environments without a global composer)
```

### migrations/ — Database Migrations

Run in ascending filename order; `.sql` files use a `-- @@DOWN` marker to separate the rollback section.

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
├── 009_reactions_extend.sql            extends the supported reaction object types
├── 010_settings_update.sql             system_settings adjustments
├── 011_messages_and_blocks.sql         conversations / messages / user_blocks
├── 012_user_appeals.sql                user_appeals
├── 013_communities_and_boards.sql      communities / community_members / boards / board_entries / board_entry_comments
├── 014_events.sql                      events / event_signups
├── 015_news.sql                        news
├── 016_phase4.sql                      post_views (browsing history / reposts)
├── 017_backgrounds.sql                 user background-image preferences
├── 018_indexes.sql                     index optimization
├── 019_appeal_token.sql                appeal token
├── 020_board_logo.sql                  board logo
└── 021_brand_color.sql                 brand color setting
```

### scripts/ — Engineering Scripts

```text
scripts/
├── gen-pwa-icons.php    Generate the PWA icons and favicon (based on the root logo.png)
└── gen-docs.mjs         Developer docs generator (refreshes AUTO-GEN sections of tutorial.md)
```

### tests/ — Tests

```text
tests/
├── bootstrap.php               PHPUnit bootstrap
├── Support/
│   ├── HttpTestCase.php        HTTP integration test base class (kernel boot, session/CSRF scaffolding)
│   └── TestIp.php              Injects a unique IP per simulated request to avoid tripping the rate limiter
├── Feature/                    End-to-end integration tests
│   ├── HttpFlowTest.php          Core user journey (full middleware chain)
│   ├── UpgradeFlowTest.php       Search, account security, notification preferences, profile, post editing
│   ├── MessagingFlowTest.php     Direct messages, blocking, privacy settings
│   ├── AppealFlowTest.php        Ban appeals and immediate session invalidation
│   ├── PhaseThreeFlowTest.php    Interest circles and the unified board framework
│   ├── EventFlowTest.php         Campus event sign-ups and approval
│   ├── NewsFlowTest.php          Campus news publishing and broadcast idempotency
│   ├── RepostFlowTest.php        Reposts
│   ├── HistoryFlowTest.php       Browsing history
│   ├── StreamApiTest.php         SSE real-time notification endpoint
│   ├── VideoFlowTest.php         Direct video upload
│   └── BackupDumpTest.php        Backup export
├── Unit/                       Unit tests
│   ├── TextTest.php              Text helpers (relative time, etc.)
│   ├── ThemeVarsTest.php         Theme variable validation and CSS generation
│   ├── MigratorParseTest.php     Migration file parsing
│   ├── SessionSecurityTest.php   Session security
│   └── InstallEnvTest.php        .env generation
└── frontend/                   Vitest frontend tests
    ├── generate.test.ts          Theme color/variable generation
    └── pwa-rules.test.ts         Service Worker cache rules
```

### docs/ — Documentation

```text
docs/
├── tutorial.md            Developer tutorial (environment setup, architecture, routes & modules, build & deploy, FAQ;
│                          some sections are auto-refreshed by gen-docs.mjs on build)
└── i18n/
    └── README.en.md       English README (this file)
```

### music-app/ — Standalone Music Player

The `/music` page embeds it via an iframe; it is deployed independently on **Cloudflare Pages / Workers** and is adapted from the open-source Solara 2.0 project (colors, fonts and icons reworked to the MOFU brand system with self-hosted assets).

```text
music-app/
├── index.html / login.html      Page skeletons
├── manifest.json / favicon.*    PWA manifest and icons
├── wrangler.toml                Cloudflare Workers/Pages configuration
├── src/worker.ts                Workers adapter entry
├── functions/                   Pages Functions (_middleware, api/, palette, proxy, etc.)
├── css/
│   ├── style.css / desktop.css / mobile.css / mofu-theme.css
│   ├── tokens/                  Design tokens
│   ├── layout/                  Desktop layout
│   ├── components/              Player controls / modals / stage / search
│   └── mobile/                  Mobile (base / controls / modals / search / sheet / stage)
├── js/
│   ├── index.js / app.js / state.js / constants.js / dom.js / i18n.js / mobile.js
│   ├── core/                    Audio, Media Session, quality, local storage
│   ├── features/                Favorites, lyrics, playlists, search, settings
│   ├── mobile/                  Mobile core, gestures, search, panel, stage, toolbar
│   └── visual/                  Aurora background, color extraction, spotlight
├── fonts/                       Self-hosted Material Symbols
└── README.md                    Subproject notes, origin and license notice
```

### Root Files

```text
composer.json       PHP dependencies, PSR-4 autoloading and composer scripts
composer.lock       Dependency lockfile
package.json        Frontend dependencies and npm scripts
package-lock.json   Dependency lockfile
vite.config.ts      Vite build / Vitest config (base=/assets/, output to public/assets)
tsconfig.json       TypeScript configuration
phpunit.xml         PHPUnit configuration
.env.example        Environment variable template (see below)
.gitignore          Ignore rules
logo.png            Brand logo (single source of truth for icon generation)
font/               Font source files (not bundled; system fonts are used on the web)
storage/            Runtime data (logs / cache / backups / install.lock)
```

## Environment Variables

Copy `.env.example` to `.env` and adjust as needed (shared hosting can generate it via the web install wizard).

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `MOFU社区` | Site name |
| `APP_ENV` | `local` | Runtime environment |
| `APP_DEBUG` | `true` | Debug mode (error details); must be `false` in production |
| `APP_URL` | `http://127.0.0.1:8000` | Site root URL |
| `APP_KEY` | empty | **Required** 64-char random hex key; use the install wizard or `php -r "echo bin2hex(random_bytes(32));"` |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | Database host and port |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `mofu` / `mofu` / empty | Database name and credentials |
| `SESSION_COOKIE` | `mofu_session` | Session cookie name |
| `SESSION_LIFETIME` | `2592000` | Session lifetime (seconds, 30 days by default) |
| `VITE_DEV_SERVER` | empty | Set to the Vite dev server URL to enable HMR; leave empty to read the build manifest |
| `UPLOAD_MAX_MB` | `8` | Image upload limit (MB) |
| `UPLOAD_VIDEO_MAX_MB` | `60` | Video upload limit (MB, keep `php.ini` in sync) |
| `THEME_DEFAULT_COLOR` | `#FFCC38` | Fixed brand accent color (appearance mode is user-selectable) |
| `MUSIC_APP_URL` | empty | Music player URL; if empty, `/music` shows a placeholder |
| `TWIKOO_ENV_ID` | empty | Twikoo environment ID; if empty, only on-site comments are used |
| `TWIKOO_SCRIPT_URL` | `/vendor/twikoo.all.min.js` | Comment script URL (self-hosted by default) |
| `TWIKOO_LANG` | `zh-CN` | Comment language (already bundled into the main package) |

> Always set a random `APP_KEY` and a strong database password for production deployments.

### Security updates and PHP CVEs

**PHP2Shell (CVE-2026-109618 / CVE-2026-109619)** is an unauthenticated PHP RCE issue fixed in PHP **8.2.35, 8.3.36, 8.4.27 and 8.5.12**. The floors are branch-specific: PHP 8.3.35, 8.4.26 and 8.5.11 are still vulnerable. PHP 8.1 and older are end-of-life and must not be used in production.

PHP core CVEs cannot be patched by application code: upgrade the server runtime and restart every PHP worker. Partial mitigations and update commands are documented in [PHP2Shell handling](../security/php2shell.md).

Before deployment, run:

```bash
composer security
composer audit --locked
```

Production must use `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, a random `APP_KEY`, and `expose_php=Off`. Upload directories must deny script execution; the bundled `public/uploads/.htaccess` covers Apache.

## Database & Migrations

- Migration files are plain `.sql`, named `NNN_name.sql`, executed in ascending filename order.
- Within a file, the part before `-- @@DOWN` is the UP section and the part after is the DOWN section; without the marker the whole file is treated as UP (not reversible).

```bash
php bin/migrate.php migrate      # apply all pending migrations
php bin/migrate.php status       # show migration status
php bin/migrate.php rollback [N] # roll back the most recent N migrations (default 1)
```

## Documentation

- [Developer Tutorial](../tutorial.md) — environment setup, architecture, routes & modules, build & deploy, FAQ (some sections auto-refresh on build)
- [中文 README](../../README.md)

## Roadmap

- ✅ **Phase 1 · Foundation** — architecture, Apple-inspired design system, Light / Dark / System appearance, responsive shell, SSR, Swup, auth, home & feed, likes/comments, basic admin
- ✅ **Phase 2 · Signature community** — confession wall, tree hollow, topics & categories, favorites, follows, notification center, moderation, reports, profiles, personalized themes
- ✅ **Continuous improvements** — site-wide search, account security & device management, avatar upload, notification preferences, likes / comments tabs, post editing and link sharing, direct messages, blocking and privacy settings, ban appeals and do-not-disturb hours
- ✅ **Phase 3 · Campus ecosystem** — interest circles, community boards (forum / Q&A / mutual aid / study / lost & found / marketplace), campus events & sign-ups, campus news
- 🚧 **Phase 4 · Advanced** — PWA & offline experience, SSE real-time notifications, browsing history, reposts, video uploads and the backup tool shipped; real-time chat and deeper offline features remain future items

## Contributing

1. Fork the repository and create a feature branch (`feat/xxx`, `fix/xxx`);
2. Follow the code conventions described in the Developer Tutorial (strict types, design tokens, module boundaries);
3. Run `composer test` and `npm test`, and make sure the frontend build passes;
4. Open a pull request describing the motivation and how it was verified.

## Acknowledgements

- [Solara 2.0 / MusicApi](https://github.com/Qiunai-520/MusicApi) — upstream for the music player (`music-app/`)
- Slim Framework, Twig, Vite, Swup
- Material Symbols

## License

Proprietary. Copying, distribution, or commercial use without authorization is not permitted. See the `license` field in `composer.json`.

