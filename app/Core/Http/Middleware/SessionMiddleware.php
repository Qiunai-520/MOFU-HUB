<?php

declare(strict_types=1);

namespace Mofu\Core\Http\Middleware;

use Mofu\Core\Security\SessionManager;
use Mofu\Core\Security\SessionState;
use Mofu\Modules\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 解析/创建访客会话，并把 SessionState 注入请求属性。
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly UserRepository $users,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $state = new SessionState();

        $cookies = $request->getCookieParams();
        $rawId = $cookies[$this->sessions->cookieName()] ?? null;
        $session = is_string($rawId) ? $this->sessions->resolve($rawId) : null;

        if ($session === null) {
            // 已知爬虫的只读抓取不建会话：能显著减少 user_sessions 的持续膨胀。
            // 注意无 UA 的客户端不当作爬虫，避免误伤工具型客户端与测试。
            if (self::isKnownBot($request->getHeaderLine('User-Agent'))) {
                return $handler->handle($request->withAttribute(SessionState::class, $state));
            }

            $session = $this->sessions->create();
            $state->cookieValue = (string) $session['id'];
        }

        $state->session = $session;

        $userId = $session['user_id'] ?? null;
        if ($userId !== null) {
            // 注意：封禁 / 停用账号的会话不再直接失效，而是交由 BanMiddleware 做受限跳转，
            // 以便向被处罚用户展示封禁原因与申诉入口。
            $state->user = $this->users->findForSession((int) $userId);
        }

        $response = $handler->handle($request->withAttribute(SessionState::class, $state));

        if ($state->clearCookie) {
            return $response->withAddedHeader('Set-Cookie', $this->sessions->cookieHeader('', true));
        }

        if ($state->cookieValue !== null) {
            return $response->withAddedHeader('Set-Cookie', $this->sessions->cookieHeader($state->cookieValue));
        }

        return $response;
    }

    /** 常见爬虫 / 抓取工具（无 UA 的客户端返回 false，按正常访客处理） */
    private static function isKnownBot(string $userAgent): bool
    {
        if ($userAgent === '') {
            return false;
        }

        return preg_match(
            '/bot\b|crawler|spider|slurp|bingpreview|facebookexternalhit|headlesschrome|python-requests|curl\/|wget|uptime|monitoring/i',
            $userAgent,
        ) === 1;
    }
}
