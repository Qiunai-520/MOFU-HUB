<?php

declare(strict_types=1);

namespace Mofu\Modules\Search;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\Json;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SearchController
{
    private const MAX_KEYWORD = 50;

    public function __construct(
        private readonly SearchService $search,
        private readonly Page $page,
    ) {
    }

    /** 搜索建议（JSON）：输入时的实时下拉数据 */
    public function suggest(Request $request, Response $response): Response
    {
        $keyword = trim((string) ($request->getQueryParams()['q'] ?? ''));

        if (mb_strlen($keyword) > self::MAX_KEYWORD) {
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD);
        }

        if ($keyword === '') {
            return Json::ok($response, ['topics' => [], 'users' => []]);
        }

        return Json::ok($response, $this->search->suggest($keyword));
    }

    public function index(Request $request, Response $response): Response
    {        $state = Ctx::state($request);
        $query = $request->getQueryParams();

        $keyword = trim((string) ($query['q'] ?? ''));

        if (mb_strlen($keyword) > self::MAX_KEYWORD) {
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD);
        }

        $type = in_array((string) ($query['type'] ?? ''), SearchService::TYPES, true)
            ? (string) $query['type']
            : 'all';
        $page = max(1, (int) ($query['page'] ?? 1));

        $data = [
            'q' => $keyword,
            'type' => $type,
            'hotTopics' => $this->search->hotTopics(12),
        ];

        if ($keyword !== '') {
            $data += $this->search->search(
                $keyword,
                $type,
                $page,
                $state->userId(),
                Guard::can($state->user, 'post.moderate'),
            );
        }

        return $this->page->render($request, 'pages/search.twig', $data);
    }
}
