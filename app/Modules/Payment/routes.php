<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Payment\PaymentController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/pay/{orderNo}', fn ($rq, $rs, $args) => $container->get(PaymentController::class)->pay($rq, $rs, $args));
    $app->get('/payment/result', fn ($rq, $rs) => $container->get(PaymentController::class)->result($rq, $rs));
    $app->get('/api/v1/payment/ezfp/notify', fn ($rq, $rs) => $container->get(PaymentController::class)->notify($rq, $rs));
};
