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
SW_VERSION="$(date +%s)"
node node_modules/esbuild/bin/esbuild "$WORKSPACE/src-client/sw.ts" \
    --bundle --minify --format=iife --target=es2022 \
    --define:__SW_VERSION__="'$SW_VERSION'" \
    --outfile="$WORKSPACE/public/sw.js"

echo "[fe-build] 生成默认主题变量 ..."
node node_modules/esbuild/bin/esbuild scripts/build-theme.mjs \
    --bundle --platform=node --format=esm --outfile="$BUILD_DIR/.build-theme.mjs" >/dev/null
MOFU_ASSETS_OUT="$WORKSPACE/public/assets" node "$BUILD_DIR/.build-theme.mjs"

echo "[fe-build] 刷新开发者文档 ..."
node "$WORKSPACE/scripts/gen-docs.mjs"

echo "[fe-build] 完成：$WORKSPACE/public/assets"
