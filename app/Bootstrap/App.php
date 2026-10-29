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
use Mofu\Modules\Moderation\BanMiddleware;
use Mofu\Modules\Moderation\BanService;
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
        // 会话之后、CSRF 之前：此时已拿到当前用户，可对受限账号做跳转/拦截
        $app->add(new BanMiddleware($container->get(BanService::class)));
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

        $viewsPath = $rootPath . '/app/Views';

        $container->set(TwigRenderer::class, fn (Container $c): TwigRenderer => new TwigRenderer(
            $viewsPath,
            $rootPath . '/storage/cache/twig/' . self::templateCacheKey($viewsPath),
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

    /**
     * 生产模式 Twig 不自动重载。把模板数量与最近修改时间写入缓存目录，
     * 发布新模板后自动切换缓存键，避免用户看到旧布局。
     */
    private static function templateCacheKey(string $viewsPath): string
    {
        $count = 0;
        $latest = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($viewsPath, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'twig') {
                    continue;
                }

                $count++;
                $latest = max($latest, (int) $file->getMTime());
            }
        } catch (\Throwable) {
            return 'fallback';
        }

        return substr(hash('sha256', $viewsPath . '|' . $count . '|' . $latest), 0, 12);
    }
}
