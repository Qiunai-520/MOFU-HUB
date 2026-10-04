<?php

declare(strict_types=1);

/*
 * 生成站点图标：PWA 图标（192 / 512 / maskable-512 / Apple Touch 180）与 favicon.svg。
 *
 * 统一使用品牌 Logo（根目录 logo.png，透明背景的吉祥物），不重绘、不改设计：
 * 仅在浅色承载底上居中合成——透明 Logo 含黑色描边，需要浅底才能在深色模式下清晰可辨。
 *
 * 用法：php scripts/gen-pwa-icons.php
 */

$root = dirname(__DIR__);
$outDir = $root . '/public/icons';
$source = is_file($root . '/logo.png') ? $root . '/logo.png' : $root . '/public/logo.png';

if (!is_file($source)) {
    fwrite(STDERR, "找不到 Logo 源文件：$source\n");
    exit(1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "无法创建目录：$outDir\n");
    exit(1);
}

/** 承载底色（浅色，保证黑色描边可辨） */
const TILE_BG = [255, 255, 255];
/** 常规图标内 Logo 占比 */
const RATIO_NORMAL = 0.84;
/** maskable 需要安全区，Logo 占比更小 */
const RATIO_MASKABLE = 0.66;

/**
 * 在纯色底上居中合成 Logo，输出不透明 PNG。
 */
function renderIcon(string $source, int $size, float $ratio): \GdImage
{
    $src = imagecreatefrompng($source);

    if ($src === false) {
        fwrite(STDERR, "无法读取 Logo：$source\n");
        exit(1);
    }

    $sw = imagesx($src);
    $sh = imagesy($src);

    $dst = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($dst, TILE_BG[0], TILE_BG[1], TILE_BG[2]);
    imagefilledrectangle($dst, 0, 0, $size, $size, $bg);

    $inner = (int) round($size * $ratio);
    $offset = (int) round(($size - $inner) / 2);

    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, $offset, $offset, 0, 0, $inner, $inner, $sw, $sh);
    imagedestroy($src);

    return $dst;
}

$targets = [
    'icon-192.png' => [192, RATIO_NORMAL],
    'icon-512.png' => [512, RATIO_NORMAL],
    'maskable-512.png' => [512, RATIO_MASKABLE],
    'apple-touch-icon.png' => [180, RATIO_NORMAL],
];

foreach ($targets as $name => [$size, $ratio]) {
    $img = renderIcon($source, $size, $ratio);
    imagepng($img, $outDir . '/' . $name, 9);
    imagedestroy($img);
    echo "生成 public/icons/$name ({$size}px)\n";
}

// favicon.svg：内嵌一张小尺寸 PNG（承载底 + Logo），保持透明外的浅底一致
$faviconPng = $outDir . '/favicon-64.png';
$img = renderIcon($source, 64, RATIO_NORMAL);
imagepng($img, $faviconPng, 9);
imagedestroy($img);

$svg = sprintf(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><image width="64" height="64" href="data:image/png;base64,%s"/></svg>',
    base64_encode((string) file_get_contents($faviconPng)),
);
file_put_contents($root . '/public/favicon.svg', $svg);
unlink($faviconPng);
echo "生成 public/favicon.svg（内嵌 Logo）\n";
