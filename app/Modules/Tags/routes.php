<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Tags\TagController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/tags', fn ($rq, $rs) => $container->get(TagController::class)->index($rq, $rs));
    $app->get('/tags/{slug}', fn ($rq, $rs, $args) => $container->get(TagController::class)->show($rq, $rs, $args));
};
