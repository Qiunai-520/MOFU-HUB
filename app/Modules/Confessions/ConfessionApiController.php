<?php

declare(strict_types=1);

namespace Mofu\Modules\Confessions;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\Security\SessionManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ConfessionApiController extends ApiController
{
    public function __construct(
        private readonly ConfessionService $service,
        private readonly ConfessionRepository $repository,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $state = Ctx::state($request);

        $board = ($query['board'] ?? '') === 'hole' ? 'hole' : 'wall';
        $sort = is_string($query['sort'] ?? null) && isset(ConfessionService::SORTS[$query['sort']])
            ? (string) $query['sort']
            : 'latest';
        $category = is_string($query['category'] ?? null) && isset(ConfessionService::CATEGORIES[$board][$query['category']])
            ? (string) $query['category']
            : '';
        $page = max(1, (int) ($query['page'] ?? 1));

        $result = $this->service->listBoard(
            $board,
            $sort,
            $category,
            $page,
            $state->userId(),
            Guard::can($state->user, 'post.moderate'),
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
        $state = Ctx::state($request);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        $limiterKey = $state->user !== null ? 'confession:u:' . $state->user['id'] : 'confession:ip:' . $ip;
        $maxAttempts = $state->user !== null ? 10 : 5;

        if (!$this->limiter->attempt($limiterKey, $maxAttempts, 3600)) {
            throw HttpException::tooManyRequests('投稿太频繁啦，休息一会儿再来');
        }

        $result = $this->service->submit($this->input($request), $state->user);

        $response = $this->ok($response, [
            'id' => $result['id'],
            'status' => $result['status'],
            'pending' => $result['status'] === 'pending',
            'url' => ConfessionService::boardUrl($result['board']) . '/' . $result['id'],
        ], [], 201);

        if ($result['guestKey'] !== null) {
            $response = $this->appendGuestKey($response, $request, $result['id'], $result['guestKey']);
        }

        return $response;
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $state = Ctx::state($request);

        $row = $this->repository->find($id);

        if ($row === null || !$this->service->viewable($row, $state->user)) {
            throw HttpException::notFound('内容不存在或未公开');
        }

        return $this->ok($response, $this->service->presentOne(
            $row,
            $state->userId(),
            Guard::can($state->user, 'post.moderate'),
        ));
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $state = Ctx::state($request);
        $id = (int) ($args['id'] ?? 0);

        $guestKeys = ConfessionController::readGuestKeys($request);
        $guestKey = $guestKeys[$id] ?? null;

        $this->service->delete($id, $state->user, $guestKey);

        return $this->ok($response);
    }

    /** @param array<string, string> $args */
    public function comments(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $state = Ctx::state($request);
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        $row = $this->repository->find($id);

        if ($row === null || $row['status'] !== 'approved' || $row['deleted_at'] !== null) {
            throw HttpException::notFound('内容不存在');
        }

        $result = $this->service->listComments(
            $id,
            $state->userId(),
            Guard::can($state->user, 'post.moderate'),
            $page,
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

    public function commentStore(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        if (!$this->limiter->attempt('confession-comment:' . $user['id'], 20, 300)) {
            throw HttpException::tooManyRequests('评论太频繁了，休息一下再发吧');
        }

        $input = $this->input($request);
        $confessionId = (int) ($input['confession_id'] ?? 0);

        $comment = $this->service->createComment($confessionId, $user, $input);

        $presented = ConfessionPresenter::presentComment(
            $comment,
            (int) $user['id'],
            Guard::can($user, 'post.moderate'),
            false,
        );

        return $this->ok($response, $presented + ['replies' => []], [], 201);
    }

    /** @param array<string, string> $args */
    public function commentDestroy(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);

        $this->service->removeComment(
            (int) ($args['id'] ?? 0),
            $user,
            Guard::can($user, 'post.moderate'),
        );

        return $this->ok($response);
    }

    private function appendGuestKey(Response $response, Request $request, int $confessionId, string $guestKey): Response
    {
        $keys = ConfessionController::readGuestKeys($request);
        $keys[$confessionId] = $guestKey;

        if (count($keys) > 10) {
            $keys = array_slice($keys, -10, null, true);
        }

        $cookie = sprintf(
            'mofu_confessions=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=%d%s',
            rawurlencode((string) json_encode($keys)),
            15552000,
            SessionManager::isSecureRequest($request->getServerParams()) ? '; Secure' : '',
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
