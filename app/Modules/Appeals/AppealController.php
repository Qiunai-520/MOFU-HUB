<?php

declare(strict_types=1);

namespace Mofu\Modules\Appeals;

use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\Security\SessionManager;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AppealController
{
    public function __construct(
        private readonly AppealService $appeals,
        private readonly RateLimiter $limiter,
        private readonly Page $page,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        return $this->page->render($request, 'pages/appeal.twig', [
            'username' => '',
            'errors' => [],
            'submitted' => isset($request->getQueryParams()['ok']),
            'latest' => $this->latestFromRequest($request),
        ]);
    }

    /** 从查看令牌 Cookie 还原申诉状态（仅本人凭提交时下发的令牌可见） */
    private function latestFromRequest(Request $request): ?array
    {
        $token = (string) ($request->getCookieParams()['mofu_appeal'] ?? '');

        return $token !== '' ? $this->appeals->latestForToken($token) : null;
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = strtolower(trim((string) ($body['username'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $reason = (string) ($body['reason'] ?? '');
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        $renderError = fn (string $message, int $status): Response => $this->page->render(
            $request,
            'pages/appeal.twig',
            [
                'username' => $username,
                'errors' => ['form' => $message],
                'submitted' => false,
                'latest' => $this->latestFromRequest($request),
            ],
            $status,
        );

        if (!$this->limiter->attempt('appeal:' . $ip, 5, 3600)) {
            return $renderError('提交太频繁了，请稍后再试', 429);
        }

        try {
            $result = $this->appeals->submit($username, $password, $reason);
        } catch (HttpException $e) {
            return $renderError($e->getMessage(), $e->getStatusCode());
        }

        $cookie = sprintf(
            'mofu_appeal=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=%d%s',
            $result['token'],
            15552000,
            SessionManager::isSecureRequest($request->getServerParams()) ? '; Secure' : '',
        );

        return $response
            ->withHeader('Set-Cookie', $cookie)
            ->withHeader('Location', '/appeal?ok=1')
            ->withStatus(302);
    }
}
