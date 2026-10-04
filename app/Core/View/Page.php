<?php

declare(strict_types=1);

namespace Mofu\Core\View;

use Mofu\Bootstrap\Config;
use Mofu\Core\Http\Ctx;
use Mofu\Modules\Themes\ThemeBootstrap;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * 页面渲染助手：组装公共上下文 + 渲染响应。
 */
final class Page
{
    public function __construct(
        private readonly TwigRenderer $view,
        private readonly Config $config,
        private readonly ThemeBootstrap $theme,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        ServerRequestInterface $request,
        string $template,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        $base = ViewContext::base($request, $this->config)
            + $this->theme->chrome(Ctx::state($request)->userId());

        $html = $this->view->render($template, $base + $data);

        $response = (new ResponseFactory())->createResponse($status);
        $response->getBody()->write($html);

        // 页面内嵌当前会话的 CSRF 令牌：禁止任何中间层缓存 HTML
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
