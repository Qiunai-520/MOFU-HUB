<?php

declare(strict_types=1);

namespace Mofu\Modules\Jail;

use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class JailController
{
    public function __construct(
        private readonly JailService $jail,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $entries = $this->jail->entries($page, 20);

        return $this->page->render($request, 'pages/jail.twig', [
            'entries' => $entries,
            'stats' => $this->jail->stats(),
        ]);
    }
}
