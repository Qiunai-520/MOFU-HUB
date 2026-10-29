<?php

declare(strict_types=1);

namespace Mofu\Modules\Home;

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Security\Guard;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Page;
use Mofu\Core\View\Present;
use Mofu\Modules\Settings\AboutTeam;
use Mofu\Modules\Settings\HomeBanners;
use Mofu\Modules\Settings\SettingsService;
use Mofu\Modules\Membership\TaskService;
use Mofu\Modules\Posts\FeedService;
use Mofu\Modules\Posts\PostRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HomeController
{
    private const TABS = ['recommend', 'latest', 'hot', 'following'];
    private const KONBINI_SCENE_URL = '/konbini';

    /** 更新日志：Markdown 正文存在 system_settings，后台可编辑/上传 .md */
    public const CHANGELOG_KEY = 'about.changelog';

    /** 尚未上传时的默认日志（从 V1.0.0 开始） */
    public const DEFAULT_CHANGELOG = <<<'MD'
# 更新日志

## V1.0.0

- 首个正式版本：动态、评论、私信、圈子与板块、表白墙与树洞
- 成长体系：等级 Lv0-Lv6、答题转正、称号与成就、每日签到、M 币钱包与小店
- 会员体系：邮箱验证码注册、会员等级与大会员、投币支持
- 内容治理：举报与审核、封禁与申诉、公告与运营看板
- 体验：苹果风格视觉、悬浮导航与动效、主题中心、PWA 离线支持
MD;

    public function __construct(
        private readonly PostRepository $posts,
        private readonly FeedService $feed,
        private readonly Query $query,
        private readonly Page $page,
        private readonly \Mofu\Modules\Follows\FollowService $follows,
        private readonly Config $config,
        private readonly SettingsService $settings,
        private readonly TaskService $tasks,
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
            'publishedId' => max(0, (int) ($query['published'] ?? 0)),
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
            $items = array_map(static fn (array $item): array => [
                'id' => 0,
                'title' => $item['title'],
                'summary' => $item['summary'],
                'category' => ($item['type'] ?? 'image') === 'scene3d' ? '3D 场景' : '',
                'cover' => $item['image'],
                'url' => $item['link'] !== '' ? $item['link'] : '#',
                'target' => $item['target'] ?? 'self',
                'type' => $item['type'] ?? 'image',
            ], $configured);

            return $items;
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

        $items = array_map(static function (array $row): array {
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
                'target' => 'self',
                'type' => 'image',
            ];
        }, $rows);

        return $items;
    }

    public function about(Request $request, Response $response): Response
    {
        return $this->page->render($request, 'pages/about.twig', [
            'now' => date('Y-m-d H:i:s'),
            'aboutTeam' => AboutTeam::decode($this->settings->get(AboutTeam::KEY)),
        ]);
    }

    /** 更新日志页：渲染后台维护的 Markdown（未上传时用内置默认） */
    public function changelog(Request $request, Response $response): Response
    {
        $markdown = (string) ($this->settings->get(self::CHANGELOG_KEY, '') ?? '');

        if (trim($markdown) === '') {
            $generated = $this->generatedChangelog();
            $markdown = $generated === '' ? self::DEFAULT_CHANGELOG : $generated;
        }

        return $this->page->render($request, 'pages/changelog.twig', [
            'title' => '更新日志',
            'logHtml' => Text::renderContent($markdown),
            'updatedAt' => (string) ($this->settings->get(self::CHANGELOG_KEY . '.updated_at', '') ?? ''),
        ]);
    }

    /** 构建脚本生成的更新文件（public/version.json）：没有自定义日志时用它渲染 */
    private function generatedChangelog(): string
    {
        $path = rtrim((string) $this->config->get('root', ''), '/') . '/public/version.json';

        if (!is_file($path)) {
            return '';
        }

        $data = json_decode((string) file_get_contents($path), true);
        $history = is_array($data) && is_array($data['history'] ?? null) ? $data['history'] : [];

        if ($history === []) {
            return '';
        }

        $lines = ['# 更新日志'];

        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $lines[] = '';
            $lines[] = '## v' . (string) ($entry['version'] ?? '') . ' · ' . (string) ($entry['date'] ?? '');
            $lines[] = '';
            $lines[] = (string) ($entry['summary'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * 音乐入口：播放器是独立应用（MOFU 风格的 Solara，部署在 Cloudflare Pages），
     * 这里按 MUSIC_APP_URL 内嵌；未配置时给出占位与配置说明。
     */
    public function music(Request $request, Response $response): Response
    {
        return $this->page->render($request, 'pages/music.twig', [
            'musicAppUrl' => (string) $this->config->get('music.app_url', ''),
        ]);
    }

    /** 独立 3D 场景：日本街角便利店。 */
    public function konbini(Request $request, Response $response): Response
    {
        return $this->page->render($request, 'pages/konbini.twig', [
            'title' => '街角便利店',
        ]);
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
            [self::KONBINI_SCENE_URL, '0.6'],
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
