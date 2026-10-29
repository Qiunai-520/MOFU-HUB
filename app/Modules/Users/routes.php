<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Users\BlockApiController;
use Mofu\Modules\Users\UserController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/me', fn ($rq, $rs) => $container->get(UserController::class)->me($rq, $rs));
    $app->get('/u/{username:[a-z0-9_]+}', fn ($rq, $rs, $args) => $container->get(UserController::class)->show($rq, $rs, $args));
    // 某人的全部动态（主页默认只显示 2 条，从这里进完整列表 + 搜索）
    $app->get('/u/{username:[a-z0-9_]+}/posts', fn ($rq, $rs, $args) => $container->get(UserController::class)->show($rq, $rs, ['username' => $args['username'], 'all' => '1']));
    // Twikoo 头像源：按邮箱哈希返回站内头像（sha256 64 位 / md5 32 位都支持）
    $app->get('/avatar/{hash:[0-9a-fA-F]{32,64}}', fn ($rq, $rs, $args) => $container->get(UserController::class)->avatar($rq, $rs, $args));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $group->post('/blocks/toggle', fn ($rq, $rs) => $container->get(BlockApiController::class)->toggle($rq, $rs));
        $group->get('/users/search', fn ($rq, $rs) => $container->get(UserController::class)->search($rq, $rs));
    });
};
