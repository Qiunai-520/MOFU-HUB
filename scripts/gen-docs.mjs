#!/usr/bin/env node
/**
 * MOFU社区 — 开发者文档生成器
 *
 * 从源码提取事实数据，刷新 docs/tutorial.md 中由标记包围的「自动区段」：
 *   <!-- AUTO-GEN:<name>:start -->  ...  <!-- AUTO-GEN:<name>:end -->
 * 手写讲解内容不受影响；由 bin/fe-build.sh 在每次构建后自动调用。
 *
 * 用法：node scripts/gen-docs.mjs
 */
import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const TUTORIAL = join(ROOT, 'docs', 'tutorial.md');

const log = (message) => console.log(`[docs] ${message}`);

/** Markdown 表格单元格转义 */
const cell = (value) => String(value ?? '').replace(/\|/g, '\\|').trim();

/** 用新内容替换命名自动区段（标记之间的全部内容） */
function subst(content, name, body) {
  const pattern = new RegExp(
    `(<!-- AUTO-GEN:${name}:start -->)([\\s\\S]*?)(<!-- AUTO-GEN:${name}:end -->)`,
  );

  if (!pattern.test(content)) {
    log(`警告：tutorial.md 缺少区段标记 ${name}，已跳过`);
    return content;
  }

  return content.replace(pattern, `$1\n${body}\n$3`);
}

/** .env.example → 环境变量表（按配置分组） */
function envSection() {
  const file = join(ROOT, '.env.example');

  if (!existsSync(file)) {
    return '_未找到 `.env.example`，无法生成。_';
  }

  const groups = [];
  let current = { title: '基础', rows: [] };
  let desc = '';

  for (const raw of readFileSync(file, 'utf8').split(/\r?\n/)) {
    const line = raw.trim();

    if (line === '') {
      desc = '';
      continue;
    }

    const section = line.match(/^#\s*-+\s*(.+?)\s*-+$/);

    if (section !== null) {
      if (current.rows.length > 0) {
        groups.push(current);
      }

      current = { title: section[1], rows: [] };
      desc = '';
      continue;
    }

    if (line.startsWith('#')) {
      if (!/^#\s*=/.test(line)) {
        desc = line.replace(/^#\s?/, '');
      }
      continue;
    }

    const match = line.match(/^([A-Z][A-Z0-9_]*)=(.*)$/);

    if (match === null) {
      continue;
    }

    const value = match[2].trim();
    current.rows.push(
      `| \`${match[1]}\` | ${cell(desc || '—')} | ${value === '' ? '（留空）' : `\`${cell(value)}\``} |`,
    );
    desc = '';
  }

  if (current.rows.length > 0) {
    groups.push(current);
  }

  const total = groups.reduce((count, group) => count + group.rows.length, 0);
  const parts = [`共 ${total} 项。配置读取逻辑见 \`app/Bootstrap/Config.php\`，未配置时使用默认值。`];

  for (const group of groups) {
    parts.push('', `**${group.title}**`, '', '| 变量 | 说明 | 示例 / 默认值 |', '| --- | --- | --- |', ...group.rows);
  }

  return parts.join('\n');
}

/** 解析各模块 routes.php → 路由表 */
function routesSection() {
  const dir = join(ROOT, 'app', 'Modules');

  if (!existsSync(dir)) {
    return '_未找到 `app/Modules`，无法生成。_';
  }

  const rows = [];
  let total = 0;

  for (const module of readdirSync(dir).sort()) {
    const file = join(dir, module, 'routes.php');

    if (!existsSync(file)) {
      continue;
    }

    let prefix = null;
    let depth = 0;

    for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
      const group = line.match(/->group\(\s*'([^']*)'/);

      if (group !== null) {
        prefix = group[1];
        depth = 0;
      }

      if (prefix !== null) {
        depth += (line.match(/{/g)?.length ?? 0) - (line.match(/}/g)?.length ?? 0);
      }

      const route = line.match(/\$(app|group)->(get|post|put|patch|delete)\(\s*'([^']+)'/);

      if (route !== null) {
        const path = route[1] === 'group' && prefix !== null ? prefix + route[3] : route[3];
        const handler = line.match(/->get\((\w+)::class\)->(\w+)/);
        rows.push(
          `| ${module} | \`${route[2].toUpperCase()}\` | \`${path}\` | ${handler ? `\`${handler[1]}::${handler[2]}()\`` : '—'} |`,
        );
        total += 1;
      }

      if (prefix !== null && group === null && depth <= 0) {
        prefix = null;
      }
    }
  }

  return [
    `共 ${total} 条路由。约定：页面路由直接返回 HTML（SSR）；\`/api/v1\` 前缀为 JSON 接口，由前端模块异步调用。`,
    '',
    '| 模块 | 方法 | 路径 | 处理器 |',
    '| --- | --- | --- | --- |',
    ...rows,
  ].join('\n');
}

/** migrations/*.sql → 数据表清单 */
function tablesSection() {
  const dir = join(ROOT, 'migrations');

  if (!existsSync(dir)) {
    return '_未找到 `migrations`，无法生成。_';
  }

  const rows = [];
  let total = 0;

  for (const name of readdirSync(dir).sort()) {
    if (!name.endsWith('.sql')) {
      continue;
    }

    const sql = readFileSync(join(dir, name), 'utf8');
    const tables = [...sql.matchAll(/CREATE TABLE(?:\s+IF NOT EXISTS)?\s+`?([A-Za-z0-9_]+)`?/gi)].map(
      (match) => match[1],
    );

    total += tables.length;
    rows.push(
      tables.length > 0
        ? `| \`${name}\` | ${tables.map((table) => `\`${table}\``).join('、')} |`
        : `| \`${name}\` | （结构 / 数据变更，无新表） |`,
    );
  }

  return [`共 ${total} 张数据表，按迁移顺序创建。`, '', '| 迁移文件 | 创建的数据表 |', '| --- | --- |', ...rows].join(
    '\n',
  );
}

/** src-client/main.ts → 前端页面模块表 */
function frontendModulesSection() {
  const file = join(ROOT, 'src-client', 'main.ts');

  if (!existsSync(file)) {
    return '_未找到 `src-client/main.ts`，无法生成。_';
  }

  const rows = [];

  for (const match of readFileSync(file, 'utf8').matchAll(/(\w+):\s*\(\)\s*=>\s*import\('\.\/modules\/([\w-]+)'\)/g)) {
    rows.push(`| \`${match[1]}\` | \`src-client/modules/${match[2]}.ts\` |`);
  }

  return ['| 页面标识（`data-page`） | 客户端模块 |', '| --- | --- |', ...rows].join('\n');
}

/** 目录树注释（键为相对路径） */
const TREE_NOTES = {
  app: 'PHP 应用（Bootstrap / Core / Install / Modules / Views）',
  'app/Bootstrap': '启动、容器、配置与错误处理',
  'app/Core': '框架内核：数据库、HTTP、安全、视图',
  'app/Install': 'Web 安装向导（门禁 / 环境自检 / .env 生成）',
  'app/Modules': '业务模块',
  'app/Views': 'Twig 模板（layouts / components / pages）',
  bin: '命令行工具（迁移、构建、开发启动）',
  docs: '项目文档（本教程 + 双语 README）',
  'docs/i18n': 'README 多语言版本',
  migrations: '数据库迁移（按序执行的 SQL 文件）',
  public: 'Web 根目录（单入口 + 静态资源）',
  'public/assets': '前端构建产物（自动生成）',
  'public/uploads': '用户上传文件（运行期生成）',
  scripts: '工程脚本（构建与文档生成）',
  'src-client': '前端 TypeScript 源码',
  'src-client/core': '前端内核：API、状态、导航与全局交互',
  'src-client/modules': '页面级客户端模块（懒加载）',
  'src-client/styles': '样式（基础 / 布局 / 页面）',
  'src-client/theme': 'HCT 动态配色与主题引擎',
  storage: '运行时数据（日志 / 缓存，部署时需可写）',
  'storage/cache': '缓存（速率限制等）',
  'storage/logs': '应用与迁移日志',
  tests: '测试（PHPUnit + Vitest）',
  'tests/Unit': '后端单元测试',
  'tests/Feature': '后端集成测试（完整 HTTP 链路，需数据库）',
  'tests/frontend': '前端单元测试（Vitest）',
  '.env.example': '环境配置样例（复制为 .env 使用）',
};

/** 项目目录树（根目录 + app、src-client 展开一层） */
function treeSection() {
  const SKIP = new Set(['node_modules', 'vendor', '.git', '.aicode', '.phpunit.cache', 'package-lock.json', 'composer.lock', 'deploy', 'mofu-check.php']);
  const out = [];

  const listDir = (rel) => {
    const abs = rel === '' ? ROOT : join(ROOT, rel);
    return readdirSync(abs, { withFileTypes: true })
      .filter(
        (entry) =>
          !SKIP.has(entry.name) &&
          (!entry.name.startsWith('.') || entry.name === '.env.example' || entry.name === '.gitignore'),
      )
      .sort((a, b) => (a.isDirectory() === b.isDirectory() ? (a.name < b.name ? -1 : 1) : a.isDirectory() ? -1 : 1));
  };

  const render = (rel, prefix, recurse) => {
    const entries = listDir(rel);

    entries.forEach((entry, index) => {
      const last = index === entries.length - 1;
      const relChild = rel === '' ? entry.name : `${rel}/${entry.name}`;
      const isDir = entry.isDirectory();
      const note = TREE_NOTES[relChild];
      out.push(`${prefix}${last ? '└─' : '├─'} ${entry.name}${isDir ? '/' : ''}${note ? `  # ${note}` : ''}`);

      if (isDir && recurse.has(relChild)) {
        render(relChild, `${prefix}${last ? '   ' : '│  '}`, new Set());
      }
    });
  };

  render('', '', new Set(['app', 'src-client']));

  return ['```text', ...out, '```'].join('\n');
}

/** public/assets → 最近一次构建产物清单 */
function buildSection() {
  const dir = join(ROOT, 'public', 'assets');

  if (!existsSync(dir)) {
    return '_未找到 `public/assets`，请先运行 `bash bin/fe-build.sh`。_';
  }

  const classify = (name) => {
    if (name === 'manifest.json') return '构建清单（`ViteAssets` 读取）';
    if (/^main-.*\.js$/.test(name)) return '应用入口 JS（含 M3E 组件与全站交互）';
    if (/^main-.*\.css$/.test(name)) return '全站样式（含字体声明）';
    if (/^theme-default\./.test(name)) return '默认主题产物（`ThemeBootstrap` 读取）';
    if (/material-symbols/.test(name)) return '图标字体：Material Symbols';
    if (/\.woff2?$/.test(name)) return '字体：Roboto';
    return '页面模块懒加载 chunk';
  };
  const weight = (name) => {
    if (/^main-.*\.(js|css)$/.test(name)) return 0;
    if (name === 'manifest.json') return 1;
    if (/^theme-default\./.test(name)) return 2;
    if (/\.woff2?$/.test(name)) return 3;
    return 4;
  };

  const files = readdirSync(dir).sort((a, b) => weight(a) - weight(b) || (a < b ? -1 : 1));
  const rows = files.map((name) => {
    const kb = statSync(join(dir, name)).size / 1024;
    const size = kb >= 1024 ? `${(kb / 1024).toFixed(1)} MB` : `${kb.toFixed(1)} KB`;
    return `| \`${name}\` | ${classify(name)} | ${size} |`;
  });

  const built = statSync(join(dir, 'manifest.json')).mtime.toLocaleString('zh-CN', { hour12: false });

  return [`最近构建时间：${built}`, '', '| 文件 | 说明 | 大小 |', '| --- | --- | --- |', ...rows].join('\n');
}

function main() {
  if (!existsSync(TUTORIAL)) {
    log('警告：未找到 docs/tutorial.md，跳过');
    return;
  }

  let content = readFileSync(TUTORIAL, 'utf8');
  content = subst(content, 'env', envSection());
  content = subst(content, 'tables', tablesSection());
  content = subst(content, 'routes', routesSection());
  content = subst(content, 'frontend-modules', frontendModulesSection());
  content = subst(content, 'tree', treeSection());
  content = subst(content, 'build', buildSection());
  writeFileSync(TUTORIAL, content, 'utf8');

  log(`已刷新 docs/tutorial.md（${new Date().toLocaleString('zh-CN', { hour12: false })}）`);
}

try {
  main();
} catch (error) {
  log(`生成失败（不影响构建）：${error instanceof Error ? error.message : String(error)}`);
}
