<?php

declare(strict_types=1);

namespace Mofu\Modules\Announcements;

use Mofu\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AnnouncementApiController extends ApiController
{
    public function __construct(private readonly AnnouncementService $announcements)
    {
    }

    public function feed(Request $request, Response $response): Response
    {
        $user = $this->user($request);

        return $this->ok($response, $this->announcements->feed($user === null ? null : (int) $user['id']));
    }

    public function read(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $input = $this->input($request);
        $ids = isset($input['ids']) && is_array($input['ids']) ? array_map('intval', $input['ids']) : [];

        $this->announcements->markRead((int) $user['id'], $ids);

        return $this->ok($response, ['read' => $ids]);
    }

    public function dismiss(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);

        $this->announcements->dismiss((int) $user['id'], (int) $args['id']);

        return $this->ok($response, ['dismissed' => (int) $args['id']]);
    }
}
