#!/usr/bin/env node
/**
 * 构建时自动生成版本号与更新文件。
 *
 * - `VERSION`（仓库根）：当前版本号，语义化；不存在时以 1.0.0 起步。
 * - 每次执行把 patch 位 +1，并在 `CHANGELOG.md` 与 `public/version.json` 追加一条
 *   「版本 + 日期 + 本次改动的文件清单（来自 git status，取不到时退化为构建时间）」。
 * - `public/version.json` 随发布包分发，前台「更新日志」在没有自定义内容时用它渲染。
 *
 * 用法：node scripts/gen-version.mjs
 */
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const VERSION_FILE = join(ROOT, 'VERSION');
const CHANGELOG_FILE = join(ROOT, 'CHANGELOG.md');
const PUBLIC_JSON = join(ROOT, 'public', 'version.json');

const MAX_FILES = 40;

function readVersion() {
  if (!existsSync(VERSION_FILE)) {
    return '1.0.0';
  }

  const raw = readFileSync(VERSION_FILE, 'utf8').trim();
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(raw);

  return match === null ? '1.0.0' : raw;
}

function bump(version) {
  const [major, minor, patch] = version.split('.').map((part) => Number.parseInt(part, 10));

  return `${major}.${minor}.${patch + 1}`;
}

function changedFiles() {
  try {
    const out = execFileSync('git', ['status', '--porcelain'], { cwd: ROOT, encoding: 'utf8' });
    const files = out
      .split('\n')
      .map((line) => line.slice(3).trim())
      .filter((line) => line !== '');

    return files;
  } catch {
    return [];
  }
}

function readHistory() {
  if (!existsSync(PUBLIC_JSON)) {
    return [];
  }

  try {
    const parsed = JSON.parse(readFileSync(PUBLIC_JSON, 'utf8'));

    return Array.isArray(parsed.history) ? parsed.history : [];
  } catch {
    return [];
  }
}

function nowIso() {
  const d = new Date();
  const pad = (n) => String(n).padStart(2, '0');

  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function today() {
  return nowIso().slice(0, 10);
}

/** 从上一次版本以来改动过的文件生成一句摘要 */
function summarize(files) {
  if (files.length === 0) {
    return '构建产物更新（未检测到工作区改动）';
  }

  const shown = files.slice(0, MAX_FILES);
  const more = files.length > MAX_FILES ? `\n  - …等 ${files.length} 处改动` : '';

  return shown.map((file) => `  - ${file}`).join('\n') + more;
}

const current = readVersion();
const files = changedFiles();
const summary = summarize(files);
const history = readHistory();

// 首次构建（该版本还未生成过产物）保持当前版本；同一版本重复构建才 +patch
const next = history[0]?.version === current ? bump(current) : current;

history.unshift({ version: next, date: today(), built_at: nowIso(), summary });

writeFileSync(VERSION_FILE, `${next}\n`);

writeFileSync(
  PUBLIC_JSON,
  `${JSON.stringify({ version: next, built_at: nowIso(), history: history.slice(0, 50) }, null, 2)}\n`,
);

const entry = `## v${next} · ${today()}\n\n${summary}\n`;
const header = '# 更新日志\n\n> 由 `scripts/gen-version.mjs` 在每次构建时自动生成。\n\n';

const previous = existsSync(CHANGELOG_FILE)
  ? readFileSync(CHANGELOG_FILE, 'utf8').replace(/^# 更新日志[\s\S]*?\n\n/, '')
  : '';

writeFileSync(CHANGELOG_FILE, `${header}${entry}\n${previous}`);

console.log(`[version] ${current} -> ${next}（改动 ${files.length} 处）`);