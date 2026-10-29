<?php

declare(strict_types=1);

namespace Mofu\Modules\Jury;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Modules\Growth\LevelService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class JuryController
{
    public function __construct(
        private readonly JuryService $jury,
        private readonly LevelService $levels,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];
        $isMember = $this->jury->isMember($userId);
        $query = $request->getQueryParams();

        return $this->page->render($request, 'pages/jury.twig', [
            'isMember' => $isMember,
            'eligibility' => $this->jury->canApply($userId),
            'application' => $this->jury->myApplication($userId),
            'cleanRecord' => $this->jury->hasCleanRecord($userId),
            'level' => (int) ($this->levels->stats($userId)['level'] ?? 0),
            'minLevel' => JuryService::APPLY_MIN_LEVEL,
            'cleanDays' => JuryService::CLEAN_DAYS,
            'cases' => $this->jury->cases('voting'),
            'myVotes' => $isMember ? $this->jury->myVotes($userId) : [],
            'ok' => isset($query['ok']),
        ]);
    }

    public function apply(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();

        $this->jury->apply((int) $user['id'], (string) ($body['reason'] ?? ''));

        return $response->withHeader('Location', '/jury?ok=1')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function vote(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $body = (array) $request->getParsedBody();

        $this->jury->vote((int) ($args['id'] ?? 0), (int) $user['id'], (string) ($body['vote'] ?? ''));

        return $response->withHeader('Location', '/jury?ok=1')->withStatus(302);
    }
}
