<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\History\HistoryController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/history', fn ($rq, $rs) => $container->get(HistoryController::class)->index($rq, $rs));
    $app->post('/history/clear', fn ($rq, $rs) => $container->get(HistoryController::class)->clear($rq, $rs));
};
