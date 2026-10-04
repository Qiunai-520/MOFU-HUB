<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Boards\BoardController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/boards', fn ($rq, $rs) => $container->get(BoardController::class)->index($rq, $rs));
    $app->get('/boards/{slug}/new', fn ($rq, $rs, $args) => $container->get(BoardController::class)->new($rq, $rs, $args));
    $app->post('/boards/{slug}', fn ($rq, $rs, $args) => $container->get(BoardController::class)->create($rq, $rs, $args));
    $app->get('/boards/{slug}', fn ($rq, $rs, $args) => $container->get(BoardController::class)->board($rq, $rs, $args));
    $app->get('/boards/{slug}/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(BoardController::class)->show($rq, $rs, $args));
    $app->post('/boards/{slug}/{id:[0-9]+}/state', fn ($rq, $rs, $args) => $container->get(BoardController::class)->toggleState($rq, $rs, $args));
    $app->post('/boards/{slug}/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(BoardController::class)->delete($rq, $rs, $args));
    $app->post('/boards/{slug}/{id:[0-9]+}/comments', fn ($rq, $rs, $args) => $container->get(BoardController::class)->comment($rq, $rs, $args));
};
