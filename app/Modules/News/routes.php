<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\News\NewsController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/news', fn ($rq, $rs) => $container->get(NewsController::class)->index($rq, $rs));
    $app->get('/news/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(NewsController::class)->show($rq, $rs, $args));
};
