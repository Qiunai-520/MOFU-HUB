<?php

declare(strict_types=1);

namespace Mofu\Modules\Moderation;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * 限制账号的访问控制。
 *
 * 被封禁 / 临时封禁的用户只允许访问封禁提示页、申诉页与静态资源；
 * 其余页面跳转到 /banned，接口返回 403 JSON。权限判定在服务端完成，前端隐藏按钮不作为依据。
 */
final class BanMiddleware implements MiddlewareInterface
{
    private const ALLOWED_PREFIXES = [
        '/banned',
        '/appeal',
        '/logout',
        '/login',
        '/register',
        '/api/v1/announcements',
        '/api/v1/me/ban',
        '/assets',
        '/vendor',
        '/uploads',
        '/fonts',
        '/icons',
        '/favicon.svg',
        '/manifest.webmanifest',
        '/sw.js',
        '/offline.html',
        '/logo.png',
    ];

    public function __construct(private readonly BanService $bans)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 惰性处理到期的临时封禁：每个请求都查一次属于热路径写放大，改为概率执行
        if (random_int(1, 100) === 1) {
            $this->bans->expireDue();
        }

        $user = Ctx::user($request);

        if ($user === null || !$this->bans->isRestricted($user)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();

        if ($this->isAllowed($path)) {
            return $handler->handle($request);
        }

        $factory = new ResponseFactory();

        if (Ctx::isApi($request) || str_starts_with($path, '/api/')) {
            return Json::error($factory->createResponse(), 'account_restricted', '账号当前受限，无法执行该操作', 403);
        }

        return $factory->createResponse(302)->withHeader('Location', '/banned');
    }

    private function isAllowed(string $path): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
