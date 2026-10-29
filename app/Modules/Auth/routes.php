<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Auth\AuthController;
use Slim\App;

return static function (App $app, Container $container): void {
    $auth = fn () => $container->get(AuthController::class);

    $app->get('/login', fn ($rq, $rs) => $auth()->showLogin($rq, $rs));
    $app->post('/login', fn ($rq, $rs) => $auth()->login($rq, $rs));
    $app->post('/logout', fn ($rq, $rs) => $auth()->logout($rq, $rs));

    // 邮箱注册：填写邮箱 → 收验证码 → 创建账号
    $app->get('/register', fn ($rq, $rs) => $auth()->showRegister($rq, $rs));
    $app->post('/register', fn ($rq, $rs) => $auth()->sendRegisterCode($rq, $rs));
    $app->get('/register/verify', fn ($rq, $rs) => $auth()->showRegister($rq, $rs));
    $app->post('/register/verify', fn ($rq, $rs) => $auth()->verifyRegister($rq, $rs));

    // 邮箱重置 / 设置密码
    $app->get('/password/reset', fn ($rq, $rs) => $auth()->showReset($rq, $rs));
    $app->post('/password/reset', fn ($rq, $rs) => $auth()->sendResetCode($rq, $rs));
    $app->post('/password/reset/verify', fn ($rq, $rs) => $auth()->verifyReset($rq, $rs));
};
