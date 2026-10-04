<?php

declare(strict_types=1);

namespace Mofu\Core\Support;

final class Text
{
    /** 相对时间（服务端渲染用） */
    public static function relativeTime(string $datetime): string
    {
        $timestamp = strtotime($datetime);

        if ($timestamp === false) {
            return '';
        }

        $diff = time() - $timestamp;

        return match (true) {
            $diff < 60 => '刚刚',
            $diff < 3600 => floor($diff / 60) . ' 分钟前',
            $diff < 86400 => floor($diff / 3600) . ' 小时前',
            $diff < 86400 * 7 => floor($diff / 86400) . ' 天前',
            default => date('Y-m-d', $timestamp),
        };
    }

    /**
     * 将用户纯文本渲染为安全 HTML：
     * 先整体转义，再支持一个安全的 Markdown 子集
     * （标题 / 引用 / 有序无序列表 / 粗体 / 斜体 / 删除线 / 行内代码 / 链接），
     * 最后识别裸链接并保留换行。注入的 HTML 均在本方法内生成。
     */
    public static function renderContent(string $raw): string
    {
        $escaped = htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');

        // 行内代码优先占位：其内容不再参与其它格式化（用 \x1F 占位，避免与 rtrim/正则冲突）
        $code = [];
        $escaped = preg_replace_callback('~`([^`\n]+)`~u', static function (array $m) use (&$code): string {
            $token = "\x1FC" . count($code) . "\x1F";
            $code[$token] = '<code>' . $m[1] . '</code>';

            return $token;
        }, $escaped) ?? $escaped;

        // 显式链接 [文字](https://…)
        $anchors = [];
        $escaped = preg_replace_callback(
            '~\[([^\]\n]+)\]\((https?://[^\s)]+)\)~u',
            static function (array $m) use (&$anchors): string {
                $token = "\x1FA" . count($anchors) . "\x1F";
                $anchors[$token] = self::link($m[2], $m[1]);

                return $token;
            },
            $escaped,
        ) ?? $escaped;

        // 裸链接自动识别
        $escaped = preg_replace_callback(
            '~(?<![\w"\'=])(https?://[^\s<>"\']+)~u',
            static fn (array $m): string => self::link($m[1]),
            $escaped,
        ) ?? $escaped;

        // 强调
        $escaped = preg_replace('~\*\*([^*\n]+)\*\*~u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('~(?<![\w*])\*([^*\n]+)\*(?![\w*])~u', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('~(?<![\w_])_([^_\n]+)_(?![\w_])~u', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('#~~([^~\n]+)~~#u', '<del>$1</del>', $escaped) ?? $escaped;

        return strtr(self::renderBlocks($escaped), $code + $anchors);
    }

    /** 生成链接（url 已转义，label 可选） */
    private static function link(string $url, ?string $label = null): string
    {
        return sprintf(
            '<a href="%s" target="_blank" rel="nofollow noopener">%s</a>',
            $url,
            $label ?? $url,
        );
    }

    /** 行级块：标题 / 引用 / 列表 / 段落（普通行保持 <br> 换行，与原行为一致） */
    private static function renderBlocks(string $text): string
    {
        $out = '';
        $buffer = [];
        $mode = 'p';

        $flush = static function () use (&$out, &$buffer, &$mode): void {
            if ($buffer === []) {
                $mode = 'p';

                return;
            }

            $out .= match ($mode) {
                'quote' => '<blockquote>' . implode('<br>', $buffer) . '</blockquote>',
                'ul' => '<ul>' . implode('', array_map(static fn (string $i): string => '<li>' . $i . '</li>', $buffer)) . '</ul>',
                'ol' => '<ol>' . implode('', array_map(static fn (string $i): string => '<li>' . $i . '</li>', $buffer)) . '</ol>',
                default => implode('<br>', $buffer) . '<br>',
            };

            $buffer = [];
            $mode = 'p';
        };

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $trimmed = rtrim($line, " \t");

            if (preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $m) === 1) {
                $flush();
                $out .= '<h3>' . $m[2] . '</h3>';
            } elseif (preg_match('/^&gt;\s?(.*)$/u', $trimmed, $m) === 1) {
                if ($mode !== 'quote') {
                    $flush();
                    $mode = 'quote';
                }
                $buffer[] = $m[1];
            } elseif (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $m) === 1) {
                if ($mode !== 'ul') {
                    $flush();
                    $mode = 'ul';
                }
                $buffer[] = $m[1];
            } elseif (preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $m) === 1) {
                if ($mode !== 'ol') {
                    $flush();
                    $mode = 'ol';
                }
                $buffer[] = $m[1];
            } elseif ($trimmed === '') {
                $flush();
            } else {
                if ($mode !== 'p') {
                    $flush();
                }
                $buffer[] = $trimmed;
            }
        }

        $flush();

        return (string) preg_replace('~<br>$~', '', $out);
    }

    /** 摘要（用于列表页，纯文本输出，模板层负责转义） */
    public static function excerpt(string $text, int $length = 140): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return mb_strlen($plain) <= $length ? $plain : mb_substr($plain, 0, $length) . '…';
    }

    /** 话题 slug：保留中英文数字，空白转连字符 */
    public static function slugifyTopic(string $name): string
    {
        $slug = preg_replace('/\s+/u', '-', trim($name)) ?? $name;
        $slug = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $slug) ?? $slug;
        $slug = trim($slug, '-_');

        return $slug === '' ? 'topic-' . substr(md5($name), 0, 8) : mb_strtolower($slug);
    }

    /** 去除话题名两侧的 # 与空白 */
    public static function normalizeTopicName(string $name): string
    {
        return trim(trim($name), "# \t\n\r\0\x0B");
    }
}
