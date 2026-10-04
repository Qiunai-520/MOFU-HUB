<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Admin\AdminController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/admin', fn ($rq, $rs) => $container->get(AdminController::class)->dashboard($rq, $rs));
    $app->get('/admin/users', fn ($rq, $rs) => $container->get(AdminController::class)->users($rq, $rs));
    $app->get('/admin/posts', fn ($rq, $rs) => $container->get(AdminController::class)->posts($rq, $rs));
    $app->get('/admin/comments', fn ($rq, $rs) => $container->get(AdminController::class)->comments($rq, $rs));
    $app->get('/admin/confessions', fn ($rq, $rs) => $container->get(AdminController::class)->confessions($rq, $rs));
    $app->get('/admin/reports', fn ($rq, $rs) => $container->get(AdminController::class)->reports($rq, $rs));
    $app->get('/admin/appeals', fn ($rq, $rs) => $container->get(AdminController::class)->appeals($rq, $rs));
    $app->get('/admin/circles', fn ($rq, $rs) => $container->get(AdminController::class)->circles($rq, $rs));
    $app->get('/admin/boards', fn ($rq, $rs) => $container->get(AdminController::class)->boardEntries($rq, $rs));
    $app->get('/admin/board-settings', fn ($rq, $rs) => $container->get(AdminController::class)->boardSettings($rq, $rs));
    $app->get('/admin/team', fn ($rq, $rs) => $container->get(AdminController::class)->team($rq, $rs));
    $app->get('/admin/banners', fn ($rq, $rs) => $container->get(AdminController::class)->banners($rq, $rs));
    $app->get('/admin/comments/export-twikoo', fn ($rq, $rs) => $container->get(AdminController::class)->exportCommentsTwikoo($rq, $rs));
    $app->get('/admin/events', fn ($rq, $rs) => $container->get(AdminController::class)->events($rq, $rs));
    $app->get('/admin/news', fn ($rq, $rs) => $container->get(AdminController::class)->news($rq, $rs));
    $app->get('/admin/news/new', fn ($rq, $rs) => $container->get(AdminController::class)->newsNew($rq, $rs));

    $app->post('/admin/users/{id:[0-9]+}/status', fn ($rq, $rs, $args) => $container->get(AdminController::class)->setUserStatus($rq, $rs, $args));
    $app->post('/admin/users/{id:[0-9]+}/role', fn ($rq, $rs, $args) => $container->get(AdminController::class)->setUserRole($rq, $rs, $args));
    $app->post('/admin/posts/{id:[0-9]+}/status', fn ($rq, $rs, $args) => $container->get(AdminController::class)->setPostStatus($rq, $rs, $args));
    $app->post('/admin/posts/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deletePost($rq, $rs, $args));
    $app->post('/admin/comments/{id:[0-9]+}/remove', fn ($rq, $rs, $args) => $container->get(AdminController::class)->removeComment($rq, $rs, $args));
    $app->post('/admin/confessions/{id:[0-9]+}/moderate', fn ($rq, $rs, $args) => $container->get(AdminController::class)->moderateConfession($rq, $rs, $args));
    $app->post('/admin/reports/{id:[0-9]+}/resolve', fn ($rq, $rs, $args) => $container->get(AdminController::class)->resolveReport($rq, $rs, $args));
    $app->post('/admin/appeals/{id:[0-9]+}/resolve', fn ($rq, $rs, $args) => $container->get(AdminController::class)->resolveAppeal($rq, $rs, $args));
    $app->post('/admin/circles/{id:[0-9]+}/active', fn ($rq, $rs, $args) => $container->get(AdminController::class)->setCircleActive($rq, $rs, $args));
    $app->post('/admin/circles/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteCircle($rq, $rs, $args));
    $app->post('/admin/boards/{id:[0-9]+}/status', fn ($rq, $rs, $args) => $container->get(AdminController::class)->setEntryStatus($rq, $rs, $args));
    $app->post('/admin/boards/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteEntry($rq, $rs, $args));
    $app->post('/admin/boards/{id:[0-9]+}/logo', fn ($rq, $rs, $args) => $container->get(AdminController::class)->saveBoardLogo($rq, $rs, $args));
    $app->post('/admin/team', fn ($rq, $rs) => $container->get(AdminController::class)->saveTeam($rq, $rs));
    $app->post('/admin/banners', fn ($rq, $rs) => $container->get(AdminController::class)->saveBanners($rq, $rs));
    $app->post('/admin/events/{id:[0-9]+}/status', fn ($rq, $rs, $args) => $container->get(AdminController::class)->setEventStatus($rq, $rs, $args));
    $app->post('/admin/events/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteEvent($rq, $rs, $args));
    $app->post('/admin/news', fn ($rq, $rs) => $container->get(AdminController::class)->newsCreate($rq, $rs));
    $app->get('/admin/news/{id:[0-9]+}/edit', fn ($rq, $rs, $args) => $container->get(AdminController::class)->newsEdit($rq, $rs, $args));
    $app->post('/admin/news/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(AdminController::class)->newsUpdate($rq, $rs, $args));
    $app->post('/admin/news/{id:[0-9]+}/status', fn ($rq, $rs, $args) => $container->get(AdminController::class)->newsStatus($rq, $rs, $args));
    $app->post('/admin/news/{id:[0-9]+}/pin', fn ($rq, $rs, $args) => $container->get(AdminController::class)->newsPin($rq, $rs, $args));
    $app->post('/admin/news/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->newsDelete($rq, $rs, $args));
};
