<?php

declare(strict_types=1);

namespace Mofu\Modules\Settings;

/**
 * 首页轮播图：管理员在后台按行维护，存于 system_settings（JSON）。
 *
 * 成员字段：title / summary / link / image（图片 URL）/ type（image|scene3d）。
 * type=scene3d 时首页使用统一的「进入 3D 场景」按钮；link 指向任意场景页。
 * 未配置时 decode() 返回空数组，首页会回退到「置顶资讯」那套默认轮播。
 */
final class HomeBanners
{
    public const KEY = 'site.home_banners';

    private const MAX_ITEMS = 8;
    private const MAX_TITLE = 60;
    private const MAX_SUMMARY = 120;
    private const MAX_LINK = 255;
    private const MAX_IMAGE = 255;

    /**
     * @return list<array{title: string, summary: string, link: string, image: string, type: string, target: string}>
     */
    public static function decode(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $items = [];

        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = self::item(
                (string) ($row['title'] ?? ''),
                (string) ($row['summary'] ?? ''),
                (string) ($row['link'] ?? ''),
                (string) ($row['image'] ?? ''),
                (string) ($row['target'] ?? 'self'),
                (string) ($row['type'] ?? 'image'),
            );

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<int|string, mixed> $titles
     * @param array<int|string, mixed> $summaries
     * @param array<int|string, mixed> $links
     * @param array<int|string, mixed> $images
     *
     * @return list<array{title: string, summary: string, link: string, image: string, type: string, target: string}>
     */
    public static function fromRows(array $titles, array $summaries, array $links, array $images, array $targets = [], array $types = []): array
    {
        $items = [];

        foreach (array_values($titles) as $i => $rawTitle) {
            $item = self::item(
                (string) $rawTitle,
                (string) ($summaries[$i] ?? ''),
                (string) ($links[$i] ?? ''),
                (string) ($images[$i] ?? ''),
                (string) ($targets[$i] ?? 'self'),
                (string) ($types[$i] ?? 'image'),
            );

            if ($item === null) {
                continue;
            }

            $items[] = $item;

            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param list<array{title: string, summary: string, link: string, image: string, type: string, target: string}> $items
     */
    public static function encode(array $items): string
    {
        return (string) json_encode($items, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 归一化一条轮播：标题与图片都为空视为空行（整行丢弃）。
     *
     * @return array{title: string, summary: string, link: string, image: string, type: string, target: string}|null
     */
    private static function item(string $title, string $summary, string $link, string $image, string $target = 'self', string $type = 'image'): ?array
    {
        $title = mb_substr(trim($title), 0, self::MAX_TITLE);
        $summary = mb_substr(trim($summary), 0, self::MAX_SUMMARY);
        $image = trim($image);
        $link = trim($link);
        $target = in_array($target, ['self', 'blank', 'none'], true) ? $target : 'self';
        $type = in_array($type, ['image', 'scene3d'], true) ? $type : 'image';

        if ($title === '' && $image === '') {
            return null;
        }

        // 没有链接的轮播图只能“仅展示”
        if ($link === '') {
            $target = 'none';
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'link' => self::safeUrl($link, self::MAX_LINK, true),
            'image' => self::safeUrl($image, self::MAX_IMAGE, false),
            'target' => $target,
            'type' => $type,
        ];
    }

    /** 链接允许站内相对路径或 http(s)；图片只允许 /uploads 或 http(s) */
    private static function safeUrl(string $url, int $maxLength, bool $allowRelative): string
    {
        if ($url === '') {
            return '';
        }

        if ($allowRelative && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return mb_substr($url, 0, $maxLength);
        }

        if (
            !str_starts_with($url, '/uploads/')
            && !str_starts_with($url, '/images/')
            && preg_match('#^https?://#i', $url) !== 1
        ) {
            return '';
        }

        return mb_substr($url, 0, $maxLength);
    }
}
