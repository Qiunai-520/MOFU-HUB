<?php

declare(strict_types=1);

namespace Mofu\Bootstrap;

/**
 * 业务模块服务注册中心。
 * 有标量参数的叶子服务在此显式注册；其余服务与控制器通过构造器类型自动装配。
 */
final class Services
{
    /** @var list<class-string> */
    private const AUTOWIRED = [
        // 基础设施
        \Mofu\Core\Support\AuditLogger::class,
        \Mofu\Core\View\Page::class,

        // 仓储与服务
        \Mofu\Modules\Users\UserRepository::class,
        \Mofu\Modules\Users\FavoriteService::class,
        \Mofu\Modules\Posts\PostRepository::class,
        \Mofu\Modules\Posts\PostService::class,
        \Mofu\Modules\Posts\FeedService::class,
        \Mofu\Modules\Comments\CommentRepository::class,
        \Mofu\Modules\Comments\CommentService::class,
        \Mofu\Modules\Reactions\ReactionService::class,
        \Mofu\Modules\Notifications\NotificationService::class,
        \Mofu\Modules\Settings\SettingsService::class,
        \Mofu\Modules\Settings\PreferenceService::class,
        \Mofu\Modules\Appeals\AppealService::class,
        \Mofu\Modules\Themes\ThemeService::class,
        \Mofu\Modules\Themes\ThemeBootstrap::class,
        \Mofu\Modules\Confessions\ConfessionRepository::class,
        \Mofu\Modules\Confessions\ConfessionService::class,
        \Mofu\Modules\Comments\CommentExporter::class,
        \Mofu\Modules\Follows\FollowService::class,
        \Mofu\Modules\Users\BlockService::class,
        \Mofu\Modules\Messages\MessageService::class,
        \Mofu\Modules\Reports\ReportService::class,
        \Mofu\Modules\Search\SearchService::class,
        \Mofu\Modules\Communities\CommunityService::class,
        \Mofu\Modules\Boards\BoardService::class,
        \Mofu\Modules\Events\EventService::class,
        \Mofu\Modules\News\NewsService::class,
        \Mofu\Modules\History\HistoryService::class,

        // 控制器
        \Mofu\Modules\Auth\AuthController::class,
        \Mofu\Modules\Home\HomeController::class,
        \Mofu\Modules\Posts\PostController::class,
        \Mofu\Modules\Posts\PostApiController::class,
        \Mofu\Modules\Media\MediaApiController::class,
        \Mofu\Modules\Comments\CommentApiController::class,
        \Mofu\Modules\Reactions\ReactionApiController::class,
        \Mofu\Modules\Notifications\NotificationApiController::class,
        \Mofu\Modules\Users\UserController::class,
        \Mofu\Modules\Settings\SettingsController::class,
        \Mofu\Modules\Themes\ThemeController::class,
        \Mofu\Modules\Themes\ThemeApiController::class,
        \Mofu\Modules\Admin\AdminController::class,
        \Mofu\Modules\Confessions\ConfessionController::class,
        \Mofu\Modules\Confessions\ConfessionApiController::class,
        \Mofu\Modules\Follows\FollowApiController::class,
        \Mofu\Modules\Reports\ReportApiController::class,
        \Mofu\Modules\Search\SearchController::class,
        \Mofu\Modules\Messages\MessageController::class,
        \Mofu\Modules\Messages\MessageApiController::class,
        \Mofu\Modules\Users\BlockApiController::class,
        \Mofu\Modules\Appeals\AppealController::class,
        \Mofu\Modules\Communities\CommunityController::class,
        \Mofu\Modules\Communities\CommunityApiController::class,
        \Mofu\Modules\Boards\BoardController::class,
        \Mofu\Modules\Events\EventController::class,
        \Mofu\Modules\News\NewsController::class,
        \Mofu\Modules\History\HistoryController::class,
        \Mofu\Modules\Stream\StreamController::class,
    ];

    public static function register(Container $container): void
    {
        // 需要标量参数的叶子服务
        $root = (string) $container->get(Config::class)->get('root');

        $container->set(\Mofu\Core\Security\RateLimiter::class, fn () => new \Mofu\Core\Security\RateLimiter(
            $root . '/storage/cache',
        ));

        $container->set(\Mofu\Modules\Media\MediaService::class, fn (Container $c) => new \Mofu\Modules\Media\MediaService(
            $c->get(\Mofu\Core\Database\Query::class),
            $root . '/public/uploads',
            ((int) $container->get(Config::class)->get('upload.max_mb', 8)) * 1048576,
            ((int) $container->get(Config::class)->get('upload.video_max_mb', 60)) * 1048576,
        ));

        foreach (self::AUTOWIRED as $class) {
            $container->set($class, static fn (Container $c) => $c->autowire($class));
        }
    }
}
