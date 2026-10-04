<?php

declare(strict_types=1);

namespace Mofu\Core\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Twig 模板渲染。模板自动转义（用户内容一律经过 html 转义）。
 */
final class TwigRenderer
{
    private Environment $twig;

    public function __construct(
        string $viewsPath,
        string $cachePath,
        bool $debug,
        private readonly ViteAssets $vite,
    ) {
        $loader = new FilesystemLoader($viewsPath);

        $this->twig = new Environment($loader, [
            'cache' => $debug ? false : $cachePath,
            'autoescape' => 'html',
            'strict_variables' => false,
            'debug' => $debug,
        ]);

        $this->twig->addFunction(new TwigFunction(
            'vite_asset',
            fn (string $entry): string => $this->vite->entry($entry),
            ['is_safe' => ['html']],
        ));

        if ($debug) {
            $this->twig->addExtension(new \Twig\Extension\DebugExtension());
        }
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
