<h1 align="center">MOFU Community</h1>

> A modern campus community platform built on Google Material 3 Expressive

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MySQL%20%2F%20MariaDB-8.0%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL / MariaDB">
  <img src="https://img.shields.io/badge/TypeScript-5.6-3178C6?logo=typescript&logoColor=white" alt="TypeScript">
  <img src="https://img.shields.io/badge/Vite-5-646CFF?logo=vite&logoColor=white" alt="Vite 5">
  <img src="https://img.shields.io/badge/Material%203%20Expressive-%40m3e%2Fweb-6750A4" alt="Material 3 Expressive">
  <img src="https://img.shields.io/badge/License-Proprietary-lightgrey" alt="License: Proprietary">
</p>

<p align="center">
  <a href="../../README.md">中文</a> · <b>English</b>
</p>

---

MOFU Community is a comprehensive campus community platform: a campus feed, an anonymous confession wall and "tree hollow", follows and notifications, a personalized theme center, content moderation, and an admin console. The backend is a PHP + Slim SSR architecture; the frontend pairs TypeScript with Swup for an app shell and smooth page transitions. The whole design system follows Google's **Material 3 Expressive (M3E)** guidelines.

## Features

- **Campus feed** — rich posts (up to 9 images), editing, deletion, topic tags, public / private visibility; four streams: recommended, latest, hot, following
- **Interactions** — likes, favorites, comments and replies, content reports, copy-link sharing; optimistic updates with server-side validation and duplicate protection
- **Follows** — follow / unfollow, follower & following lists, follow suggestions
- **Site-wide search** — one query across posts, users, topics and confessions; permission-filtered (private content and banned accounts never leak), search history, hot topics
- **Anonymous boards** — confession wall and "tree hollow": fully anonymous or nickname-signed submissions (guests can post anonymously too), categories, moderation queue, anonymous comments and likes, "my submissions" status tracking
- **Notification center** — interaction notifications, unread badge, mark-all-as-read
- **Profiles** — posts, favorites, likes, comments, followers / following tabs; profile editing and avatar upload
- **Account & security** — password change, login device management (revoke a single device or all other devices), notification preferences (likes / comments / follows toggles + do-not-disturb hours), privacy settings (DM policy and list visibility)
- **Ban appeals** — suspended or banned accounts can appeal from the appeal page; once approved by an admin the account is restored automatically and notified; bans take effect immediately (existing sessions are invalidated)
- **Direct messages** — one-to-one conversations: conversation list, unread badges, message history, instant delivery while SSE is connected (8-second polling fallback retained)
- **Blocking** — blocking prevents follows and DMs in both directions and removes existing follow relations; manage the block list in privacy settings
- **Interest circles** — create / join circles (open or approval-based); in-circle posts reuse the post system (images / topics / comments / likes) and are visible to members only, isolated from the main feed, search and public profiles; membership approval & removal, owner-editable circle settings
- **Community boards** — one framework for forum, Q&A, mutual aid, study, lost & found and marketplace; Q&A / mutual-aid posts can be marked "solved", marketplace listings "sold", lost & found distinguishes lost / found reports; posts support likes, favorites and replies, with per-board status filters and latest / hot sorting
- **Campus events** — create events (time / location / capacity / optional cover image, reusing the upload & compression pipeline); sign-ups are either open or approval-based (organizers can approve / reject / remove), with capacity and deadline guards; browse by upcoming / ongoing / past, organizers can edit, cancel (notifying signed-up members) and delete; "My events" aggregates joined and organized events
- **Campus news** — an editorial channel for admins (notices / campus news / study resources): drafts & publishing, pinning, cover image, view counter; first publish broadcasts a notification to all active users (idempotent, never re-sent); admin console supports publish / hide / pin / edit / delete
- **PWA & offline** — installable to the home screen (Web App Manifest + adaptive & Apple Touch icons); a Service Worker caches build artifacts and images and falls back to an offline page when the network is gone; `sw.js` is version-stamped on every build with layered caching (precache / hashed assets / FIFO-capped user media)
- **Reposts** — one-tap repost from the post detail into your own feed (undoable): a quote card shows the original author with excerpt/thumbnail, or a placeholder once the original is gone; repost counter plus a "reposted your post" notification; private and in-circle posts cannot be reposted
- **Browsing history** — posts you viewed are recorded automatically (deduplicated to the latest visit); `/history` lets you review and clear them; deleted content and in-circle posts never appear
- **Real-time notifications** — an SSE stream pushes unread badges (notifications + DMs) with automatic reconnect and a 30-second polling fallback; the messages page pulls new messages immediately on stream events (dev servers need multiple workers, see the tutorial)
- **Dynamic video** — posts can carry a single uploaded video (MP4 / WebM, no transcoding, capped by `UPLOAD_VIDEO_MAX_MB`) and cannot mix with images; native player in feed and detail views (constrained by your PHP upload limits)
- **Backup tool** — `php bin/backup.php` dumps the database in pure PHP and archives uploads (no mysqldump needed), with retention pruning and documented restore steps
- **Theme center** — HCT dynamic color (8 palettes), light / dark, contrast, density and motion settings, wallpaper color extraction, multiple saved themes; guests can try themes locally
- **M3E experience** — Swup-powered navigation without full reloads, M3E loading animations (buttons / forms / page transitions), responsive app shell (desktop side nav + mobile bottom nav & FAB), Material Symbols icons
- **Admin console** — dashboard, users (status & roles), posts, comments, confession moderation, report handling, appeal handling, circle management, board management, event management, news management
- **Security** — database-backed sessions, site-wide CSRF, permission-point based access control, rate limiting, audit logs, upload validation

## Tech Stack

| Layer | Technology |
| --- | --- |
| Backend | PHP ≥ 8.1 · Slim 4 · Twig 3 · vlucas/phpdotenv |
| Database | MySQL / MariaDB (utf8mb4) · built-in migrator |
| Frontend | TypeScript (strict) · Vite 5 · Swup 4 · @m3e/web |
| Design system | Material 3 Expressive · HCT dynamic color · Material Symbols · Roboto |
| Testing | PHPUnit 10 · Vitest 2 |

## Quick Start

> Requirements: PHP ≥ 8.1 (`pdo_mysql`, `mbstring`, `json`, `gd`), Composer, Node.js ≥ 20, MySQL / MariaDB.

> Shared hosting without CLI: just upload the code and open your site — the `/install` wizard handles environment checks, database setup, in-page migrations and the first super-admin account, then locks itself. The steps below are for local development:

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
| `bash bin/dev-start.sh` | Start MariaDB + dev server together |
| `php bin/migrate.php migrate \| status \| rollback` | Database migrations / status / rollback |
| `npm run build` | Build frontend (Vite) |
| `bash bin/fe-build.sh` | Full build: frontend + Service Worker + theme artifacts + docs refresh |
| `php bin/backup.php` | Back up the database and uploads (defaults to `storage/backups/`; supports `--skip-uploads` / `--keep=N`) |
| `npm run dev` | Vite dev server (HMR, with `VITE_DEV_SERVER` in `.env`) |
| `npm run docs` | Refresh auto-generated sections of the tutorial |
| `composer test` | Backend tests (PHPUnit) |
| `npm test` | Frontend tests (Vitest) |

## Project Structure

```text
app/           PHP application (Bootstrap / Core / Modules / Views)
bin/           CLI tools (migrations, build, dev start)
docs/          Documentation (developer tutorial + bilingual README)
migrations/    Database migrations
public/        Web root (single entry + build output + uploads)
scripts/       Engineering scripts (theme artifacts, docs generator)
src-client/    Frontend TypeScript source
storage/       Runtime data (logs / cache)
tests/         Tests (PHPUnit + Vitest)
```

## Documentation

- [Developer Tutorial](../tutorial.md) — environment setup, architecture, routes & modules, building & deployment, FAQ (some sections auto-refresh on build; currently in Chinese)
- [中文 README](../../README.md)

## Roadmap

- ✅ **Phase 1 · Foundation** — architecture, M3E design system, dynamic color, responsive shell, SSR, Swup, auth, home & feed, likes/comments, basic admin
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

- [m3e](https://github.com/matraic/m3e) ([docs](https://matraic.github.io/m3e)) — Material 3 Expressive Web Components
- Slim Framework, Twig, Vite, Swup
- Material Symbols, Roboto, Material Color Utilities

## License

Proprietary. Copying, distribution, or commercial use without authorization is not permitted. See the `license` field in `composer.json`.
