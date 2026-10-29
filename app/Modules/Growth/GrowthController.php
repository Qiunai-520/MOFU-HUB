<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GrowthController
{
    public function __construct(
        private readonly GrowthService $growth,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];

        $this->growth->refresh($userId);

        return $this->page->render($request, 'pages/growth.twig', [
            'profile' => $this->growth->profile($userId),
        ]);
    }
}
