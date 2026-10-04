<?php

declare(strict_types=1);

namespace Mofu\Modules\Themes;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Modules\Settings\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ThemeController
{
    public function __construct(
        private readonly ThemeService $themes,
        private readonly SettingsService $settings,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/settings/appearance.twig', [
            'defaultTheme' => $this->settings->defaultTheme(),
            'activeTheme' => $this->themes->activeFor((int) $user['id']),
            'userThemes' => $this->themes->allFor((int) $user['id']),
            'variants' => ThemeService::VARIANTS,
        ]);
    }
}
