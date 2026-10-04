#!/usr/bin/env python3
"""生成 CJK 字体子集（public/fonts/mofu-cjk-medium.woff2）。

用法：
    python3 scripts/gen-cjk-subset.py

依赖：fonttools（+ brotli）
    apt install -y python3-fonttools python3-brotli
    或 pip install fonttools brotli

说明：
- 源字体 font/QiunaiMedium.ttf 是 127MB 的可变字体（wght 100-900，内部名 PingFang Rounded SC）；
- 子集覆盖 GB2312 全量汉字与符号 + ASCII + 常用标点/全角 + 日文假名，约 7600 字形、woff2 约 1.4MB；
- Ubuntu 自带的 fontTools 4.29 无法对该字体做 instancer 实例化（gvar deltas 断言失败），
  因此这里直接丢弃可变轴表（gvar/fvar/avar/STAT/HVAR/MVAR/cvar），保留字体的默认字重；
- 子集外的生僻字与谚文由 --font-cjk 后续的系统中文字体兜底。
"""
import pathlib
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
SOURCE = ROOT / 'font' / 'QiunaiMedium.ttf'
OUTPUT = ROOT / 'public' / 'fonts' / 'mofu-cjk-medium.woff2'
CHARS_FILE = pathlib.Path('/tmp/mofu-cjk-chars.txt')


def build_charset() -> str:
    chars: set[str] = set()

    # ASCII + Latin-1 补充
    for cp in list(range(0x20, 0x7F)) + list(range(0xA0, 0x100)):
        chars.add(chr(cp))

    # 常用符号：破折号、引号、货币、箭头、几何图形等
    for start, end in [(0x2010, 0x2027), (0x2030, 0x205E), (0x2103, 0x2103), (0x2190, 0x2193), (0x25A0, 0x25CF)]:
        for cp in range(start, end + 1):
            chars.add(chr(cp))

    # CJK 标点、全角字符、兼容字符
    for start, end in [(0x3000, 0x303F), (0xFE30, 0xFE4F), (0xFF01, 0xFF5E), (0xFFE0, 0xFFE6)]:
        for cp in range(start, end + 1):
            chars.add(chr(cp))

    # 日文假名（轻量补充）
    for start, end in [(0x3041, 0x3096), (0x30A1, 0x30FA), (0x30FC, 0x30FE)]:
        for cp in range(start, end + 1):
            chars.add(chr(cp))

    # GB2312 全量（简体中文日常用字与符号）
    for b1 in range(0xA1, 0xFF):
        for b2 in range(0xA1, 0xFF):
            try:
                chars.add(bytes([b1, b2]).decode('gb2312'))
            except UnicodeDecodeError:
                continue

    text = ''.join(sorted(chars))
    CHARS_FILE.write_text(text, encoding='utf-8')

    return text


def main() -> int:
    if not SOURCE.is_file():
        print(f'缺少源字体：{SOURCE}', file=sys.stderr)
        return 1

    text = build_charset()
    print(f'字符集：{len(text)} 个字符 → {CHARS_FILE}')

    OUTPUT.parent.mkdir(parents=True, exist_ok=True)

    command = [
        sys.executable, '-m', 'fontTools.subset', str(SOURCE),
        f'--text-file={CHARS_FILE}',
        '--flavor=woff2',
        f'--output-file={OUTPUT}',
        '--no-hinting',
        '--desubroutinize',
        '--layout-features=kern,liga,calt',
        '--name-IDs=1,2,4,6',
        '--drop-tables+=DSIG,gvar,fvar,avar,STAT,HVAR,MVAR,cvar',
    ]

    print('$', ' '.join(command))
    result = subprocess.run(command, check=False)

    if result.returncode != 0:
        print('子集化失败（检查 fonttools 是否已安装）', file=sys.stderr)
        return result.returncode

    size = OUTPUT.stat().st_size
    print(f'完成：{OUTPUT} （{size / 1024 / 1024:.2f} MB）')

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
