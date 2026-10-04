<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Users\BlockApiController;
use Mofu\Modules\Users\UserController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/me', fn ($rq, $rs) => $container->get(UserController::class)->me($rq, $rs));
    $app->get('/u/{username:[a-z0-9_]+}', fn ($rq, $rs, $args) => $container->get(UserController::class)->show($rq, $rs, $args));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->post('/blocks/toggle', fn ($rq, $rs) => $container->get(BlockApiController::class)->toggle($rq, $rs));
        $group->get('/users/search', fn ($rq, $rs) => $container->get(UserController::class)->search($rq, $rs));
    });
};
