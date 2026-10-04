<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Appeals\AppealController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/appeal', fn ($rq, $rs) => $container->get(AppealController::class)->show($rq, $rs));
    $app->post('/appeal', fn ($rq, $rs) => $container->get(AppealController::class)->submit($rq, $rs));
};
