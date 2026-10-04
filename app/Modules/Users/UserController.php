<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Http\Json;
use Mofu\Core\Security\Guard;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Page;
use Mofu\Core\View\Present;
use Mofu\Modules\Confessions\ConfessionService;
use Mofu\Modules\Follows\FollowService;
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

        return $this->page->render($request, 'pages/me.twig', [
            'profile' => [
                'id' => $userId,
                'username' => (string) $profileUser['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($userId),
                'bio' => (string) ($profileUser['bio'] ?? ''),
                'followers_count' => $this->follows->followersCount($userId),
                'following_count' => $this->follows->followingCount($userId),
                'avatar_url' => Present::uploadUrl(
                    isset($profileUser['avatar_thumb']) ? (string) $profileUser['avatar_thumb'] : null,
                    isset($profileUser['avatar_path']) ? (string) $profileUser['avatar_path'] : null,
                ),
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

        $nickname = trim((string) ($profileUser['nickname'] ?? '')) !== ''
            ? (string) $profileUser['nickname']
            : (string) $profileUser['username'];

        $canMessage = $viewerId !== null && !$isSelf
            ? $this->messages->canMessage($viewerId, $userId)['allowed']
            : false;
        $isBlocked = $viewerId !== null && $this->blocks->isBlocked($viewerId, $userId);
        $connectionsHidden = !$isSelf
            && $this->preferences->privacyPrefs($userId)['connections_visibility'] === 'self';

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
                $result = $this->posts->byUser($userId, $isSelf, $page, 10);
                $data['feed'] = $result;
                $data['feedItems'] = $this->feed->presentRows($result['items'], $viewerId, $canModerate);
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
}
