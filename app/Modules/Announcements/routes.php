<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Announcements\AnnouncementApiController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $api = fn () => $container->get(AnnouncementApiController::class);

        $group->get('/announcements', fn ($rq, $rs) => $api()->feed($rq, $rs));
        $group->post('/announcements/read', fn ($rq, $rs) => $api()->read($rq, $rs));
        $group->post('/announcements/{id:[0-9]+}/dismiss', fn ($rq, $rs, $args) => $api()->dismiss($rq, $rs, $args));
    });
};
