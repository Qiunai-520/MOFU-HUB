<?php

declare(strict_types=1);

namespace Mofu\Modules\Communities;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Modules\Posts\FeedService;
use Mofu\Modules\Posts\PostRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CommunityController
{
    private const TABS = ['feed', 'members', 'about'];

    /** 入圈/退圈后回显的成功提示令牌（白名单，避免任意文案回显） */
    private const OK_TOKENS = ['joined', 'pending', 'left'];

    public function __construct(
        private readonly CommunityService $communities,
        private readonly PostRepository $posts,
        private readonly FeedService $feed,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $state = Ctx::state($request);
        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        return $this->page->render($request, 'pages/circles/index.twig', [
            'circles' => $this->communities->listPage($state->userId(), $page, 12),
            'myCircles' => $state->user !== null
                ? $this->communities->myCommunities((int) $state->user['id'])
                : [],
        ]);
    }

    public function new(Request $request, Response $response): Response
    {
        Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/circles/new.twig', [
            'errors' => [],
            'values' => ['name' => '', 'description' => '', 'visibility' => 'public'],
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();

        try {
            $community = $this->communities->create((int) $user['id'], $body);
        } catch (HttpException $e) {
            return $this->page->render($request, 'pages/circles/new.twig', [
                'errors' => ['form' => $e->getMessage()],
                'values' => [
                    'name' => trim((string) ($body['name'] ?? '')),
                    'description' => trim((string) ($body['description'] ?? '')),
                    'visibility' => (string) ($body['visibility'] ?? 'public'),
                ],
            ], $e->getStatusCode());
        }

        return $response->withHeader('Location', (string) $community['url'])->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $state = Ctx::state($request);
        $community = $this->requireCommunity($args);
        $communityId = (int) $community['id'];
        $viewerId = $state->userId();

        $query = $request->getQueryParams();
        $tab = in_array($query['tab'] ?? '', self::TABS, true) ? (string) $query['tab'] : 'feed';
        $page = max(1, (int) ($query['page'] ?? 1));

        $membership = $viewerId !== null ? $this->communities->membership($communityId, $viewerId) : null;
        $isMember = $membership !== null && (string) $membership['status'] === 'active';
        $isManager = $isMember && in_array((string) $membership['role'], ['owner', 'moderator'], true);

        $data = [
            'community' => $community,
            'counts' => $this->communities->counts($communityId),
            'tab' => $tab,
            'baseUrl' => '/circles/' . rawurlencode((string) $community['slug']) . '?',
            'membershipStatus' => $membership !== null ? (string) $membership['status'] : null,
            'isMember' => $isMember,
            'isManager' => $isManager,
            'isOwner' => $viewerId !== null && (int) $community['owner_id'] === $viewerId,
            'ok' => in_array($query['ok'] ?? '', self::OK_TOKENS, true) ? (string) $query['ok'] : '',
        ];

        if ($tab === 'members') {
            $data['members'] = $this->communities->membersPage($communityId, $page);
            $data['pendingMembers'] = $isManager ? $this->communities->pendingMembers($communityId) : [];
        } elseif ($tab === 'feed' && $isMember) {
            $result = $this->posts->byCommunity($communityId, $viewerId, $page, 10);
            $data['feed'] = $result;
            $data['feedItems'] = $this->feed->presentRows(
                $result['items'],
                $viewerId,
                Guard::can($state->user, 'post.moderate'),
            );
        }

        return $this->page->render($request, 'pages/circles/show.twig', $data);
    }

    /** @param array<string, string> $args */
    public function newPost(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $community = $this->requireCommunity($args);

        if (!$this->communities->isMember((int) $community['id'], (int) $user['id'])) {
            throw HttpException::forbidden('加入圈子后才能发布内容');
        }

        return $this->page->render($request, 'pages/compose.twig', [
            'composeCommunity' => [
                'id' => (int) $community['id'],
                'name' => (string) $community['name'],
                'slug' => (string) $community['slug'],
            ],
        ]);
    }

    /** @param array<string, string> $args */
    public function join(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $community = $this->requireCommunity($args);

        $result = $this->communities->join($community, (int) $user['id']);
        $ok = ($result['status'] ?? '') === 'pending' ? 'pending' : 'joined';

        return $this->redirectBack($response, $community, 'feed', $ok);
    }

    /** @param array<string, string> $args */
    public function leave(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $community = $this->requireCommunity($args);

        $this->communities->leave($community, (int) $user['id']);

        return $this->redirectBack($response, $community, 'feed', 'left');
    }

    /** @param array<string, string> $args */
    public function updateInfo(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $community = $this->requireCommunity($args);

        $this->communities->updateInfo($community, (int) $user['id'], (array) $request->getParsedBody());

        return $this->redirectBack($response, $community, 'about');
    }

    /** @param array<string, string> $args */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $community = $this->requireCommunity($args);

        $this->communities->approve($community, (int) $user['id'], (int) ($args['id'] ?? 0));

        return $this->redirectBack($response, $community, 'members');
    }

    /** @param array<string, string> $args */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $community = $this->requireCommunity($args);

        $this->communities->reject($community, (int) $user['id'], (int) ($args['id'] ?? 0));

        return $this->redirectBack($response, $community, 'members');
    }

    /** @param array<string, string> $args */
    public function remove(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $community = $this->requireCommunity($args);

        $this->communities->removeMember($community, (int) $user['id'], (int) ($args['id'] ?? 0));

        return $this->redirectBack($response, $community, 'members');
    }

    /**
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    private function requireCommunity(array $args): array
    {
        $community = $this->communities->findActiveBySlug((string) ($args['slug'] ?? ''));

        if ($community === null) {
            throw HttpException::notFound('圈子不存在或已停用');
        }

        return $community;
    }

    /** @param array<string, mixed> $community */
    private function redirectBack(Response $response, array $community, string $tab = 'feed', string $ok = ''): Response
    {
        $url = '/circles/' . rawurlencode((string) $community['slug']);
        $query = [];

        if ($tab !== 'feed') {
            $query['tab'] = $tab;
        }

        if ($ok !== '') {
            $query['ok'] = $ok;
        }

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
