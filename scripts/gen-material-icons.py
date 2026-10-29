#!/usr/bin/env python3
"""Build a small Material Symbols font containing only icons used by the app.

The package ships a 3.8 MB variable icon font. Loading it on every page makes
the icon asset larger than all JavaScript and CSS combined. This script keeps
the ligatures used by templates and client-rendered markup while preserving the
same CSS API (`.material-symbols-outlined`).

Requirements:
    python -m pip install fonttools brotli

Usage:
    python scripts/gen-material-icons.py
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

from fontTools import subset
from fontTools.ttLib import TTFont

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'node_modules' / 'material-symbols' / 'material-symbols-outlined.woff2'
OUTPUT = ROOT / 'src-client' / 'styles' / 'material-symbols-subset.woff2'
ICONS_FILE = ROOT / 'src-client' / 'styles' / 'material-symbols-icons.txt'

SPAN_RE = re.compile(
    r'<span\b[^>]*class=["\'][^"\']*\bmaterial-symbols-outlined\b[^"\']*["\'][^>]*>'
    r'(.*?)</span>',
    re.DOTALL | re.IGNORECASE,
)
TOKEN_RE = re.compile(r'\b[a-z][a-z0-9_]*\b')
ICON_DEFAULT_RE = re.compile(
    r"(?:icon\s+VARCHAR\([^)]*\)\s+DEFAULT|['\"]icon['\"]\s*=>|icon\s*:)"
    r"\s*['\"]([a-z][a-z0-9_]*)['\"]",
    re.IGNORECASE,
)

GLYPH_TOKEN_REPLACEMENTS = {
    'underscore': '_',
    **{f'digit_{word}': str(number) for number, word in enumerate(
        ('zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'),
    )},
}


def glyph_name_to_icon_part(value: str) -> str:
    return GLYPH_TOKEN_REPLACEMENTS.get(value, value)


def source_files() -> list[Path]:
    files: list[Path] = []

    for folder, patterns in (
        (ROOT / 'app', ('*.php', '*.twig')),
        (ROOT / 'src-client', ('*.ts',)),
        (ROOT / 'migrations', ('*.sql',)),
    ):
        for pattern in patterns:
            files.extend(folder.rglob(pattern))

    return files


def collect_icons() -> list[str]:
    icons: set[str] = set()

    for path in source_files():
        text = path.read_text(encoding='utf-8')

        for match in SPAN_RE.finditer(text):
            content = match.group(1)

            # Literal icon ligatures, plus every quoted branch of Twig/TS
            # ternaries such as `{{ isHole ? 'forest' : 'favorite' }}`.
            for token in TOKEN_RE.findall(content):
                icons.add(token)

        # Data-driven icons are rendered through `{{ item.icon }}`; collect
        # seed/default values and model fallbacks so the subset remains safe.
        icons.update(ICON_DEFAULT_RE.findall(text))

        # Existing icon values live in migration INSERT rows. Broad candidate
        # collection is safe because names absent from Material's ligature
        # table are filtered below.
        if path.suffix == '.sql':
            icons.update(re.findall(r"['\"]([a-z][a-z0-9_]*)['\"]", text))

    # Keep the empty/broken state usable even if a template changes.
    icons.update({'help', 'info', 'check', 'chevron_right', 'close'})

    return sorted(icons)


def main() -> int:
    if not SOURCE.is_file():
        print(f'缺少源图标字体：{SOURCE}', file=sys.stderr)
        return 1

    candidates = collect_icons()

    if len(candidates) < 20:
        print('扫描到的图标数量异常，拒绝生成不完整的字体。', file=sys.stderr)
        return 1

    source_font = TTFont(str(SOURCE))
    ligature_glyphs: dict[str, str] = {}

    for lookup in source_font['GSUB'].table.LookupList.Lookup:
        for subtable in lookup.SubTable:
            extension = getattr(subtable, 'ExtSubTable', None)
            ligatures = getattr(extension, 'ligatures', None)

            if ligatures is None:
                continue

            for first, rows in ligatures.items():
                for row in rows:
                    icon_name = ''.join(
                        glyph_name_to_icon_part(part)
                        for part in (first, *row.Component)
                    )
                    ligature_glyphs[icon_name] = row.LigGlyph

    icons = [name for name in candidates if name in ligature_glyphs]
    missing = sorted(set(candidates) - set(icons))
    target_glyphs = sorted({ligature_glyphs[name] for name in icons})

    if len(icons) < 20:
        print('有效图标数量异常，拒绝生成不完整的字体。', file=sys.stderr)
        return 1

    source_font.close()

    ICONS_FILE.parent.mkdir(parents=True, exist_ok=True)
    ICONS_FILE.write_text('\n'.join(icons), encoding='utf-8')
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)

    options = subset.Options()
    options.flavor = 'woff2'
    options.no_hinting = True
    options.desubroutinize = True
    options.layout_features = ['rclt', 'rlig']
    options.layout_closure = False
    options.name_IDs = ['*']
    options.name_languages = ['*']
    options.name_legacy = True

    font = subset.load_font(str(SOURCE), options)

    try:
        subsetter = subset.Subsetter(options=options)
        subsetter.populate(text=''.join(icons), glyphs=target_glyphs)
        subsetter.subset(font)
        subset.save_font(font, str(OUTPUT), options)
    finally:
        font.close()

    original = SOURCE.stat().st_size
    reduced = OUTPUT.stat().st_size
    saved = 100 * (1 - reduced / original)

    print(f'图标：{len(icons)} 个')
    if missing:
        print(f'忽略无效候选：{", ".join(missing)}')
    print(f'源字体：{original / 1024 / 1024:.2f} MB')
    print(f'子集：  {reduced / 1024:.1f} KB（减少 {saved:.1f}%）')
    print(f'输出：  {OUTPUT.relative_to(ROOT)}')

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
