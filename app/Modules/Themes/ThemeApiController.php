<?php

declare(strict_types=1);

namespace Mofu\Modules\Themes;

use Mofu\Core\Http\ApiController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ThemeApiController extends ApiController
{
    public function __construct(private readonly ThemeService $themes)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);

        return $this->ok($response, [
            'active' => $this->themes->activeFor((int) $user['id']),
            'themes' => $this->themes->allFor((int) $user['id']),
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
        $user = $this->requireUser($request);
        $themeId = $this->themes->save((int) $user['id'], $this->input($request));

        return $this->ok($response, ['id' => $themeId], [], 201);
    }

    /** @param array<string, string> $args */
    public function activate(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $this->themes->activate((int) $user['id'], (int) ($args['id'] ?? 0));

        return $this->ok($response);
    }

    /** @param array<string, string> $args */
    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = $this->requireUser($request);
        $this->themes->delete((int) $user['id'], (int) ($args['id'] ?? 0));

        return $this->ok($response);
    }
}
