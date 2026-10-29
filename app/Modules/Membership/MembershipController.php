<?php

declare(strict_types=1);

namespace Mofu\Modules\Membership;

use Mofu\Core\Http\Ctx;
use Mofu\Core\View\Page;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Payment\PaymentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MembershipController
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly PaymentService $payments,
        private readonly LevelService $levels,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Ctx::user($request);
        $userId = $user !== null ? (int) $user['id'] : 0;

        return $this->page->render($request, 'pages/membership.twig', [
            'plans' => $this->memberships->plans(),
            'subscription' => $userId > 0 ? $this->memberships->status($userId) : null,
            'level' => $userId > 0 ? $this->levels->stats($userId) : null,
            'paymentReady' => $this->payments->configured(),
        ]);
    }
}
