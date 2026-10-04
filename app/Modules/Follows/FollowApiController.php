<?php

declare(strict_types=1);

namespace Mofu\Modules\Follows;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FollowApiController extends ApiController
{
    public function __construct(
        private readonly FollowService $follows,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function toggle(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        if (!$this->limiter->attempt('follow:' . $user['id'], 60, 300)) {
            throw HttpException::tooManyRequests('操作太频繁，请稍后再试');
        }

        $input = $this->input($request);
        $followeeId = (int) ($input['user_id'] ?? 0);

        $result = $this->follows->toggle((int) $user['id'], $followeeId);

        return $this->ok($response, $result);
    }
}
