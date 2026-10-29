<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Jury\JuryController;
use Slim\App;

return static function (App $app, Container $container): void {
    $jury = fn () => $container->get(JuryController::class);

    $app->get('/jury', fn ($rq, $rs) => $jury()->index($rq, $rs));
    $app->post('/jury/apply', fn ($rq, $rs) => $jury()->apply($rq, $rs));
    $app->post('/jury/cases/{id:[0-9]+}/vote', fn ($rq, $rs, $args) => $jury()->vote($rq, $rs, $args));
};
