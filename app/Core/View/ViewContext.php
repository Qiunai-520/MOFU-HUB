<?php

declare(strict_types=1);

namespace Mofu\Core\View;

use Mofu\Bootstrap\Config;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Mofu\Modules\Users\ProfileDecorations;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 页面公共上下文的组装。
 */
final class ViewContext
{
    /**
     * 收起悬浮主导航（并隐藏发布/音乐悬浮按钮）的路由前缀。
     *
     * 这里是**唯一事实来源**：服务端据此在 <html> 上渲染 data-nav-compact，
     * 客户端（core/nav.ts）从 window.__MOFU__.navPrimaryPaths 读同一份，
     * swup 切页时据此增删属性。新增/调整只改这一处。
     *
     * 判断标准反过来列：**悬浮底部导航上的四个一级目的地**（外加消息页的通知标签页）
     * 才保留悬浮导航与发布/音乐悬浮按钮，其余页面一律收起 —— 二级页不重复提供入口，
     * 也避免悬浮按钮挡住页面自己的操作区。
     */
    private const NAV_PRIMARY_PATHS = ['/', '/boards', '/messages', '/notifications', '/me'];

    /** @return array<string, mixed> */
    public static function base(ServerRequestInterface $request, Config $config): array
    {
        $state = Ctx::state($request);
        $path = $request->getUri()->getPath();

        return [
            'app' => [
                'name' => (string) $config->get('app.name', 'MOFU社区'),
                'url' => rtrim((string) $config->get('app.url', ''), '/'),
                'version' => self::version($config),
            ],
            'upload' => [
                'maxMb' => (int) $config->get('upload.max_mb', 8),
                'videoMaxMb' => (int) $config->get('upload.video_max_mb', 60),
            ],
            'currentUser' => self::presentUser($state->user),
            // 评论系统（Twikoo）：envId 为空时前端完全不加载外部脚本，直接用站内评论
            'twikooEnvId' => $config->get('twikoo.env_id', ''),
            'twikooScriptUrl' => $config->get('twikoo.script_url', '/vendor/twikoo.all.min.js'),
            'twikooLang' => $config->get('twikoo.lang', 'zh-CN'),
            'twikooEmotionCdn' => (static function () use ($config): string {
                $cdn = (string) $config->get('twikoo.emotion_cdn', '');
                if ($cdn !== '' && !str_starts_with($cdn, 'http://') && !str_starts_with($cdn, 'https://')) {
                    return rtrim((string) $config->get('app.url', ''), '/') . '/' . ltrim($cdn, '/');
                }

                return $cdn;
            })(),
            'csrfToken' => $state->csrfToken(),
            'currentPath' => $path,
            'navCompact' => self::isNavCompact($path),
            'navPrimaryPaths' => self::NAV_PRIMARY_PATHS,
            'musicAppUrl' => (string) $config->get('music.app_url', ''),
        ];
    }

    /** 当前版本号（读仓库根 VERSION，构建脚本每次构建写入） */
    private static function version(Config $config): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $path = rtrim((string) $config->get('root', ''), '/') . '/VERSION';
        $raw = is_file($path) ? trim((string) file_get_contents($path)) : '';
        $cached = $raw === '' ? '1.0.0' : $raw;

        return $cached;
    }

    /** 当前路径是否属于「收起悬浮导航」的页面（非一级目的地都收起） */
    private static function isNavCompact(string $path): bool
    {
        $normalized = rtrim($path, '/');
        $normalized = $normalized === '' ? '/' : $normalized;

        return !in_array($normalized, self::NAV_PRIMARY_PATHS, true);
    }

    /**
     * 将用户记录转换为模板使用的展示结构。
     *
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>|null
     */
    public static function presentUser(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $nickname = trim((string) ($user['nickname'] ?? '')) !== ''
            ? (string) $user['nickname']
            : (string) $user['username'];

        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'nickname' => $nickname,
            'email' => (string) ($user['email'] ?? ''),
            'initial' => mb_substr($nickname, 0, 1),
            'hue' => Present::avatarHue((int) $user['id']),
            'can_admin' => Guard::can($user, 'admin.access'),
            'permissions' => array_map(
                static fn (mixed $permission): string => (string) $permission,
                (array) ($user['permissions'] ?? []),
            ),
            'roles' => array_map(
                static fn (mixed $role): string => (string) $role,
                (array) ($user['roles'] ?? []),
            ),
            'avatar_url' => Present::uploadUrl(
                isset($user['avatar_thumb']) ? (string) $user['avatar_thumb'] : null,
                isset($user['avatar_path']) ? (string) $user['avatar_path'] : null,
            ),
            ...ProfileDecorations::present($user),
        ];
    }
}
