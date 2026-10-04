<?php

declare(strict_types=1);

namespace Mofu\Modules\Comments;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CommentApiController extends ApiController
{
    public function __construct(
        private readonly CommentService $commentService,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** @param array<string, string> $args */
    public function index(Request $request, Response $response, array $args): Response
    {
        $postId = (int) ($args['id'] ?? 0);
        $state = Ctx::state($request);
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        $result = $this->commentService->listForPost(
            $postId,
            $state->userId(),
            Guard::can($state->user, 'comment.moderate'),
            $page,
            20,
        );

        return $this->ok($response, $result['items'], [
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
            ],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        if (!$this->limiter->attempt('comment:' . $user['id'], 20, 300)) {
            throw HttpException::tooManyRequests('评论太频繁了，休息一下再发吧');
        }

        $comment = $this->commentService->create((int) $user['id'], $this->input($request));

        $presented = CommentPresenter::present(
            $comment,
            (int) $user['id'],
            Guard::can($user, 'comment.moderate'),
            false,
        );

        return $this->ok($response, $presented + ['replies' => []], [], 201);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);

        $this->commentService->remove(
            (int) ($args['id'] ?? 0),
            (int) $user['id'],
            Guard::can($user, 'comment.moderate'),
        );

        return $this->ok($response);
    }
}
