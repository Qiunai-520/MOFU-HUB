<?php

declare(strict_types=1);

namespace Mofu\Bootstrap;

use Mofu\Core\Database\Connection;
use Mofu\Core\Database\Query;
use Mofu\Core\Http\Middleware\CsrfMiddleware;
use Mofu\Core\Http\Middleware\SessionMiddleware;
use Mofu\Core\Security\Auth;
use Mofu\Core\Security\SessionManager;
use Mofu\Core\Support\Logger;
use Mofu\Core\View\TwigRenderer;
use Mofu\Core\View\ViteAssets;
use Mofu\Modules\Users\UserRepository;
use PDO;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

final class App
{
    public static function create(string $rootPath): SlimApp
    {
        date_default_timezone_set('Asia/Shanghai');

        $config = Config::fromEnvironment($rootPath);

        $container = new Container();
        $container->instance(Config::class, $config);
        $container->instance(Container::class, $container);

        self::registerCoreServices($container, $config, $rootPath);
        Services::register($container);

        $app = AppFactory::create();
        $container->instance(SlimApp::class, $app);

        // 洋葱模型：后添加的中间件先执行（位于外层）。
        // 目标执行顺序（外 → 内）：错误处理 → 路由 → Body 解析 → 会话 → CSRF → 处理器。
        $app->add(new CsrfMiddleware());
        $app->add(new SessionMiddleware(
            $container->get(SessionManager::class),
            $container->get(UserRepository::class),
        ));
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(new ErrorHandler($container, $config));

        self::registerRoutes($app, $container, $rootPath);

        return $app;
    }

    private static function registerCoreServices(Container $container, Config $config, string $rootPath): void
    {
        $container->set(Logger::class, fn (): Logger => new Logger(
            $rootPath . '/storage/logs/app.log',
            (bool) $config->get('app.debug'),
        ));

        $container->set(PDO::class, fn (): PDO => Connection::connect($config));

        $container->set(Query::class, fn (Container $c): Query => new Query($c->get(PDO::class)));

        $container->set(ViteAssets::class, fn (): ViteAssets => new ViteAssets(
            $rootPath . '/public/assets/manifest.json',
            (string) $config->get('app.vite_dev_server', ''),
        ));

        $container->set(TwigRenderer::class, fn (Container $c): TwigRenderer => new TwigRenderer(
            $rootPath . '/app/Views',
            $rootPath . '/storage/cache/twig',
            (bool) $config->get('app.debug'),
            $c->get(ViteAssets::class),
        ));

        $container->set(SessionManager::class, fn (Container $c): SessionManager => new SessionManager(
            $c->get(Query::class),
            $config,
        ));

        $container->set(Auth::class, fn (Container $c): Auth => new Auth(
            $c->get(Query::class),
            $c->get(SessionManager::class),
        ));

        $container->set(UserRepository::class, fn (Container $c): UserRepository => new UserRepository(
            $c->get(Query::class),
        ));
    }

    /**
     * 自动装载各模块的路由注册文件：Modules 下每个模块的 routes.php
     * 需返回 callable(SlimApp, Container)。
     */
    private static function registerRoutes(SlimApp $app, Container $container, string $rootPath): void
    {
        foreach (glob($rootPath . '/app/Modules/*/routes.php') ?: [] as $file) {
            $register = require $file;

            if (is_callable($register)) {
                $register($app, $container);
            }
        }
    }
}
