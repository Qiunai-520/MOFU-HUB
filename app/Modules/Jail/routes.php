<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Jail\JailController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/jail', fn ($rq, $rs) => $container->get(JailController::class)->index($rq, $rs));
};
