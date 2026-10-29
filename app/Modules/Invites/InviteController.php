<?php

declare(strict_types=1);

namespace Mofu\Modules\Invites;

use Mofu\Core\Http\Ctx;
use Mofu\Core\View\Page;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Growth\WalletService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InviteController
{
    public function __construct(
        private readonly InviteService $invites,
        private readonly WalletService $wallet,
        private readonly LevelService $levels,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Ctx::user($request);
        $userId = $user !== null ? (int) $user['id'] : 0;

        return $this->page->render($request, 'pages/invites.twig', [
            'quota' => $userId > 0 ? $this->invites->monthlyQuota($userId) : 0,
            'remaining' => $userId > 0 ? $this->invites->remaining($userId) : 0,
            'price' => InviteService::PRICE_MOFU,
            'codes' => $userId > 0 ? $this->invites->mine($userId) : [],
            'balance' => $userId > 0 ? $this->wallet->balance($userId) : null,
            'level' => $userId > 0 ? (int) ($this->levels->stats($userId)['level'] ?? 0) : 0,
        ]);
    }
}
