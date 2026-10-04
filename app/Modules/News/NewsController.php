<?php

declare(strict_types=1);

namespace Mofu\Modules\News;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NewsController
{
    public function __construct(
        private readonly NewsService $news,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $category = (string) ($query['category'] ?? '');
        $category = isset(NewsService::CATEGORIES[$category]) ? $category : '';
        $page = max(1, (int) ($query['page'] ?? 1));

        return $this->page->render($request, 'pages/news/index.twig', [
            'news' => $this->news->listPage($category, $page),
            'category' => $category,
            'baseUrl' => '/news?' . ($category !== '' ? 'category=' . $category . '&' : ''),
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $state = Ctx::state($request);
        $canManage = Guard::can($state->user, 'news.manage');
        $row = $this->news->findOrFail((int) ($args['id'] ?? 0));

        if ((string) $row['status'] !== 'published' && !$canManage) {
            throw HttpException::notFound('资讯不存在或已删除');
        }

        if ((string) $row['status'] === 'published') {
            $this->news->incrementView((int) $row['id']);
            $row['view_count'] = (int) $row['view_count'] + 1;
        }

        return $this->page->render($request, 'pages/news/show.twig', [
            'news' => $this->news->present($row),
            'canManage' => $canManage,
        ]);
    }
}
