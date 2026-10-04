<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Reports\ReportApiController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->post('/reports', fn ($rq, $rs) => $container->get(ReportApiController::class)->store($rq, $rs));
    });
};
