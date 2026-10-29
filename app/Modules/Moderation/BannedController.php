<?php

declare(strict_types=1);

namespace Mofu\Modules\Moderation;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BannedController
{
    public function __construct(
        private readonly BanService $bans,
        private readonly Page $page,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        $user = Ctx::user($request);

        if ($user === null) {
            throw HttpException::redirect('/login');
        }

        $ban = $this->bans->activeSuspension((int) $user['id']);

        return $this->page->render($request, 'pages/banned.twig', [
            'ban' => $ban === null ? null : $this->bans->presentBan($ban),
            'history' => $this->bans->history((int) $user['id']),
            'status' => (string) ($user['status'] ?? 'active'),
        ]);
    }
}
