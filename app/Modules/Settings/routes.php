<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Settings\SettingsController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/settings/profile', fn ($rq, $rs) => $container->get(SettingsController::class)->profile($rq, $rs));
    $app->post('/settings/profile', fn ($rq, $rs) => $container->get(SettingsController::class)->saveProfile($rq, $rs));
    $app->post('/settings/avatar', fn ($rq, $rs) => $container->get(SettingsController::class)->uploadAvatar($rq, $rs));
    $app->post('/settings/cover', fn ($rq, $rs) => $container->get(SettingsController::class)->uploadCover($rq, $rs));
    $app->post('/settings/cover/remove', fn ($rq, $rs) => $container->get(SettingsController::class)->removeCover($rq, $rs));

    $app->get('/settings/security', fn ($rq, $rs) => $container->get(SettingsController::class)->security($rq, $rs));
    $app->post('/settings/security', fn ($rq, $rs) => $container->get(SettingsController::class)->changePassword($rq, $rs));
    $app->post('/settings/security/sessions/revoke-others', fn ($rq, $rs) => $container->get(SettingsController::class)->revokeOtherSessions($rq, $rs));
    $app->post('/settings/security/sessions/{id:[a-f0-9]{64}}/revoke', fn ($rq, $rs, $args) => $container->get(SettingsController::class)->revokeSession($rq, $rs, $args));

    $app->get('/settings/notifications', fn ($rq, $rs) => $container->get(SettingsController::class)->notifications($rq, $rs));
    $app->post('/settings/notifications', fn ($rq, $rs) => $container->get(SettingsController::class)->saveNotifications($rq, $rs));

    $app->get('/settings/privacy', fn ($rq, $rs) => $container->get(SettingsController::class)->privacy($rq, $rs));
    $app->post('/settings/privacy', fn ($rq, $rs) => $container->get(SettingsController::class)->savePrivacy($rq, $rs));

    $app->get('/settings/background', fn ($rq, $rs) => $container->get(SettingsController::class)->background($rq, $rs));
    $app->post('/settings/background', fn ($rq, $rs) => $container->get(SettingsController::class)->saveBackground($rq, $rs));
    $app->post('/settings/background/upload', fn ($rq, $rs) => $container->get(SettingsController::class)->uploadBackground($rq, $rs));
};
