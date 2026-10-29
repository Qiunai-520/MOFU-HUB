<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Admin\AdminController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/admin', fn ($rq, $rs) => $container->get(AdminController::class)->index($rq, $rs));
    $app->get('/admin/dashboard', fn ($rq, $rs) => $container->get(AdminController::class)->dashboard($rq, $rs));
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

    $app->get('/admin/announcements', fn ($rq, $rs) => $container->get(AdminController::class)->announcements($rq, $rs));
    $app->get('/admin/titles', fn ($rq, $rs) => $container->get(AdminController::class)->titles($rq, $rs));
    $app->get('/admin/tags', fn ($rq, $rs) => $container->get(AdminController::class)->tags($rq, $rs));
    $app->get('/admin/jury', fn ($rq, $rs) => $container->get(AdminController::class)->jury($rq, $rs));
    $app->get('/admin/lab', fn ($rq, $rs) => $container->get(AdminController::class)->lab($rq, $rs));
    $app->get('/admin/shop', fn ($rq, $rs) => $container->get(AdminController::class)->shop($rq, $rs));
    $app->get('/admin/checkin', fn ($rq, $rs) => $container->get(AdminController::class)->checkin($rq, $rs));
    $app->get('/admin/membership', fn ($rq, $rs) => $container->get(AdminController::class)->membership($rq, $rs));
    $app->get('/admin/growth', fn ($rq, $rs) => $container->get(AdminController::class)->growth($rq, $rs));
    $app->get('/admin/economy', fn ($rq, $rs) => $container->get(AdminController::class)->economy($rq, $rs));

    $app->post('/admin/announcements', fn ($rq, $rs) => $container->get(AdminController::class)->saveAnnouncement($rq, $rs, ['id' => '0']));
    $app->post('/admin/announcements/{id:[0-9]+}/save', fn ($rq, $rs, $args) => $container->get(AdminController::class)->saveAnnouncement($rq, $rs, $args));
    $app->post('/admin/announcements/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteAnnouncement($rq, $rs, $args));
    $app->post('/admin/titles', fn ($rq, $rs) => $container->get(AdminController::class)->saveTitle($rq, $rs, ['id' => '0']));
    $app->post('/admin/tags', fn ($rq, $rs) => $container->get(AdminController::class)->saveTag($rq, $rs, ['id' => '0']));
    $app->post('/admin/tags/{id:[0-9]+}/save', fn ($rq, $rs, $args) => $container->get(AdminController::class)->saveTag($rq, $rs, $args));
    $app->post('/admin/tags/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteTag($rq, $rs, $args));
    $app->post('/admin/jury/applications/{id:[0-9]+}/decide', fn ($rq, $rs, $args) => $container->get(AdminController::class)->decideJuryApplication($rq, $rs, $args));
    $app->post('/admin/jury/members/{id:[0-9]+}/rate', fn ($rq, $rs, $args) => $container->get(AdminController::class)->rateJuryMember($rq, $rs, $args));
    $app->post('/admin/jury/members/{id:[0-9]+}/remove', fn ($rq, $rs, $args) => $container->get(AdminController::class)->removeJuryMember($rq, $rs, $args));
    $app->post('/admin/jury/cases', fn ($rq, $rs) => $container->get(AdminController::class)->openJuryCase($rq, $rs));
    $app->post('/admin/jury/cases/{id:[0-9]+}/close', fn ($rq, $rs, $args) => $container->get(AdminController::class)->closeJuryCase($rq, $rs, $args));
    $app->post('/admin/jury/stipend', fn ($rq, $rs) => $container->get(AdminController::class)->payJuryStipend($rq, $rs));
    $app->post('/admin/jury/settle', fn ($rq, $rs) => $container->get(AdminController::class)->settleJuryYear($rq, $rs));
    $app->post('/admin/lab', fn ($rq, $rs) => $container->get(AdminController::class)->saveLab($rq, $rs, ['id' => '0']));
    $app->post('/admin/lab/{id:[0-9]+}/save', fn ($rq, $rs, $args) => $container->get(AdminController::class)->saveLab($rq, $rs, $args));
    $app->post('/admin/lab/{id:[0-9]+}/toggle', fn ($rq, $rs, $args) => $container->get(AdminController::class)->toggleLab($rq, $rs, $args));
    $app->post('/admin/lab/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteLab($rq, $rs, $args));
    $app->post('/admin/titles/grant', fn ($rq, $rs) => $container->get(AdminController::class)->grantTitle($rq, $rs, []));
    $app->post('/admin/titles/achievements', fn ($rq, $rs) => $container->get(AdminController::class)->saveAchievement($rq, $rs));
    $app->post('/admin/titles/{id:[0-9]+}/save', fn ($rq, $rs, $args) => $container->get(AdminController::class)->saveTitle($rq, $rs, $args));
    $app->post('/admin/titles/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteTitle($rq, $rs, $args));
    $app->post('/admin/shop/products', fn ($rq, $rs) => $container->get(AdminController::class)->saveProduct($rq, $rs, ['id' => '0']));
    $app->post('/admin/shop/products/{id:[0-9]+}/save', fn ($rq, $rs, $args) => $container->get(AdminController::class)->saveProduct($rq, $rs, $args));
    $app->post('/admin/shop/products/{id:[0-9]+}/action', fn ($rq, $rs, $args) => $container->get(AdminController::class)->productAction($rq, $rs, $args));
    $app->post('/admin/checkin/rewards', fn ($rq, $rs) => $container->get(AdminController::class)->saveCheckinRewards($rq, $rs));
    $app->post('/admin/membership/levels', fn ($rq, $rs) => $container->get(AdminController::class)->saveLevels($rq, $rs));
    $app->post('/admin/membership/questions', fn ($rq, $rs) => $container->get(AdminController::class)->saveQuestion($rq, $rs, []));
    $app->post('/admin/membership/questions/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(AdminController::class)->deleteQuestion($rq, $rs, $args));
    $app->post('/admin/membership/grant', fn ($rq, $rs) => $container->get(AdminController::class)->grantGrowth($rq, $rs));
    $app->get('/admin/users/{id:[0-9]+}/ban', fn ($rq, $rs, $args) => $container->get(AdminController::class)->userBanForm($rq, $rs, $args));
    $app->post('/admin/users/{id:[0-9]+}/moderate', fn ($rq, $rs, $args) => $container->get(AdminController::class)->userModerate($rq, $rs, $args));

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
    $app->post('/admin/boards/create', fn ($rq, $rs) => $container->get(AdminController::class)->createBoardEntry($rq, $rs));
    $app->post('/admin/boards/{id:[0-9]+}/save', fn ($rq, $rs, $args) => $container->get(AdminController::class)->saveBoardEntry($rq, $rs, $args));
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

    // 系统与升级：虚拟主机没有终端，提供网页方式应用数据库迁移
    $app->get('/admin/system', fn ($rq, $rs) => $container->get(AdminController::class)->system($rq, $rs));
    $app->post('/admin/system/migrate', fn ($rq, $rs) => $container->get(AdminController::class)->runMigrations($rq, $rs));
    $app->post('/admin/system/backup', fn ($rq, $rs) => $container->get(AdminController::class)->exportBackup($rq, $rs));

    // 更新日志（关于页的 Markdown）
    $app->get('/admin/changelog', fn ($rq, $rs) => $container->get(AdminController::class)->changelog($rq, $rs));
    $app->post('/admin/changelog', fn ($rq, $rs) => $container->get(AdminController::class)->saveChangelog($rq, $rs));
};
