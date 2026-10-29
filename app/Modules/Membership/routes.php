<?php

declare(strict_types=1);

use Mofu\Bootstrap\Container;
use Mofu\Modules\Membership\MembershipApiController;
use Mofu\Modules\Membership\MembershipController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app, Container $container): void {
    $app->get('/membership', fn ($rq, $rs) => $container->get(MembershipController::class)->index($rq, $rs));

    $app->group('/api/v1', function (RouteCollectorProxy $group) use ($container): void {
        $api = fn () => $container->get(MembershipApiController::class);

        $group->get('/membership', fn ($rq, $rs) => $api()->status($rq, $rs));
        $group->post('/membership/tasks/{source:[a-z_]+}', fn ($rq, $rs, $args) => $api()->task($rq, $rs, $args));
        $group->get('/membership/quiz', fn ($rq, $rs) => $api()->quizPaper($rq, $rs));
        $group->post('/membership/quiz/submit', fn ($rq, $rs) => $api()->quizSubmit($rq, $rs));
        $group->post('/membership/premium-bonus', fn ($rq, $rs) => $api()->premiumBonus($rq, $rs));
        $group->post('/membership/purchase', fn ($rq, $rs) => $api()->purchase($rq, $rs));
    });
};
