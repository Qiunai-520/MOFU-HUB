<?php

declare(strict_types=1);

namespace Mofu\Core\Http;

use Mofu\Core\Security\SessionState;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 请求上下文便捷访问器。
 */
final class Ctx
{
    public static function state(ServerRequestInterface $request): SessionState
    {
        $state = $request->getAttribute(SessionState::class);

        if (!$state instanceof SessionState) {
            // 理论上 SessionMiddleware 一定注入；缺失时返回空状态保证安全。
            return new SessionState();
        }

        return $state;
    }

    /** @return array<string, mixed>|null */
    public static function user(ServerRequestInterface $request): ?array
    {
        return self::state($request)->user;
    }

    public static function isApi(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $accept = $request->getHeaderLine('Accept');

        return str_starts_with($path, '/api/') || str_contains($accept, 'application/json');
    }
}
