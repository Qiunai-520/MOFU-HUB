<?php

declare(strict_types=1);

namespace Mofu\Modules\History;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HistoryController
{
    public function __construct(
        private readonly HistoryService $history,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        return $this->page->render($request, 'pages/history.twig', [
            'feed' => $this->history->listPage((int) $user['id'], $page),
            'baseUrl' => '/history?',
        ]);
    }

    public function clear(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $this->history->clear((int) $user['id']);

        return $response->withHeader('Location', '/history')->withStatus(302);
    }
}
