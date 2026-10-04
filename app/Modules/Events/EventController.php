<?php

declare(strict_types=1);

namespace Mofu\Modules\Events;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\Security\RateLimiter;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

final class EventController
{
    public function __construct(
        private readonly EventService $events,
        private readonly Page $page,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $viewerId = Ctx::state($request)->userId();
        $query = $request->getQueryParams();
        $tab = (string) ($query['tab'] ?? 'upcoming');
        $tab = in_array($tab, EventService::TIME_TABS, true) ? $tab : 'upcoming';
        $page = max(1, (int) ($query['page'] ?? 1));

        return $this->page->render($request, 'pages/events/index.twig', [
            'events' => $this->events->listPage($tab, $page, $viewerId),
            'tab' => $tab,
            'baseUrl' => '/events?tab=' . $tab . '&',
        ]);
    }

    public function mine(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $query = $request->getQueryParams();
        $tab = ($query['tab'] ?? '') === 'organized' ? 'organized' : 'joined';
        $page = max(1, (int) ($query['page'] ?? 1));

        return $this->page->render($request, 'pages/events/mine.twig', [
            'events' => $this->events->minePage((int) $user['id'], $tab, $page),
            'tab' => $tab,
            'baseUrl' => '/events/mine?tab=' . $tab . '&',
        ]);
    }

    public function newForm(Request $request, Response $response): Response
    {
        Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/events/new.twig', [
            'editing' => false,
            'event' => null,
            'errors' => [],
            'values' => $this->emptyValues(),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        if (!$this->limiter->attempt('event:' . (int) $user['id'], 10, 3600)) {
            throw HttpException::tooManyRequests('发布活动太频繁啦，稍后再试');
        }

        $body = (array) $request->getParsedBody();

        try {
            $eventId = $this->events->create((int) $user['id'], $body, $this->coverFile($request));
        } catch (HttpException $e) {
            return $this->page->render($request, 'pages/events/new.twig', [
                'editing' => false,
                'event' => null,
                'errors' => ['form' => $e->getMessage()],
                'values' => $this->valuesFromInput($body),
            ], $e->getStatusCode());
        }

        return $response->withHeader('Location', EventService::eventUrl($eventId))->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $state = Ctx::state($request);
        $viewerId = $state->userId();
        $canModerate = Guard::can($state->user, 'post.moderate');
        $event = $this->events->findOrFail((int) ($args['id'] ?? 0));

        $isOrganizer = $viewerId !== null && (int) $event['user_id'] === $viewerId;

        if (!$isOrganizer && !$canModerate && !in_array((string) $event['status'], ['published', 'cancelled'], true)) {
            throw HttpException::notFound('活动不存在或已删除');
        }

        $myStatus = null;

        if ($viewerId !== null) {
            $states = $this->events->mySignupStates([(int) $event['id']], $viewerId);
            $myStatus = $states[(int) $event['id']] ?? null;
        }

        $canSeeRoster = $isOrganizer || $myStatus === 'approved';
        $signups = ['pending' => [], 'approved' => []];

        if ($canSeeRoster) {
            $signups = $this->events->signups((int) $event['id']);
        }

        return $this->page->render($request, 'pages/events/show.twig', [
            'event' => $this->events->present($event, $viewerId, $myStatus),
            'pendingSignups' => $isOrganizer ? $signups['pending'] : [],
            'rosterSignups' => $canSeeRoster ? $signups['approved'] : [],
            'showNotes' => $isOrganizer,
            'ok' => in_array($request->getQueryParams()['ok'] ?? '', self::OK_TOKENS, true) ? (string) $request->getQueryParams()['ok'] : '',
        ]);
    }

    /** @param array<string, string> $args */
    public function editForm(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $event = $this->events->findOrFail((int) ($args['id'] ?? 0));

        if ((int) $event['user_id'] !== (int) $user['id']) {
            throw HttpException::forbidden();
        }

        if (!$this->events->canEdit($event, (int) $user['id'])) {
            throw HttpException::badRequest('活动已开始或已取消，无法编辑');
        }

        return $this->page->render($request, 'pages/events/new.twig', [
            'editing' => true,
            'event' => $event,
            'errors' => [],
            'values' => $this->valuesFromRow($event),
        ]);
    }

    /** @param array<string, string> $args */
    public function update(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $eventId = (int) ($args['id'] ?? 0);
        $event = $this->events->findOrFail($eventId);

        if ((int) $event['user_id'] !== (int) $user['id']) {
            throw HttpException::forbidden();
        }

        $body = (array) $request->getParsedBody();

        try {
            $this->events->update($eventId, (int) $user['id'], $body, $this->coverFile($request));
        } catch (HttpException $e) {
            return $this->page->render($request, 'pages/events/new.twig', [
                'editing' => true,
                'event' => $event,
                'errors' => ['form' => $e->getMessage()],
                'values' => $this->valuesFromInput($body),
            ], $e->getStatusCode());
        }

        return $response->withHeader('Location', EventService::eventUrl($eventId))->withStatus(302);
    }

    /** 报名/审核结果回显令牌（白名单，避免任意文案回显） */
    private const OK_TOKENS = ['signedup', 'pending', 'cancelled', 'event-cancelled', 'approved', 'rejected', 'removed'];

    /** @param array<string, string> $args */
    public function signup(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $eventId = (int) ($args['id'] ?? 0);

        if (!$this->limiter->attempt('event-signup:' . (int) $user['id'], 30, 600)) {
            throw HttpException::tooManyRequests('操作太频繁，请稍后再试');
        }

        $body = (array) $request->getParsedBody();
        $status = $this->events->signup($eventId, (int) $user['id'], (string) ($body['note'] ?? ''));

        return $this->redirectShow($response, $eventId, $status === 'pending' ? 'pending' : 'signedup');
    }

    /** @param array<string, string> $args */
    public function cancelSignup(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $eventId = (int) ($args['id'] ?? 0);

        $this->events->cancelSignup($eventId, (int) $user['id']);

        return $this->redirectShow($response, $eventId, 'cancelled');
    }

    /** @param array<string, string> $args */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $eventId = (int) ($args['id'] ?? 0);

        $this->events->cancelEvent($eventId, (int) $user['id'], Guard::can($user, 'post.moderate'));

        return $this->redirectShow($response, $eventId, 'event-cancelled');
    }

    /** @param array<string, string> $args */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $eventId = (int) ($args['id'] ?? 0);

        $this->events->deleteEvent($eventId, (int) $user['id'], Guard::can($user, 'post.moderate'));

        return $response->withHeader('Location', '/events')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function manageSignup(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $eventId = (int) ($args['id'] ?? 0);
        $action = (string) ($args['action'] ?? '');

        if (!in_array($action, ['approve', 'reject', 'remove'], true)) {
            throw HttpException::badRequest('无效的操作');
        }

        $this->events->manageSignup($eventId, (int) $user['id'], (int) ($args['userId'] ?? 0), $action);

        return $this->redirectShow($response, $eventId, $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : 'removed'));
    }

    private function redirectShow(Response $response, int $eventId, string $ok = ''): Response
    {
        $query = $ok !== '' ? '?ok=' . rawurlencode($ok) : '';

        return $response
            ->withHeader('Location', EventService::eventUrl($eventId) . $query . '#signups')
            ->withStatus(302);
    }

    private function coverFile(Request $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $cover = $files['cover'] ?? null;

        return $cover instanceof UploadedFileInterface ? $cover : null;
    }

    /** @return array<string, string> */
    private function emptyValues(): array
    {
        return [
            'title' => '',
            'description' => '',
            'location' => '',
            'start_at' => '',
            'end_at' => '',
            'signup_deadline' => '',
            'capacity' => '0',
            'requires_approval' => '',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function valuesFromInput(array $input): array
    {
        return [
            'title' => trim((string) ($input['title'] ?? '')),
            'description' => trim((string) ($input['description'] ?? '')),
            'location' => trim((string) ($input['location'] ?? '')),
            'start_at' => trim((string) ($input['start_at'] ?? '')),
            'end_at' => trim((string) ($input['end_at'] ?? '')),
            'signup_deadline' => trim((string) ($input['signup_deadline'] ?? '')),
            'capacity' => trim((string) ($input['capacity'] ?? '0')),
            'requires_approval' => (string) ($input['requires_approval'] ?? '') === '1' ? '1' : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function valuesFromRow(array $row): array
    {
        return [
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'location' => (string) $row['location'],
            'start_at' => str_replace(' ', 'T', substr((string) $row['start_at'], 0, 16)),
            'end_at' => $row['end_at'] !== null ? str_replace(' ', 'T', substr((string) $row['end_at'], 0, 16)) : '',
            'signup_deadline' => $row['signup_deadline'] !== null
                ? str_replace(' ', 'T', substr((string) $row['signup_deadline'], 0, 16))
                : '',
            'capacity' => (string) (int) $row['capacity'],
            'requires_approval' => (int) $row['requires_approval'] === 1 ? '1' : '',
        ];
    }
}
