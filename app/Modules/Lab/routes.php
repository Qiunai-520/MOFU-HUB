<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Lab\LabController;
use Slim\App;

return static function (App $app, Container $container): void {
    $lab = fn () => $container->get(LabController::class);

    $app->get('/lab', fn ($rq, $rs) => $lab()->index($rq, $rs));
    $app->post('/lab/{key:[a-z0-9\-_]+}/join', fn ($rq, $rs, $args) => $lab()->join($rq, $rs, $args));
    $app->post('/lab/{key:[a-z0-9\-_]+}/leave', fn ($rq, $rs, $args) => $lab()->leave($rq, $rs, $args));
};
