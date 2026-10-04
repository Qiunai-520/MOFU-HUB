<?php

declare(strict_types=1);

namespace Mofu\Modules\Boards;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BoardController
{
    public function __construct(
        private readonly BoardService $boards,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $boards = $this->boards->all();
        $counts = $this->boards->counts();

        foreach ($boards as $i => $board) {
            $boards[$i]['entry_count'] = $counts[(int) $board['id']] ?? 0;
        }

        return $this->page->render($request, 'pages/boards/index.twig', [
            'boards' => $boards,
        ]);
    }

    /** @param array<string, string> $args */
    public function board(Request $request, Response $response, array $args): Response
    {
        $board = $this->requireBoard($args);
        $query = $request->getQueryParams();

        $filter = trim((string) ($query['filter'] ?? ''));
        $sort = ($query['sort'] ?? '') === 'hot' ? 'hot' : 'latest';
        $page = max(1, (int) ($query['page'] ?? 1));

        return $this->page->render($request, 'pages/boards/board.twig', [
            'board' => $board,
            'entries' => $this->boards->listPage($board, $filter, $sort, $page),
            'filter' => $filter,
            'sort' => $sort,
            'baseUrl' => '/boards/' . rawurlencode((string) $board['slug']) . '?',
            'filters' => $this->filtersFor($board),
        ]);
    }

    /** @param array<string, string> $args */
    public function new(Request $request, Response $response, array $args): Response
    {
        Guard::requireLogin(Ctx::user($request));
        $board = $this->requireBoard($args);

        return $this->page->render($request, 'pages/boards/new.twig', [
            'board' => $board,
            'conditions' => BoardService::CONDITIONS,
            'errors' => [],
            'values' => ['title' => '', 'content' => '', 'price' => '', 'condition' => '', 'lost_type' => '', 'location' => '', 'happened_on' => ''],
        ]);
    }

    /** @param array<string, string> $args */
    public function create(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $board = $this->requireBoard($args);
        $body = (array) $request->getParsedBody();

        try {
            $entryId = $this->boards->create((int) $user['id'], $board, $body);
        } catch (HttpException $e) {
            return $this->page->render($request, 'pages/boards/new.twig', [
                'board' => $board,
                'conditions' => BoardService::CONDITIONS,
                'errors' => ['form' => $e->getMessage()],
                'values' => [
                    'title' => trim((string) ($body['title'] ?? '')),
                    'content' => trim((string) ($body['content'] ?? '')),
                    'price' => trim((string) ($body['price'] ?? '')),
                    'condition' => (string) ($body['condition'] ?? ''),
                    'lost_type' => (string) ($body['lost_type'] ?? ''),
                    'location' => trim((string) ($body['location'] ?? '')),
                    'happened_on' => trim((string) ($body['happened_on'] ?? '')),
                ],
            ], $e->getStatusCode());
        }

        return $response->withHeader('Location', BoardService::entryUrl((string) $board['slug'], $entryId))->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $state = Ctx::state($request);
        $viewerId = $state->userId();
        $entry = $this->requireEntry((int) ($args['id'] ?? 0));
        $board = $this->boardRow($entry);

        $canModerate = Guard::can($state->user, 'post.moderate');
        $isOwner = $viewerId !== null && (int) $entry['user_id'] === $viewerId;

        if ($entry['deleted_at'] !== null) {
            throw HttpException::notFound('帖子不存在或已删除');
        }

        if (!$isOwner && !$canModerate && (string) $entry['status'] !== 'published') {
            throw HttpException::notFound('帖子不存在或已删除');
        }

        $presented = $this->boards->presentEntry($entry, $board);
        $presented['is_owner'] = $isOwner;
        $presented['can_manage'] = $isOwner || $canModerate;
        $presented['state'] = $this->currentState($entry);
        $presented['states'] = BoardService::STATES[(string) $board['kind']] ?? [];
        $presented['liked'] = $viewerId !== null && $this->boards->hasReaction((int) $entry['id'], $viewerId, 'like');
        $presented['favorited'] = $viewerId !== null && $this->boards->hasReaction((int) $entry['id'], $viewerId, 'favorite');

        return $this->page->render($request, 'pages/boards/show.twig', [
            'board' => $board,
            'entry' => $presented,
            'entryRaw' => ['id' => (int) $entry['id']],
            'comments' => $this->boards->comments((int) $entry['id']),
        ]);
    }

    /** @param array<string, string> $args */
    public function toggleState(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $entry = $this->requireEntry((int) ($args['id'] ?? 0));
        $board = $this->boardRow($entry);

        $this->boards->toggleState($entry, (int) $user['id'], Guard::can(Ctx::user($request), 'post.moderate'));

        return $this->redirectBack($response, (string) $board['slug'], (int) $entry['id']);
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $entry = $this->requireEntry((int) ($args['id'] ?? 0));
        $board = $this->boardRow($entry);

        $this->boards->deleteEntry($entry, (int) $user['id'], Guard::can(Ctx::user($request), 'post.moderate'));

        return $response
            ->withHeader('Location', '/boards/' . rawurlencode((string) $board['slug']))
            ->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function comment(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $entry = $this->requireEntry((int) ($args['id'] ?? 0));
        $board = $this->boardRow($entry);

        if ((string) $entry['status'] !== 'published' || $entry['deleted_at'] !== null) {
            throw HttpException::notFound('帖子不存在或已删除');
        }

        $body = (array) $request->getParsedBody();
        $this->boards->addComment($entry, (int) $user['id'], (string) ($body['content'] ?? ''));

        return $response
            ->withHeader('Location', BoardService::entryUrl((string) $board['slug'], (int) $entry['id']) . '#comments')
            ->withStatus(302);
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    private function requireBoard(array $args): array
    {
        $board = $this->boards->findBySlug((string) ($args['slug'] ?? ''));

        if ($board === null) {
            throw HttpException::notFound('板块不存在或已停用');
        }

        return $board;
    }

    /** @return array<string, mixed> */
    private function requireEntry(int $id): array
    {
        $entry = $this->boards->find($id);

        if ($entry === null) {
            throw HttpException::notFound('帖子不存在或已删除');
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function boardRow(array $entry): array
    {
        return [
            'id' => (int) $entry['board_id'],
            'slug' => (string) $entry['board_slug'],
            'name' => (string) $entry['board_name'],
            'kind' => (string) $entry['board_kind'],
            'icon' => (string) $entry['board_icon'],
        ];
    }

    /**
     * 板块筛选选项（按 kind 提供）。
     *
     * @param array<string, mixed> $board
     * @return list<array{key: string, label: string}>
     */
    private function filtersFor(array $board): array
    {
        $kind = (string) $board['kind'];

        if (isset(BoardService::STATES[$kind])) {
            $out = [];
            foreach (BoardService::STATES[$kind] as $key => $label) {
                $out[] = ['key' => (string) $key, 'label' => $label];
            }

            return $out;
        }

        if ($kind === 'lost') {
            $out = [];
            foreach (BoardService::LOST_TYPES as $key => $label) {
                $out[] = ['key' => (string) $key, 'label' => $label];
            }

            return $out;
        }

        return [];
    }

    /** @param array<string, mixed> $entry */
    private function currentState(array $entry): ?string
    {
        $kind = (string) $entry['board_kind'];
        $states = BoardService::STATES[$kind] ?? null;

        if ($states === null) {
            return null;
        }

        $raw = $entry['extra'] ?? null;
        $extra = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        $extra = is_array($extra) ? $extra : [];
        $keys = array_keys($states);

        $state = (string) ($extra['state'] ?? $keys[0]);

        return isset($states[$state]) ? $state : (string) $keys[0];
    }

    private function redirectBack(Response $response, string $boardSlug, int $entryId): Response
    {
        return $response
            ->withHeader('Location', BoardService::entryUrl($boardSlug, $entryId))
            ->withStatus(302);
    }
}
