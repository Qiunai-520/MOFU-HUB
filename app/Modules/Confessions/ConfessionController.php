<?php

declare(strict_types=1);

namespace Mofu\Modules\Confessions;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ConfessionController
{
    public function __construct(
        private readonly ConfessionService $confessions,
        private readonly ConfessionRepository $repository,
        private readonly Page $page,
    ) {
    }

    public function wall(Request $request, Response $response): Response
    {
        return $this->board($request, $response, 'wall');
    }

    public function hole(Request $request, Response $response): Response
    {
        return $this->board($request, $response, 'hole');
    }

    public function composeWall(Request $request, Response $response): Response
    {
        return $this->compose($request, $response, 'wall');
    }

    public function composeHole(Request $request, Response $response): Response
    {
        return $this->compose($request, $response, 'hole');
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $state = Ctx::state($request);

        $row = $this->repository->find($id);

        if ($row === null || !$this->confessions->viewable($row, $state->user)) {
            throw HttpException::notFound('内容不存在或未公开');
        }

        $canModerate = Guard::can($state->user, 'post.moderate');

        return $this->page->render($request, 'pages/confessions/detail.twig', [
            'item' => $this->confessions->presentOne($row, $state->userId(), $canModerate),
            'comments' => $this->confessions->listComments($id, $state->userId(), $canModerate, 1),
        ]);
    }

    public function mine(Request $request, Response $response): Response
    {
        $state = Ctx::state($request);
        $guestKeys = self::readGuestKeys($request);

        if ($state->user === null && $guestKeys === []) {
            return $this->page->render($request, 'pages/confessions/mine.twig', [
                'items' => [],
                'hasIdentity' => false,
            ]);
        }

        return $this->page->render($request, 'pages/confessions/mine.twig', [
            'items' => $this->confessions->mySubmissions($state->user, $guestKeys),
            'hasIdentity' => true,
        ]);
    }

    private function board(Request $request, Response $response, string $board): Response
    {
        $state = Ctx::state($request);
        $query = $request->getQueryParams();

        $sort = is_string($query['sort'] ?? null) && isset(ConfessionService::SORTS[$query['sort']])
            ? (string) $query['sort']
            : 'latest';

        $category = is_string($query['category'] ?? null) && isset(ConfessionService::CATEGORIES[$board][$query['category']])
            ? (string) $query['category']
            : '';

        $page = max(1, (int) ($query['page'] ?? 1));
        $canModerate = Guard::can($state->user, 'post.moderate');

        $result = $this->confessions->listBoard($board, $sort, $category, $page, $state->userId(), $canModerate);

        return $this->page->render($request, 'pages/confessions/board.twig', [
            'board' => $board,
            'boardLabel' => ConfessionService::boardLabel($board),
            'sort' => $sort,
            'category' => $category,
            'categories' => ConfessionService::CATEGORIES[$board],
            'sorts' => ConfessionService::SORTS,
            'items' => $result['items'],
            'pagination' => $result,
            'baseQuery' => '?sort=' . urlencode($sort) . '&category=' . urlencode($category) . '&',
            'moderationEnabled' => $this->confessions->moderationEnabled(),
        ]);
    }

    private function compose(Request $request, Response $response, string $board): Response
    {
        return $this->page->render($request, 'pages/confessions/compose.twig', [
            'board' => $board,
            'boardLabel' => ConfessionService::boardLabel($board),
            'categories' => ConfessionService::CATEGORIES[$board],
            'moderationEnabled' => $this->confessions->moderationEnabled(),
        ]);
    }

    /**
     * 解析游客投稿凭证 cookie。
     *
     * @return array<int, string>
     */
    public static function readGuestKeys(Request $request): array
    {
        $raw = $request->getCookieParams()['mofu_confessions'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $out = [];

        foreach ($decoded as $id => $key) {
            if (is_numeric($id) && is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key) === 1) {
                $out[(int) $id] = $key;
            }
        }

        return $out;
    }
}
