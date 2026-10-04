<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Auth\AuthController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/login', fn ($rq, $rs) => $container->get(AuthController::class)->showLogin($rq, $rs));
    $app->post('/login', fn ($rq, $rs) => $container->get(AuthController::class)->login($rq, $rs));
    $app->get('/register', fn ($rq, $rs) => $container->get(AuthController::class)->showRegister($rq, $rs));
    $app->post('/register', fn ($rq, $rs) => $container->get(AuthController::class)->register($rq, $rs));
    $app->post('/logout', fn ($rq, $rs) => $container->get(AuthController::class)->logout($rq, $rs));
};
