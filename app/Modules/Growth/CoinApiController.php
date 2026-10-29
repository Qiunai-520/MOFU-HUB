<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CoinApiController extends ApiController
{
    public function __construct(private readonly CoinService $coins)
    {
    }

    /** @param array<string, string> $args */
    public function summary(Request $request, Response $response, array $args): Response
    {
        $user = $this->user($request);

        return $this->ok($response, $this->coins->summary((int) $args['id'], $user === null ? null : (int) $user['id']));
    }

    /** @param array<string, string> $args */
    public function give(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $amount = (int) ($this->input($request)['amount'] ?? 1);

        return $this->ok($response, $this->coins->give((int) $user['id'], (int) $args['id'], $amount));
    }
}
