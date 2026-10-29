<?php

declare(strict_types=1);

namespace Mofu\Modules\Lab;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LabController
{
    public function __construct(
        private readonly LabService $lab,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $query = $request->getQueryParams();

        return $this->page->render($request, 'pages/lab.twig', [
            'features' => $this->lab->features((int) $user['id']),
            'ok' => isset($query['ok']),
            'error' => isset($query['error']) ? (string) $query['error'] : '',
        ]);
    }

    /** @param array<string, string> $args */
    public function join(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        try {
            $this->lab->join((int) $user['id'], (string) ($args['key'] ?? ''));
        } catch (HttpException $e) {
            return $response->withHeader('Location', '/lab?error=' . rawurlencode($e->getMessage()))->withStatus(302);
        }

        return $response->withHeader('Location', '/lab?ok=1')->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function leave(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        $this->lab->leave((int) $user['id'], (string) ($args['key'] ?? ''));

        return $response->withHeader('Location', '/lab?ok=1')->withStatus(302);
    }
}
