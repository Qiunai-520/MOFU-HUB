<?php

declare(strict_types=1);

namespace Mofu\Modules\Reactions;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\Http\HttpException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ReactionApiController extends ApiController
{
    public function __construct(
        private readonly ReactionService $reactions,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function toggle(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        if (!$this->limiter->attempt('reaction:' . $user['id'], 60, 300)) {
            throw HttpException::tooManyRequests('操作太频繁，请稍后再试');
        }

        $input = $this->input($request);

        $result = $this->reactions->toggle(
            (int) $user['id'],
            (string) ($input['target_type'] ?? ''),
            (int) ($input['target_id'] ?? 0),
            (string) ($input['kind'] ?? 'like'),
        );

        return $this->ok($response, $result);
    }
}
