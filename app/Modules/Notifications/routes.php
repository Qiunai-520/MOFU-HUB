<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Messages\MessageController;
use Mofu\Modules\Notifications\NotificationApiController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    // 通知与私信是同一个消息中心（同一模板），默认标签为通知
    $app->get('/notifications', fn ($rq, $rs) => $container->get(MessageController::class)->inbox($rq, $rs, 'notifications'));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/notifications', fn ($rq, $rs) => $container->get(NotificationApiController::class)->index($rq, $rs));
        $group->get('/notifications/unread-count', fn ($rq, $rs) => $container->get(NotificationApiController::class)->unreadCount($rq, $rs));
        $group->post('/notifications/read', fn ($rq, $rs) => $container->get(NotificationApiController::class)->markRead($rq, $rs));
    });
};
