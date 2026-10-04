<?php

declare(strict_types=1);

namespace Mofu\Modules\Messages;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MessageApiController extends ApiController
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function unreadCount(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        return $this->ok($response, ['count' => $this->messages->unreadCount((int) $user['id'])]);
    }

    /** @param array<string, string> $args */
    public function messages(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $conversationId = (int) ($args['id'] ?? 0);
        $query = $request->getQueryParams();

        $afterId = isset($query['after_id']) ? max(0, (int) $query['after_id']) : null;
        $beforeId = isset($query['before_id']) ? max(0, (int) $query['before_id']) : null;

        $items = $this->messages->listMessages($conversationId, (int) $user['id'], $afterId, $beforeId);

        return $this->ok($response, $items);
    }

    /** @param array<string, string> $args */
    public function send(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];

        if (!$this->limiter->attempt('message:' . $userId, 30, 300)) {
            throw HttpException::tooManyRequests('发送太频繁啦，稍后再试');
        }

        $conversationId = (int) ($args['id'] ?? 0);
        $input = $this->input($request);

        $message = $this->messages->send($userId, $conversationId, (string) ($input['content'] ?? ''));

        return $this->ok($response, $this->messages->presentMessage($message, $userId), [], 201);
    }

    /** @param array<string, string> $args */
    public function markRead(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $conversationId = (int) ($args['id'] ?? 0);

        $this->messages->markRead((int) $user['id'], $conversationId);

        return $this->ok($response, ['ok' => true]);
    }
}
