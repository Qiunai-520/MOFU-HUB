<?php

declare(strict_types=1);

namespace Mofu\Core\Http\Middleware;

use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Csrf;
use Mofu\Core\Security\SessionState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 对变更类请求校验 CSRF token（表单字段 _csrf 或请求头 X-CSRF-Token）。
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array(strtoupper($request->getMethod()), self::MUTATING_METHODS, true)) {
            return $handler->handle($request);
        }

        $token = $request->getHeaderLine('X-CSRF-Token');

        if ($token === '') {
            $body = $request->getParsedBody();
            if (is_array($body) && isset($body['_csrf']) && is_string($body['_csrf'])) {
                $token = $body['_csrf'];
            }
        }

        $state = $request->getAttribute(SessionState::class);

        if (!$state instanceof SessionState || !Csrf::validate($state, $token)) {
            throw new HttpException(403, '安全校验失败，请刷新页面后重试。');
        }

        return $handler->handle($request);
    }
}
