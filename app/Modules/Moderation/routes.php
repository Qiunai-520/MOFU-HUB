<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Moderation\BanApiController;
use Mofu\Modules\Moderation\BannedController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/banned', fn ($rq, $rs) => $container->get(BannedController::class)->show($rq, $rs));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/me/ban', fn ($rq, $rs) => $container->get(BanApiController::class)->status($rq, $rs));
    });
};
