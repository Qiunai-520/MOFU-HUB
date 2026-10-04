<?php

declare(strict_types=1);

namespace Mofu\Modules\Home;

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Core\View\Present;
use Mofu\Modules\Settings\AboutTeam;
use Mofu\Modules\Settings\HomeBanners;
use Mofu\Modules\Settings\SettingsService;
use Mofu\Modules\Posts\FeedService;
use Mofu\Modules\Posts\PostRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HomeController
{
    private const TABS = ['recommend', 'latest', 'hot', 'following'];

    public function __construct(
        private readonly PostRepository $posts,
        private readonly FeedService $feed,
        private readonly Query $query,
        private readonly Page $page,
        private readonly \Mofu\Modules\Follows\FollowService $follows,
        private readonly Config $config,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $state = Ctx::state($request);
        $query = $request->getQueryParams();
        $tab = in_array($query['tab'] ?? '', self::TABS, true) ? (string) $query['tab'] : 'latest';

        // 未登录无法查看关注流，回退到最新
        if ($tab === 'following' && $state->user === null) {
            $tab = 'latest';
        }

        $result = $this->posts->feed($tab, 1, 10, $state->userId());
        $items = $this->feed->presentRows(
            $result['items'],
            $state->userId(),
            Guard::can($state->user, 'post.moderate'),
        );

        $topics = array_map(static fn (array $row): array => [
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'use_count' => (int) $row['use_count'],
        ], $this->query->all(
            'SELECT name, slug, use_count FROM topics ORDER BY use_count DESC, id DESC LIMIT 12',
        ));

        $suggestions = $state->user !== null
            ? $this->follows->suggestions((int) $state->user['id'], 4)
            : [];

        return $this->page->render($request, 'pages/home.twig', [
            'tab' => $tab,
            'feed' => $result,
            'feedItems' => $items,
            'topics' => $topics,
            'suggestions' => $suggestions,
            'followingEmpty' => $tab === 'following' && $result['total'] === 0,
            'banners' => $this->bannerItems(),
        ]);
    }

    /**
     * 首页横幅轮播。
     *
     * 优先用后台维护的轮播图（设置 → 首页轮播）；未配置时回退到
     * 「置顶优先的已发布资讯」，保证没配也有内容可展示。
     *
     * @return list<array<string, mixed>>
     */
    private function bannerItems(): array
    {
        $configured = HomeBanners::decode($this->settings->get(HomeBanners::KEY));

        if ($configured !== []) {
            return array_map(static fn (array $item): array => [
                'id' => 0,
                'title' => $item['title'],
                'summary' => $item['summary'],
                'category' => '',
                'cover' => $item['image'],
                'url' => $item['link'] !== '' ? $item['link'] : '#',
            ], $configured);
        }

        $rows = $this->query->all(
            "SELECT n.id, n.title, n.summary, n.category,
                    am.disk_path AS cover_path, am.thumb_path AS cover_thumb
             FROM news n
             LEFT JOIN media am ON am.id = n.cover_media_id
             WHERE n.status = 'published' AND n.deleted_at IS NULL
             ORDER BY n.is_pinned DESC, COALESCE(n.published_at, n.created_at) DESC
             LIMIT 5",
        );

        return array_map(static function (array $row): array {
            $slug = match ((string) $row['category']) {
                'notice' => '公告',
                'resource' => '资源',
                default => '校园资讯',
            };

            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'summary' => (string) $row['summary'],
                'category' => $slug,
                'cover' => Present::uploadUrl(
                    isset($row['cover_thumb']) ? (string) $row['cover_thumb'] : null,
                    isset($row['cover_path']) ? (string) $row['cover_path'] : null,
                ),
                'url' => '/news/' . (int) $row['id'],
            ];
        }, $rows);
    }

    public function about(Request $request, Response $response): Response
    {
        return $this->page->render($request, 'pages/about.twig', [
            'now' => date('Y-m-d H:i:s'),
            'aboutTeam' => AboutTeam::decode($this->settings->get(AboutTeam::KEY)),
        ]);
    }

    /** 音乐聚合入口（占位：音源待接入） */
    public function music(Request $request, Response $response): Response
    {
        return $this->page->render($request, 'pages/music.twig');
    }

    /** robots.txt：按站点地址输出绝对 Sitemap 地址 */
    public function robots(Request $request, Response $response): Response
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');

        $body = "User-agent: *\n"
            . "Allow: /\n"
            . "Disallow: /admin\n"
            . "Disallow: /settings\n"
            . "Disallow: /login\n"
            . "Disallow: /register\n"
            . "Disallow: /api/\n\n"
            . 'Sitemap: ' . $base . "/sitemap.xml\n";

        $response->getBody()->write($body);

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /** sitemap.xml：按站点地址输出绝对 URL（相对地址不符合协议规范） */
    public function sitemap(Request $request, Response $response): Response
    {
        $base = rtrim((string) $this->config->get('app.url', ''), '/');
        $paths = [
            ['/', '1.0'],
            ['/boards', '0.8'],
            ['/circles', '0.7'],
            ['/news', '0.7'],
            ['/events', '0.7'],
            ['/search', '0.6'],
        ];

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($paths as [$path, $priority]) {
            $xml .= sprintf(
                "  <url><loc>%s</loc><priority>%s</priority></url>\n",
                htmlspecialchars($base . $path, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                $priority,
            );
        }

        $xml .= "</urlset>\n";

        $response->getBody()->write($xml);

        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}
