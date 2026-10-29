<?php

declare(strict_types=1);

namespace Mofu\Bootstrap;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\SessionManager;
use Mofu\Core\Security\SessionState;
use Mofu\Core\Support\Logger;
use Mofu\Core\View\TwigRenderer;
use Mofu\Core\View\ViewContext;
use Mofu\Modules\Themes\ThemeBootstrap;
use Mofu\Modules\Users\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException as SlimHttpException;
use Slim\Psr7\Factory\ResponseFactory;
use Throwable;

/**
 * 统一错误处理：API 请求返回 JSON，页面请求返回 HTML 错误页。
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = match (true) {
            $exception instanceof HttpException => $exception->getStatusCode(),
            $exception instanceof SlimHttpException => ($code = $exception->getCode()) >= 400 && $code < 600 ? $code : 500,
            default => 500,
        };
        $isApi = $this->isApiRequest($request);

        // 需要登录：浏览器请求直接 302 跳登录页；API 请求保持 401 JSON
        $location = $exception instanceof HttpException ? (string) ($exception->getHeaders()['Location'] ?? '') : '';

        if ($location !== '') {
            if (!$isApi) {
                return (new ResponseFactory())->createResponse(302)
                    ->withHeader('Location', $location)
                    ->withHeader('Cache-Control', 'no-store, private');
            }

            $status = 401;
        }

        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);

        if ($status >= 500) {
            $logger->error($exception->getMessage(), [
                'type' => $exception::class,
                'file' => $exception->getFile() . ':' . $exception->getLine(),
                'path' => $request->getUri()->getPath(),
            ]);
        } else {
            $logger->debug('Http ' . $status . ': ' . $exception->getMessage());
        }

        $response = (new ResponseFactory())->createResponse($status);

        if ($exception instanceof HttpException) {
            foreach ($exception->getHeaders() as $name => $value) {
                $response = $response->withHeader((string) $name, $value);
            }
        }

        if ($isApi) {
            $message = match (true) {
                $exception instanceof HttpException => $exception->getMessage(),
                $status === 404 => '接口或资源不存在',
                $status === 405 => '请求方式不受支持',
                (bool) $this->config->get('app.debug') => $exception->getMessage(),
                default => '服务器开小差了，请稍后再试',
            };

            $payload = json_encode([
                'ok' => false,
                'error' => [
                    'code' => 'http_' . $status,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            $response->getBody()->write($payload === false
                ? '{"ok":false,"error":{"code":"http_500","message":"服务器开小差了，请稍后再试"}}'
                : $payload);

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
        }

        return $this->htmlResponse($response, $request, $status, $exception);
    }

    private function htmlResponse(
        ResponseInterface $response,
        ServerRequestInterface $request,
        int $status,
        Throwable $exception,
    ): ResponseInterface {
        $message = match (true) {
            $exception instanceof HttpException => $exception->getMessage(),
            $status === 404 => '页面不存在或已被移除',
            $status === 405 => '请求方式不受支持',
            default => '服务器开小差了，请稍后再试',
        };

        try {
            /** @var TwigRenderer $view */
            $view = $this->container->get(TwigRenderer::class);
            /** @var ThemeBootstrap $theme */
            $theme = $this->container->get(ThemeBootstrap::class);

            // 错误处理位于中间件最外层，请求属性里没有会话状态；
            // 出错页也需要正确的登录态（决定可用动作与用户主题），按 cookie 自行解析一次。
            $request = $request->withAttribute(SessionState::class, $this->resolveSessionState($request));

            $html = $view->render('pages/error.twig', ViewContext::base($request, $this->config) + $theme->chrome(Ctx::state($request)->userId()) + [
                'status' => $status,
                'message' => $message,
                'detail' => $this->config->get('app.debug') && !($exception instanceof HttpException)
                    ? $exception->getMessage()
                    : null,
            ]);

            $response->getBody()->write($html);

            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store, private');
        } catch (Throwable) {
            $response->getBody()->write(sprintf(
                '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><title>%d</title><h1>%d</h1><p>%s</p>',
                $status,
                $status,
                htmlspecialchars($message, ENT_QUOTES),
            ));

            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store, private');
        }
    }

    private function isApiRequest(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        return str_starts_with($path, '/api/')
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * 尽力从会话 cookie 还原登录态；数据库不可用时退回空状态。
     */
    private function resolveSessionState(ServerRequestInterface $request): SessionState
    {
        $state = Ctx::state($request);

        if ($state->user !== null || $state->session !== null) {
            return $state;
        }

        try {
            /** @var SessionManager $sessions */
            $sessions = $this->container->get(SessionManager::class);
            /** @var UserRepository $users */
            $users = $this->container->get(UserRepository::class);

            $rawId = $request->getCookieParams()[$sessions->cookieName()] ?? null;
            $session = is_string($rawId) ? $sessions->resolve($rawId) : null;

            if ($session === null) {
                return $state;
            }

            $state->session = $session;
            $userId = $session['user_id'] ?? null;

            if ($userId !== null) {
                $user = $users->findForSession((int) $userId);

                if ($user !== null && ($user['status'] ?? 'active') === 'active') {
                    $state->user = $user;
                }
            }
        } catch (Throwable) {
            // 解析失败按未登录处理
        }

        return $state;
    }
}
