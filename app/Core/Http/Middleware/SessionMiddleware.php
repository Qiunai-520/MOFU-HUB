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
            $session = $this->sessions->create();
            $state->cookieValue = (string) $session['id'];
        }

        $state->session = $session;

        $userId = $session['user_id'] ?? null;
        if ($userId !== null) {
            $user = $this->users->findForSession((int) $userId);

            // 封禁 / 停用账号的既有会话立即失效（与登录入口保持一致）
            if ($user !== null && ($user['status'] ?? 'active') !== 'active') {
                $user = null;
            }

            $state->user = $user;
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
}
