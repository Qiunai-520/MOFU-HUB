#!/usr/bin/env bash
# MOFU社区 — 前端构建脚本
#
# 说明：工作区位于手机共享存储（不支持可执行权限与符号链接），
# 因此 node_modules 与构建过程托管在容器内部目录 /root/mofu-build；
# 每次构建前将源码副本同步到构建目录，产物输出回 public/assets。
#
# 用法：bash bin/fe-build.sh
set -e

WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
BUILD_DIR=/root/mofu-build
NODE_BIN=/opt/node22/bin

mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"

# 同步配置与源码（覆盖式拷贝）
cp "$WORKSPACE/package.json" package.json
cp "$WORKSPACE/vite.config.ts" vite.config.ts
cp "$WORKSPACE/tsconfig.json" tsconfig.json
mkdir -p src-client scripts tests
cp -r "$WORKSPACE/src-client/." src-client/
cp -r "$WORKSPACE/scripts/." scripts/
cp -r "$WORKSPACE/tests/." tests/

# node_modules 缺平台二进制（assets）时也一并补齐权限
export PATH="$NODE_BIN:$PATH"

if [ ! -d node_modules ]; then
    echo "[fe-build] 安装前端依赖 ..."
    npm install --no-bin-links --ignore-scripts --no-audit --no-fund
fi

if [ -d node_modules/@rollup/rollup-linux-arm64-gnu ]; then
    chmod +x node_modules/@rollup/rollup-linux-arm64-gnu/*.node 2>/dev/null || true
fi
if [ -d node_modules/@esbuild/linux-arm64 ]; then
    chmod +x node_modules/@esbuild/linux-arm64/bin/esbuild 2>/dev/null || true
fi

echo "[fe-build] 构建前端资源 ..."
node node_modules/vite/bin/vite.js build --outDir "$WORKSPACE/public/assets" --emptyOutDir

echo "[fe-build] 构建 Service Worker ..."
# esbuild：npm 包在部分环境里只有原生二进制（不是 JS shim），此时必须直接执行；
# 直接用 `node <file>` 会以 “Invalid or unexpected token” 中止整个构建。
ESBUILD_PATH="node_modules/esbuild/bin/esbuild"
ESBUILD_NEEDS_NODE=1
if [ -f "$ESBUILD_PATH" ] && ! head -c 2 "$ESBUILD_PATH" | grep -q '^#!'; then
    if [ -x "node_modules/@esbuild/linux-arm64/bin/esbuild" ]; then
        ESBUILD_PATH="node_modules/@esbuild/linux-arm64/bin/esbuild"
        ESBUILD_NEEDS_NODE=0
    fi
fi

esbuild() {
    if [ "$ESBUILD_NEEDS_NODE" = "1" ]; then
        node "$ESBUILD_PATH" "$@"
    else
        "$ESBUILD_PATH" "$@"
    fi
}

SW_VERSION="$(date +%s)"
esbuild "$WORKSPACE/src-client/sw.ts" \
    --bundle --minify --format=iife --target=es2022 \
    --define:__SW_VERSION__="'$SW_VERSION'" \
    --outfile="$WORKSPACE/public/sw.js"

echo "[fe-build] 刷新开发者文档 ..."
node "$WORKSPACE/scripts/gen-docs.mjs"

echo "[fe-build] 生成版本号与更新文件 ..."
node "$WORKSPACE/scripts/gen-version.mjs"

echo "[fe-build] 完成：$WORKSPACE/public/assets"
