<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Posts\PostApiController;
use Mofu\Modules\Posts\PostController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/posts/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(PostController::class)->show($rq, $rs, $args));
    $app->get('/posts/{id:[0-9]+}/edit', fn ($rq, $rs, $args) => $container->get(PostController::class)->edit($rq, $rs, $args));
    $app->get('/compose', fn ($rq, $rs) => $container->get(PostController::class)->compose($rq, $rs));
    $app->get('/topics/{slug}', fn ($rq, $rs, $args) => $container->get(PostController::class)->topic($rq, $rs, $args));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/feed', fn ($rq, $rs) => $container->get(PostApiController::class)->feed($rq, $rs));
        $group->post('/posts', fn ($rq, $rs) => $container->get(PostApiController::class)->store($rq, $rs));
        $group->get('/posts/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(PostApiController::class)->show($rq, $rs, $args));
        $group->patch('/posts/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(PostApiController::class)->update($rq, $rs, $args));
        $group->delete('/posts/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(PostApiController::class)->destroy($rq, $rs, $args));
        $group->post('/posts/{id:[0-9]+}/repost', fn ($rq, $rs, $args) => $container->get(PostApiController::class)->repost($rq, $rs, $args));
        $group->post('/posts/{id:[0-9]+}/purchase', fn ($rq, $rs, $args) => $container->get(PostApiController::class)->purchase($rq, $rs, $args));
        $group->delete('/posts/{id:[0-9]+}/repost', fn ($rq, $rs, $args) => $container->get(PostApiController::class)->undoRepost($rq, $rs, $args));
    });
};
