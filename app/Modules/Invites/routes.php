<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Invites\InviteApiController;
use Mofu\Modules\Invites\InviteController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/invites', fn ($rq, $rs) => $container->get(InviteController::class)->index($rq, $rs));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $api = fn () => $container->get(InviteApiController::class);

        $group->get('/invites', fn ($rq, $rs) => $api()->index($rq, $rs));
        $group->post('/invites/purchase', fn ($rq, $rs) => $api()->purchase($rq, $rs));
    });
};
