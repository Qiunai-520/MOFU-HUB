<?php

declare(strict_types=1);

namespace Mofu\Modules\Tags;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Modules\Posts\FeedService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TagController
{
    public function __construct(
        private readonly TagService $tags,
        private readonly FeedService $feed,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->page->render($request, 'pages/tags.twig', [
            'tags' => $this->tags->catalogue(),
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $tag = $this->tags->findRawBySlug($slug);

        if ($tag === null || (int) ($tag['active'] ?? 0) !== 1) {
            throw HttpException::notFound('标签不存在');
        }

        $state = Ctx::state($request);
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $result = $this->tags->paginatePosts((int) $tag['id'], $page, 10);

        return $this->page->render($request, 'pages/tag.twig', [
            'tag' => $this->tags->find((int) $tag['id']),
            'feed' => $result,
            'feedItems' => $this->feed->presentRows(
                $result['items'],
                $state->userId(),
                Guard::can($state->user, 'post.moderate'),
            ),
        ]);
    }
}
