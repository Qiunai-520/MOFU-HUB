/**
 * 构建期生成全站默认主题变量（基于 material-color-utilities）。
 *
 * 运行：node scripts/build-theme.mjs
 * 输出：public/assets/theme-default.json（供服务端首帧内联）
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { generateVars, varsToCss } from '../src-client/theme/generate';

const here = dirname(fileURLToPath(import.meta.url));
// 输出目录：优先环境变量（打包后运行时使用），否则相对脚本所在项目根
const outDir = process.env.MOFU_ASSETS_OUT ?? resolve(here, '..', 'public/assets');

const state = {
  sourceColor: process.env.THEME_DEFAULT_COLOR ?? '#775A0D',
  variant: 'tonalSpot',
  contrast: 'standard',
  scheme: 'auto',
  density: 0,
  motion: 'standard',
};

const vars = generateVars(state);
const css = varsToCss(vars, 'auto', 0);

mkdirSync(outDir, { recursive: true });

writeFileSync(resolve(outDir, 'theme-default.json'), JSON.stringify(vars, null, 2), 'utf8');
writeFileSync(resolve(outDir, 'theme-default.css'), css, 'utf8');

console.log(
  `[theme-gen] light=${Object.keys(vars.light).length} dark=${Object.keys(vars.dark).length} css=${css.length} bytes`,
);
