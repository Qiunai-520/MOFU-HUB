<?php

declare(strict_types=1);

namespace Mofu\Modules\Communities;

use Mofu\Core\Http\ApiController;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Modules\Posts\FeedService;
use Mofu\Modules\Posts\PostRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CommunityApiController extends ApiController
{
    public function __construct(
        private readonly CommunityService $communities,
        private readonly PostRepository $posts,
        private readonly FeedService $feed,
    ) {
    }

    /** @param array<string, string> $args */
    public function posts(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $userId = (int) $user['id'];
        $communityId = (int) ($args['id'] ?? 0);

        if (!$this->communities->isMember($communityId, $userId)) {
            throw HttpException::forbidden('加入圈子后才能查看动态');
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $result = $this->posts->byCommunity($communityId, $userId, $page, 10);
        $items = $this->feed->presentRows($result['items'], $userId, Guard::can($user, 'post.moderate'));

        return $this->ok($response, $items, [
            'pagination' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'pages' => $result['pages'],
            ],
        ]);
    }
}
