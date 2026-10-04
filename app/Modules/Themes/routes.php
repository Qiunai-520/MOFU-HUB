<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Themes\ThemeApiController;
use Mofu\Modules\Themes\ThemeController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/settings/appearance', fn ($rq, $rs) => $container->get(ThemeController::class)->index($rq, $rs));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/themes', fn ($rq, $rs) => $container->get(ThemeApiController::class)->index($rq, $rs));
        $group->post('/themes', fn ($rq, $rs) => $container->get(ThemeApiController::class)->save($rq, $rs));
        $group->post('/themes/{id:[0-9]+}/activate', fn ($rq, $rs, $args) => $container->get(ThemeApiController::class)->activate($rq, $rs, $args));
        $group->delete('/themes/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(ThemeApiController::class)->destroy($rq, $rs, $args));
    });
};
