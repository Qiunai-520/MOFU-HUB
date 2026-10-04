<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Confessions\ConfessionApiController;
use Mofu\Modules\Confessions\ConfessionController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    // 页面
    $app->get('/wall', fn ($rq, $rs) => $container->get(ConfessionController::class)->wall($rq, $rs));
    $app->get('/wall/new', fn ($rq, $rs) => $container->get(ConfessionController::class)->composeWall($rq, $rs));
    $app->get('/wall/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(ConfessionController::class)->show($rq, $rs, $args));

    $app->get('/hole', fn ($rq, $rs) => $container->get(ConfessionController::class)->hole($rq, $rs));
    $app->get('/hole/new', fn ($rq, $rs) => $container->get(ConfessionController::class)->composeHole($rq, $rs));
    $app->get('/hole/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(ConfessionController::class)->show($rq, $rs, $args));

    $app->get('/my/confessions', fn ($rq, $rs) => $container->get(ConfessionController::class)->mine($rq, $rs));

    // API
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/confessions', fn ($rq, $rs) => $container->get(ConfessionApiController::class)->index($rq, $rs));
        $group->post('/confessions', fn ($rq, $rs) => $container->get(ConfessionApiController::class)->store($rq, $rs));
        $group->get('/confessions/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(ConfessionApiController::class)->show($rq, $rs, $args));
        $group->delete('/confessions/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(ConfessionApiController::class)->destroy($rq, $rs, $args));
        $group->get('/confessions/{id:[0-9]+}/comments', fn ($rq, $rs, $args) => $container->get(ConfessionApiController::class)->comments($rq, $rs, $args));
        $group->post('/confession-comments', fn ($rq, $rs) => $container->get(ConfessionApiController::class)->commentStore($rq, $rs));
        $group->delete('/confession-comments/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(ConfessionApiController::class)->commentDestroy($rq, $rs, $args));
    });
};
