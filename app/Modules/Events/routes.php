<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Events\EventController;
use Slim\App;

return static function (App $app, Container $container): void {
    $app->get('/events', fn ($rq, $rs) => $container->get(EventController::class)->index($rq, $rs));
    $app->get('/events/mine', fn ($rq, $rs) => $container->get(EventController::class)->mine($rq, $rs));
    $app->get('/events/new', fn ($rq, $rs) => $container->get(EventController::class)->newForm($rq, $rs));
    $app->post('/events', fn ($rq, $rs) => $container->get(EventController::class)->create($rq, $rs));
    $app->get('/events/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(EventController::class)->show($rq, $rs, $args));
    $app->get('/events/{id:[0-9]+}/edit', fn ($rq, $rs, $args) => $container->get(EventController::class)->editForm($rq, $rs, $args));
    $app->post('/events/{id:[0-9]+}', fn ($rq, $rs, $args) => $container->get(EventController::class)->update($rq, $rs, $args));
    $app->post('/events/{id:[0-9]+}/signup', fn ($rq, $rs, $args) => $container->get(EventController::class)->signup($rq, $rs, $args));
    $app->post('/events/{id:[0-9]+}/signup/cancel', fn ($rq, $rs, $args) => $container->get(EventController::class)->cancelSignup($rq, $rs, $args));
    $app->post('/events/{id:[0-9]+}/signups/{userId:[0-9]+}/{action:approve|reject|remove}', fn ($rq, $rs, $args) => $container->get(EventController::class)->manageSignup($rq, $rs, $args));
    $app->post('/events/{id:[0-9]+}/cancel', fn ($rq, $rs, $args) => $container->get(EventController::class)->cancel($rq, $rs, $args));
    $app->post('/events/{id:[0-9]+}/delete', fn ($rq, $rs, $args) => $container->get(EventController::class)->delete($rq, $rs, $args));
};
