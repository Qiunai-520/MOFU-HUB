<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Http\Json;
use Mofu\Core\Security\Guard;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Page;
use Mofu\Core\View\Present;
use Mofu\Modules\Confessions\ConfessionService;
use Mofu\Modules\Follows\FollowService;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Growth\TitleService;
use Mofu\Modules\Messages\MessageService;
use Mofu\Modules\Posts\FeedService;
use Mofu\Modules\Posts\PostRepository;
use Mofu\Modules\Settings\PreferenceService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class UserController
{
    private const TABS = ['posts', 'favorites', 'likes', 'comments', 'following', 'followers'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly PostRepository $posts,
        private readonly FeedService $feed,
        private readonly FollowService $follows,
        private readonly FavoriteService $favorites,
        private readonly ConfessionService $confessions,
        private readonly BlockService $blocks,
        private readonly MessageService $messages,
        private readonly PreferenceService $preferences,
        private readonly Page $page,
        private readonly LevelService $levels,
        private readonly Query $query,
    ) {
    }

    /** 个人资料（我的）页：资料摘要 + 设置与侧栏功能入口 */
    public function me(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $profileUser = $this->users->findByUsername((string) $user['username']);

        if ($profileUser === null) {
            throw HttpException::notFound('用户不存在');
        }

        $userId = (int) $profileUser['id'];
        $nickname = trim((string) ($profileUser['nickname'] ?? '')) !== ''
            ? (string) $profileUser['nickname']
            : (string) $profileUser['username'];
        $stats = $this->levels->stats($userId);
        $titleRow = $this->query->one(
            'SELECT t.name, t.icon FROM titles t JOIN users u
               ON t.id = COALESCE(u.equipped_title_id, ' . TitleService::equippedTitleExpr('u.id') . ')
             WHERE u.id = :id AND t.active = 1',
            ['id' => $userId],
        );

        // 主页资料卡所需数据（与 /u/<username> 的 hero 保持一致）
        $counts = $this->query->one(
            'SELECT
                (SELECT COUNT(*) FROM posts WHERE user_id = :post_user AND deleted_at IS NULL) AS posts_count,
                (SELECT COALESCE(SUM(like_count), 0) FROM posts WHERE user_id = :like_user AND deleted_at IS NULL) AS likes_received',
            ['post_user' => $userId, 'like_user' => $userId],
        );

        return $this->page->render($request, 'pages/me.twig', [
            'profile' => [
                'id' => $userId,
                'username' => (string) $profileUser['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($userId),
                'bio' => (string) ($profileUser['bio'] ?? ''),
                'location' => (string) ($profileUser['location'] ?? ''),
                'website' => (string) ($profileUser['website'] ?? ''),
                'joined_at' => date('Y-m-d', strtotime((string) $profileUser['created_at']) ?: time()),
                'mid' => $userId,
                'posts_count' => (int) ($counts['posts_count'] ?? 0),
                'likes_received' => (int) ($counts['likes_received'] ?? 0),
                'cover_url' => Present::uploadUrl(
                    isset($profileUser['cover_thumb']) ? (string) $profileUser['cover_thumb'] : null,
                    isset($profileUser['cover_path']) ? (string) $profileUser['cover_path'] : null,
                ),
                'followers_count' => $this->follows->followersCount($userId),
                'following_count' => $this->follows->followingCount($userId),
                'level_number' => (int) ($stats['level'] ?? 0),
                'is_formal' => (bool) ($stats['is_formal'] ?? false),
                'is_flash' => (bool) ($stats['is_flash'] ?? false),
                'is_premium' => (bool) ($stats['is_premium'] ?? false),
                'title' => $titleRow === null ? null : [
                    'name' => (string) $titleRow['name'],
                    'icon' => (string) ($titleRow['icon'] ?? 'military_tech'),
                ],
                'avatar_url' => Present::uploadUrl(
                    isset($profileUser['avatar_thumb']) ? (string) $profileUser['avatar_thumb'] : null,
                    isset($profileUser['avatar_path']) ? (string) $profileUser['avatar_path'] : null,
                ),
                ...ProfileDecorations::present($profileUser),
            ],
        ]);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $username = (string) ($args['username'] ?? '');
        $profileUser = $this->users->findByUsername($username);

        if ($profileUser === null || ($profileUser['status'] ?? 'active') === 'banned') {
            throw HttpException::notFound('用户不存在');
        }

        $state = Ctx::state($request);
        $viewerId = $state->userId();
        $canModerate = Guard::can($state->user, 'post.moderate');
        $userId = (int) $profileUser['id'];
        $isSelf = $viewerId !== null && $viewerId === $userId;

        $query = $request->getQueryParams();
        $tab = in_array($query['tab'] ?? '', self::TABS, true) ? (string) $query['tab'] : 'posts';
        $page = max(1, (int) ($query['page'] ?? 1));
        // 「全部动态」页：/u/<用户名>/posts（主页默认只显示 2 条，从这里进完整列表 + 搜索）
        $allPosts = (string) ($args['all'] ?? '') === '1';
        $keyword = $allPosts ? trim((string) ($query['q'] ?? '')) : '';

        $nickname = trim((string) ($profileUser['nickname'] ?? '')) !== ''
            ? (string) $profileUser['nickname']
            : (string) $profileUser['username'];

        $canMessage = $viewerId !== null && !$isSelf
            ? $this->messages->canMessage($viewerId, $userId)['allowed']
            : false;
        $isBlocked = $viewerId !== null && $this->blocks->isBlocked($viewerId, $userId);
        $connectionsHidden = !$isSelf
            && $this->preferences->privacyPrefs($userId)['connections_visibility'] === 'self';

        $stats = $this->levels->stats($userId);

        // 主页头部数据：动态数与获赞数（一次查询，注意不要复用同一命名占位符）
        $counts = $this->query->one(
            'SELECT
                (SELECT COUNT(*) FROM posts WHERE user_id = :post_user AND deleted_at IS NULL) AS posts_count,
                (SELECT COALESCE(SUM(like_count), 0) FROM posts WHERE user_id = :like_user AND deleted_at IS NULL) AS likes_received',
            ['post_user' => $userId, 'like_user' => $userId],
        );
        $titleRow = $this->query->one(
            'SELECT t.name, t.icon FROM titles t JOIN users u
               ON t.id = COALESCE(u.equipped_title_id, ' . TitleService::equippedTitleExpr('u.id') . ')
             WHERE u.id = :id AND t.active = 1',
            ['id' => $userId],
        );

        $profile = [
            'id' => $userId,
            'username' => (string) $profileUser['username'],
            'nickname' => $nickname,
            'initial' => mb_substr($nickname, 0, 1),
            'hue' => Present::avatarHue($userId),
            'bio' => (string) ($profileUser['bio'] ?? ''),
            'location' => (string) ($profileUser['location'] ?? ''),
            'website' => (string) ($profileUser['website'] ?? ''),
            'joined_at' => date('Y-m-d', strtotime((string) $profileUser['created_at']) ?: time()),
            'is_self' => $isSelf,
            'followers_count' => $this->follows->followersCount($userId),
            'following_count' => $this->follows->followingCount($userId),
            'is_following' => $viewerId !== null && !$isSelf && $this->follows->isFollowing($viewerId, $userId),
            'is_blocked' => $isBlocked,
            'can_message' => $canMessage,
            'avatar_url' => Present::uploadUrl(
                isset($profileUser['avatar_thumb']) ? (string) $profileUser['avatar_thumb'] : null,
                isset($profileUser['avatar_path']) ? (string) $profileUser['avatar_path'] : null,
            ),
            'cover_url' => Present::uploadUrl(
                isset($profileUser['cover_thumb']) ? (string) $profileUser['cover_thumb'] : null,
                isset($profileUser['cover_path']) ? (string) $profileUser['cover_path'] : null,
            ),
            'mid' => $userId,
            'posts_count' => (int) ($counts['posts_count'] ?? 0),
            'likes_received' => (int) ($counts['likes_received'] ?? 0),
            'level_number' => (int) ($stats['level'] ?? 0),
            'is_formal' => (bool) ($stats['is_formal'] ?? false),
            'is_flash' => (bool) ($stats['is_flash'] ?? false),
            'is_premium' => (bool) ($stats['is_premium'] ?? false),
            'title' => $titleRow === null ? null : [
                'name' => (string) $titleRow['name'],
                'icon' => (string) ($titleRow['icon'] ?? 'military_tech'),
            ],
            ...ProfileDecorations::present($profileUser),
        ];

        $data = [
            'profile' => $profile,
            'tab' => $tab,
            'tabUrl' => '/u/' . (string) $profileUser['username'] . '?',
            'connectionsHidden' => $connectionsHidden,
        ];

        switch ($tab) {
            case 'favorites':
                $data['canViewFavorites'] = $isSelf;

                if ($isSelf) {
                    $data['favoritePosts'] = $this->feed->presentRows(
                        $this->favorites->favoritePosts($userId),
                        $viewerId,
                        $canModerate,
                    );
                    $data['favoriteConfessions'] = $this->confessions->presentRows(
                        $this->favorites->favoriteConfessions($userId),
                        $viewerId,
                        $canModerate,
                    );
                } else {
                    $data['favoritePosts'] = [];
                    $data['favoriteConfessions'] = [];
                }
                break;

            case 'likes':
                $data['canViewPrivate'] = $isSelf;

                if ($isSelf) {
                    $data['likedPosts'] = $this->feed->presentRows(
                        $this->favorites->likedPosts($userId),
                        $viewerId,
                        $canModerate,
                    );
                    $data['likedConfessions'] = $this->confessions->presentRows(
                        $this->favorites->likedConfessions($userId),
                        $viewerId,
                        $canModerate,
                    );
                } else {
                    $data['likedPosts'] = [];
                    $data['likedConfessions'] = [];
                }
                break;

            case 'comments':
                $data['canViewPrivate'] = $isSelf;
                $data['myComments'] = $isSelf
                    ? array_map(
                        static fn (array $row): array => self::presentMyComment($row, 'post'),
                        $this->users->commentsByUser($userId),
                    )
                    : [];
                $data['myConfessionComments'] = $isSelf
                    ? array_map(
                        static fn (array $row): array => self::presentMyComment($row, 'confession'),
                        $this->users->confessionCommentsByUser($userId),
                    )
                    : [];
                break;

            case 'following':
                $data['connections'] = $connectionsHidden
                    ? ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'pages' => 1]
                    : $this->follows->following($userId, $viewerId, $page, 20);
                break;

            case 'followers':
                $data['connections'] = $connectionsHidden
                    ? ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20, 'pages' => 1]
                    : $this->follows->followers($userId, $viewerId, $page, 20);
                break;

            default:
                // 私密帖仅作者本人可见：管理员浏览他人主页时也按普通访客处理
                $result = $this->posts->byUser($userId, $isSelf, $page, $allPosts ? 20 : 2, $keyword);
                $data['feed'] = $result;
                $data['feedItems'] = $this->feed->presentRows($result['items'], $viewerId, $canModerate);
                $data['allPosts'] = $allPosts;
                $data['keyword'] = $keyword;
                break;
        }

        return $this->page->render($request, 'pages/profile.twig', $data);
    }

    /**
     * @提及用户搜索：按用户名/昵称模糊匹配，返回活跃用户。
     */
    public function search(Request $request, Response $response): Response
    {
        Guard::requireLogin(Ctx::user($request));

        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));

        if ($q === '' || mb_strlen($q) < 2) {
            return Json::ok($response, []);
        }

        return Json::ok($response, $this->users->searchForMention($q));
    }

    /**
     * 将“我的评论”行转换为展示结构。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentMyComment(array $row, string $kind): array
    {
        if ($kind === 'post') {
            $available = $row['deleted_at'] === null && (string) $row['post_status'] === 'published';
            $url = '/posts/' . (int) $row['post_id'] . '#comments';
            $label = '动态评论';
        } else {
            $available = $row['deleted_at'] === null && (string) $row['confession_status'] === 'approved';
            $board = (string) ($row['board'] ?? 'wall');
            $url = ($board === 'hole' ? '/hole/' : '/wall/') . (int) $row['confession_id'] . '#comments';
            $label = ($board === 'hole' ? '树洞' : '表白墙') . '回应';
        }

        return [
            'id' => (int) $row['id'],
            'label' => $label,
            'excerpt' => Text::excerpt((string) $row['content'], 100),
            'time_ago' => Text::relativeTime((string) $row['created_at']),
            'url' => $url,
            'available' => $available,
        ];
    }

    /**
     * 按邮箱哈希输出头像：Twikoo 2.x 默认按邮箱哈希请求 Gravatar，
     * 前端把头像源指向本站（含重写老评论里已存的地址），这里换成该用户的站内头像。
     *
     * Twikoo 用的是 sha256（64 位十六进制），历史数据里也可能有 md5（32 位），两种都支持。
     * 命中：302 到站内头像；未命中：直接返回首字头像 SVG
     * （与站内文字头像同款），避免再发出一次外部请求。
     *
     * @param array<string, string> $args
     */
    public function avatar(Request $request, Response $response, array $args): Response
    {
        $hash = strtolower((string) ($args['hash'] ?? ''));
        $query = $request->getQueryParams();
        $name = trim((string) ($query['name'] ?? ''));
        $condition = match (strlen($hash)) {
            64 => 'SHA2(LOWER(TRIM(u.email)), 256) = :hash',
            32 => 'MD5(LOWER(TRIM(u.email))) = :hash',
            default => '',
        };

        if ($condition !== '') {
            $row = $this->query->one(
                'SELECT am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
                 FROM users u
                 LEFT JOIN user_profiles p ON p.user_id = u.id
                 LEFT JOIN media am ON am.id = p.avatar_media_id
                 WHERE ' . $condition . '
                 LIMIT 1',
                ['hash' => $hash],
            );

            if ($row !== null) {
                $url = Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                );

                if ($url !== '') {
                    return $response
                        ->withHeader('Location', $url)
                        ->withHeader('Cache-Control', 'public, max-age=600')
                        ->withStatus(302);
                }
            }
        }

        $response->getBody()->write(self::letterAvatar($name));

        return $response
            ->withHeader('Content-Type', 'image/svg+xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /** 首字头像 SVG（与前端 Twikoo 回退头像一致：同一套色相算法与外观） */
    private static function letterAvatar(string $name): string
    {
        $display = $name === '' ? '?' : $name;
        $hue = 7;

        for ($i = 0, $length = strlen($display); $i < $length; $i++) {
            $hue = ($hue * 31 + ord($display[$i])) % 360;
        }

        $initial = mb_strtoupper(mb_substr($display, 0, 1));

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
            . '<rect width="96" height="96" rx="48" fill="hsl(%d 58%% 62%%)"/>'
            . '<text x="48" y="63" font-size="42" font-family="sans-serif" text-anchor="middle" fill="#ffffff">%s</text>'
            . '</svg>',
            $hue,
            htmlspecialchars($initial, ENT_QUOTES | ENT_XML1, 'UTF-8'),
        );
    }
}
