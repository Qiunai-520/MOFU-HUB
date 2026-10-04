<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Stream\StreamController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/api/v1/stream', fn ($rq, $rs) => $container->get(StreamController::class)->stream($rq, $rs));
};
