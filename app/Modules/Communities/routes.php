<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Communities\CommunityApiController;
use Mofu\Modules\Communities\CommunityController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/circles', fn ($rq, $rs) => $container->get(CommunityController::class)->index($rq, $rs));
    $app->get('/circles/new', fn ($rq, $rs) => $container->get(CommunityController::class)->new($rq, $rs));
    $app->post('/circles', fn ($rq, $rs) => $container->get(CommunityController::class)->create($rq, $rs));
    $app->get('/circles/{slug}', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->show($rq, $rs, $args));
    $app->get('/circles/{slug}/new', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->newPost($rq, $rs, $args));

    $app->post('/circles/{slug}/join', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->join($rq, $rs, $args));
    $app->post('/circles/{slug}/leave', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->leave($rq, $rs, $args));
    $app->post('/circles/{slug}/update', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->updateInfo($rq, $rs, $args));
    $app->post('/circles/{slug}/members/{id:[0-9]+}/approve', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->approve($rq, $rs, $args));
    $app->post('/circles/{slug}/members/{id:[0-9]+}/reject', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->reject($rq, $rs, $args));
    $app->post('/circles/{slug}/members/{id:[0-9]+}/remove', fn ($rq, $rs, $args) => $container->get(CommunityController::class)->remove($rq, $rs, $args));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/communities/{id:[0-9]+}/posts', fn ($rq, $rs, $args) => $container->get(CommunityApiController::class)->posts($rq, $rs, $args));
    });
};
