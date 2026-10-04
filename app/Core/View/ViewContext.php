<?php

declare(strict_types=1);

namespace Mofu\Core\View;

use Mofu\Bootstrap\Config;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 页面公共上下文的组装。
 */
final class ViewContext
{
    /** @return array<string, mixed> */
    public static function base(ServerRequestInterface $request, Config $config): array
    {
        $state = Ctx::state($request);

        return [
            'app' => [
                'name' => (string) $config->get('app.name', 'MOFU社区'),
                'url' => rtrim((string) $config->get('app.url', ''), '/'),
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
            'csrfToken' => $state->csrfToken(),
            'currentPath' => $request->getUri()->getPath(),
        ];
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
            'initial' => mb_substr($nickname, 0, 1),
            'hue' => Present::avatarHue((int) $user['id']),
            'can_admin' => Guard::can($user, 'admin.access'),
            'permissions' => array_map(
                static fn (mixed $permission): string => (string) $permission,
                (array) ($user['permissions'] ?? []),
            ),
            'avatar_url' => Present::uploadUrl(
                isset($user['avatar_thumb']) ? (string) $user['avatar_thumb'] : null,
                isset($user['avatar_path']) ? (string) $user['avatar_path'] : null,
            ),
        ];
    }
}
