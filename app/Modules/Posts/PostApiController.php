<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\Support\AuditLogger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PostApiController extends ApiController
{
    public function __construct(
        private readonly PostService $postService,
        private readonly PostRepository $posts,
        private readonly FeedService $feed,
        private readonly RateLimiter $limiter,
        private readonly AuditLogger $audit,
    ) {
    }

    public function feed(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $tab = in_array($query['tab'] ?? '', ['recommend', 'latest', 'hot', 'following'], true) ? (string) $query['tab'] : 'latest';
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min(20, max(1, (int) ($query['per_page'] ?? 10)));

        $state = Ctx::state($request);

        if ($tab === 'following' && $state->user === null) {
            throw HttpException::unauthorized('登录后查看关注动态');
        }

        $result = $this->posts->feed($tab, $page, $perPage, $state->userId());
        $items = $this->feed->presentRows($result['items'], $state->userId(), Guard::can($state->user, 'post.moderate'));

        return $this->ok($response, $items, [
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

        if (!$this->limiter->attempt('post:' . $user['id'], 10, 300)) {
            throw HttpException::tooManyRequests('发布太频繁了，休息一会儿再发吧');
        }

        $postId = $this->postService->create((int) $user['id'], $this->input($request));

        return $this->ok($response, ['id' => $postId, 'url' => '/posts/' . $postId], [], 201);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $postId = (int) ($args['id'] ?? 0);
        $state = Ctx::state($request);
        $viewerId = $state->userId();
        $canModerate = Guard::can($state->user, 'post.moderate');

        $post = $this->posts->find($postId);

        if ($post === null || !$this->postService->canView($post, $viewerId, $canModerate)) {
            throw HttpException::notFound('动态不存在');
        }

        return $this->ok($response, $this->feed->presentOne($post, $viewerId, $canModerate));
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $postId = (int) ($args['id'] ?? 0);

        $this->postService->update(
            $postId,
            $this->input($request),
            (int) $user['id'],
            Guard::can($user, 'post.moderate'),
        );

        return $this->ok($response, ['id' => $postId]);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $postId = (int) ($args['id'] ?? 0);

        $this->postService->delete($postId, (int) $user['id'], Guard::can($user, 'post.moderate'));

        if (Guard::can($user, 'post.moderate')) {
            $this->audit->log((int) $user['id'], 'post.delete', 'post', $postId, ['via' => 'moderation']);
        }

        return $this->ok($response);
    }

    /** @param array<string, string> $args */
    public function repost(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);

        if (!$this->limiter->attempt('repost:' . $user['id'], 20, 600)) {
            throw HttpException::tooManyRequests('操作太频繁，请稍后再试');
        }

        $postId = (int) ($args['id'] ?? 0);
        $repostId = $this->postService->repost((int) $user['id'], $postId);

        return $this->ok($response, [
            'active' => true,
            'repost_id' => $repostId,
            'repost_count' => $this->posts->repostCount($postId),
        ], [], 201);
    }

    /** @param array<string, string> $args */
    public function undoRepost(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $postId = (int) ($args['id'] ?? 0);

        $this->postService->undoRepost((int) $user['id'], $postId);

        return $this->ok($response, [
            'active' => false,
            'repost_count' => $this->posts->repostCount($postId),
        ]);
    }
}
