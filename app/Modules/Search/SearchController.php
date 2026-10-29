<?php

declare(strict_types=1);

namespace Mofu\Modules\Search;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\Json;
use Mofu\Core\Security\Guard;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class SearchController
{
    private const MAX_KEYWORD = 50;
    /** 少于该长度不做全表 LIKE 搜索（避免把整表扫一遍还没结果） */
    private const MIN_KEYWORD = 2;

    public function __construct(
        private readonly SearchService $search,
        private readonly Page $page,
        private readonly RateLimiter $limiter,
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

        // 建议接口是输入时高频调用，按 IP 限流避免被当作全文扫描入口
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        if (!$this->limiter->attempt('search-suggest:' . $ip, 60, 60)) {
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
            'keywordTooShort' => false,
            'rateLimited' => false,
        ];

        if ($keyword !== '' && mb_strlen($keyword) < self::MIN_KEYWORD) {
            // 关键词过短：不执行全表 LIKE，只给出提示（清空 q 以免走结果分支）
            $data['keywordTooShort'] = true;
            $data['q'] = '';
        } elseif ($keyword !== '') {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

            if (!$this->limiter->attempt('search:' . $ip, 30, 60)) {
                $data['rateLimited'] = true;
            } else {
                $data += $this->search->search(
                    $keyword,
                    $type,
                    $page,
                    $state->userId(),
                    Guard::can($state->user, 'post.moderate'),
                );
            }
        }

        return $this->page->render($request, 'pages/search.twig', $data);
    }
}
