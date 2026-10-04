<?php

declare(strict_types=1);

namespace Mofu\Modules\Notifications;

use Mofu\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class NotificationApiController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $query = $request->getQueryParams();
        $filter = ($query['filter'] ?? '') === 'unread' ? 'unread' : 'all';
        $page = max(1, (int) ($query['page'] ?? 1));

        $result = $this->notifications->listForUser((int) $user['id'], $filter, $page, 30);

        return $this->ok($response, $result['items'], [
            'unread_count' => $this->notifications->unreadCount((int) $user['id']),
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
            ],
        ]);
    }

    public function unreadCount(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        return $this->ok($response, ['count' => $this->notifications->unreadCount((int) $user['id'])]);
    }

    public function markRead(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $input = $this->input($request);
        $ids = [];

        if (isset($input['ids']) && is_array($input['ids'])) {
            $ids = array_values(array_filter(array_map('intval', $input['ids']), static fn (int $id): bool => $id > 0));
        }

        $updated = $this->notifications->markRead((int) $user['id'], $ids);

        return $this->ok($response, ['updated' => $updated]);
    }
}
