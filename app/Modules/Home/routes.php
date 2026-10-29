<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Home\HomeController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/', fn ($rq, $rs) => $container->get(HomeController::class)->index($rq, $rs));
    $app->get('/about', fn ($rq, $rs) => $container->get(HomeController::class)->about($rq, $rs));
    $app->get('/about/changelog', fn ($rq, $rs) => $container->get(HomeController::class)->changelog($rq, $rs));
    $app->get('/robots.txt', fn ($rq, $rs) => $container->get(HomeController::class)->robots($rq, $rs));
    $app->get('/sitemap.xml', fn ($rq, $rs) => $container->get(HomeController::class)->sitemap($rq, $rs));
    $app->get('/music', fn ($rq, $rs) => $container->get(HomeController::class)->music($rq, $rs));
    $app->get('/konbini', fn ($rq, $rs) => $container->get(HomeController::class)->konbini($rq, $rs));
};
