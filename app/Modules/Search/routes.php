<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Search\SearchController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/search', fn ($rq, $rs) => $container->get(SearchController::class)->index($rq, $rs));
    $app->get('/api/v1/search/suggest', fn ($rq, $rs) => $container->get(SearchController::class)->suggest($rq, $rs));
};
