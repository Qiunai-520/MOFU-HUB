<?php

declare(strict_types=1);

namespace Mofu\Modules\Invites;

use Mofu\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InviteApiController extends ApiController
{
    public function __construct(
        private readonly InviteService $invites,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];

        return $this->ok($response, [
            'quota' => $this->invites->monthlyQuota($userId),
            'remaining' => $this->invites->remaining($userId),
            'price' => InviteService::PRICE_MOFU,
            'codes' => $this->invites->mine($userId),
        ]);
    }

    public function purchase(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        return $this->ok($response, $this->invites->purchase((int) $user['id']), [], 201);
    }
}
