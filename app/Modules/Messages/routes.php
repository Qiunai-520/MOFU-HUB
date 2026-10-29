<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Messages\MessageApiController;
use Mofu\Modules\Messages\MessageController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/messages', fn ($rq, $rs) => $container->get(MessageController::class)->index($rq, $rs));
    $app->get('/messages/with/{username}', fn ($rq, $rs, $args) => $container->get(MessageController::class)->openWith($rq, $rs, $args));
    $app->get('/messages/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(MessageController::class)->show($rq, $rs, $args));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/messages/unread-count', fn ($rq, $rs) => $container->get(MessageApiController::class)->unreadCount($rq, $rs));
        $group->get('/conversations/{id:[0-9]+}/messages', fn ($rq, $rs, $args) => $container->get(MessageApiController::class)->messages($rq, $rs, $args));
        $group->post('/conversations/{id:[0-9]+}/messages', fn ($rq, $rs, $args) => $container->get(MessageApiController::class)->send($rq, $rs, $args));
        // 撤回自己的消息（2 分钟内）
        $group->post('/messages/{id:[0-9]+}/recall', fn ($rq, $rs, $args) => $container->get(MessageApiController::class)->recall($rq, $rs, $args));
        // 把某个会话标为已读（会话列表长按菜单用）
        $group->post('/conversations/{id:[0-9]+}/read', fn ($rq, $rs, $args) => $container->get(MessageApiController::class)->markRead($rq, $rs, $args));
    });
};
