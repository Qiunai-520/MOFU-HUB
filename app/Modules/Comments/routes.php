<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Comments\CommentApiController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/posts/{id:[0-9]+}/comments', fn ($rq, $rs, $args) => $container->get(CommentApiController::class)->index($rq, $rs, $args));
        $group->post('/comments', fn ($rq, $rs) => $container->get(CommentApiController::class)->store($rq, $rs));
        $group->delete('/comments/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(CommentApiController::class)->destroy($rq, $rs, $args));
    });
};
