import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();
const manifest = new Set(
  readFileSync(path.join(root, 'src-client/styles/material-symbols-icons.txt'), 'utf8')
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean),
);

function sourceFiles(folder: string, extensions: string[]): string[] {
  return readdirSync(path.join(root, folder), { withFileTypes: true, recursive: true })
    .filter((entry) => entry.isFile() && extensions.some((extension) => entry.name.endsWith(extension)))
    .map((entry) => path.join(entry.parentPath, entry.name));
}

describe('Material Symbols subset', () => {
  it('contains every literal icon used by templates and client markup', () => {
    const iconPattern = /<span\b[^>]*class=["'][^"']*\bmaterial-symbols-outlined\b[^"']*["'][^>]*>\s*([a-z][a-z0-9_]*)\s*<\/span>/gi;
    const missing = new Set<string>();

    for (const file of [
      ...sourceFiles('app/Views', ['.twig']),
      ...sourceFiles('src-client', ['.ts']),
    ]) {
      for (const match of readFileSync(file, 'utf8').matchAll(iconPattern)) {
        const icon = match[1];

        if (icon !== undefined && !manifest.has(icon)) {
          missing.add(`${path.relative(root, file)}: ${icon}`);
        }
      }
    }

    expect([...missing]).toEqual([]);
  });

  it('keeps the generated font small enough for the critical path', () => {
    const size = statSync(path.join(root, 'src-client/styles/material-symbols-subset.woff2')).size;

    expect(size).toBeLessThan(200 * 1024);
  });
});
