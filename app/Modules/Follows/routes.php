<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Follows\FollowApiController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->post('/follows/toggle', fn ($rq, $rs) => $container->get(FollowApiController::class)->toggle($rq, $rs));
    });
};
