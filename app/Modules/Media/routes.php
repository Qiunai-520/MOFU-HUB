<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Media\MediaApiController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->post('/uploads', fn ($rq, $rs) => $container->get(MediaApiController::class)->upload($rq, $rs));
    });
};
