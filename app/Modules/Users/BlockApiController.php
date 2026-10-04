<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\HttpException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class BlockApiController extends ApiController
{
    public function __construct(private readonly BlockService $blocks)
    {
    }

    public function toggle(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $input = $this->input($request);
        $targetId = (int) ($input['user_id'] ?? 0);

        if ($targetId <= 0) {
            throw HttpException::badRequest('参数有误');
        }

        return $this->ok($response, $this->blocks->toggle((int) $user['id'], $targetId));
    }
}
