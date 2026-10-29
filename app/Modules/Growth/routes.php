<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Growth\CoinApiController;
use Mofu\Modules\Growth\GrowthApiController;
use Mofu\Modules\Growth\GrowthController;
use Mofu\Modules\Growth\ShopController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/growth', fn ($rq, $rs) => $container->get(GrowthController::class)->index($rq, $rs));
    // 别名：历史通知里写的是 /me/growth，避免旧链接 404
    $app->get('/me/growth', fn ($rq, $rs) => $container->get(GrowthController::class)->index($rq, $rs));
    // MOFU小店（M币 与 在线支付 两种商品）
    $app->get('/shop', fn ($rq, $rs) => $container->get(ShopController::class)->index($rq, $rs));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $api = fn () => $container->get(GrowthApiController::class);

        $group->get('/growth', fn ($rq, $rs) => $api()->profile($rq, $rs));
        $group->get('/growth/wallet', fn ($rq, $rs) => $api()->wallet($rq, $rs));
        $group->post('/growth/checkin', fn ($rq, $rs) => $api()->checkin($rq, $rs));
        $group->get('/growth/calendar', fn ($rq, $rs) => $api()->calendar($rq, $rs));
        $group->post('/growth/title', fn ($rq, $rs) => $api()->equipTitle($rq, $rs));

        $group->get('/shop', fn ($rq, $rs) => $api()->shop($rq, $rs));
        $group->post('/shop/{id:[0-9]+}/purchase', fn ($rq, $rs, $args) => $api()->purchase($rq, $rs, $args));
        $group->post('/shop/{id:[0-9]+}/pay', fn ($rq, $rs, $args) => $api()->payProduct($rq, $rs, $args));
        $group->get('/shop/orders', fn ($rq, $rs) => $api()->orders($rq, $rs));

        // M币投币
        $coins = fn () => $container->get(CoinApiController::class);
        $group->get('/posts/{id:[0-9]+}/coins', fn ($rq, $rs, $args) => $coins()->summary($rq, $rs, $args));
        $group->post('/posts/{id:[0-9]+}/coins', fn ($rq, $rs, $args) => $coins()->give($rq, $rs, $args));
    });
};
