<?php

declare(strict_types=1);

namespace Mofu\Modules\Moderation;

use Mofu\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BanApiController extends ApiController
{
    public function __construct(private readonly BanService $bans)
    {
    }

    public function status(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];
        $ban = $this->bans->activeBan($userId);

        return $this->ok($response, [
            'status' => (string) ($user['status'] ?? 'active'),
            'restricted' => $this->bans->isRestricted($user),
            'ban' => $ban === null ? null : $this->bans->presentBan($ban),
            'history' => $this->bans->history($userId),
        ]);
    }
}
