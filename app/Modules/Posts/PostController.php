<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Modules\Comments\CommentService;
use Mofu\Modules\History\HistoryService;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Growth\LevelGate;
use Mofu\Modules\Growth\TitleService;
use Mofu\Modules\Growth\ViewRewardService;
use Mofu\Modules\Membership\TaskService;
use Mofu\Modules\Tags\TagService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class PostController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostService $postService,
        private readonly CommentService $commentService,
        private readonly FeedService $feed,
        private readonly Page $page,
        private readonly HistoryService $history,
        private readonly LevelService $levels,
        private readonly Query $query,
        private readonly TagService $tags,
        private readonly TaskService $tasks,
        private readonly ViewRewardService $viewRewards,
    ) {
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $postId = (int) ($args['id'] ?? 0);
        $state = Ctx::state($request);
        $viewerId = $state->userId();
        $canModerate = Guard::can($state->user, 'post.moderate');

        $post = $this->posts->find($postId);

        if ($post === null || !$this->postService->canView($post, $viewerId, $canModerate)) {
            throw HttpException::notFound('动态不存在或已被删除');
        }

        if ($viewerId !== null && (string) $post['status'] === 'published') {
            $this->history->record($viewerId, $postId);
            // 「每日观看」经验：当日首次查看帖子详情即得（服务端按天幂等）
            $this->tasks->award($viewerId, 'watch');
            // 「每日观看 10 篇」M币结算（同 IP 当日仅首个账号）
            $this->viewRewards->settle($viewerId, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
        }

        // 作者等级与佩戴称号（详情页展示；列表侧不查，避免 N+1）
        $authorId = (int) $post['user_id'];
        $authorStats = $this->levels->stats($authorId);
        $authorTitle = $this->query->one(
            'SELECT t.name, t.icon FROM titles t JOIN users u
               ON t.id = COALESCE(u.equipped_title_id, ' . TitleService::equippedTitleExpr('u.id') . ')
             WHERE u.id = :id AND t.active = 1',
            ['id' => $authorId],
        );

        $post['author_level'] = (int) ($authorStats['level'] ?? 0);
        $post['author_is_formal'] = (bool) ($authorStats['is_formal'] ?? false);
        $post['author_is_flash'] = (bool) ($authorStats['is_flash'] ?? false);
        $post['author_is_premium'] = (bool) ($authorStats['is_premium'] ?? false);
        $post['author_title_name'] = $authorTitle === null ? null : (string) $authorTitle['name'];
        $post['author_title_icon'] = $authorTitle === null ? null : (string) ($authorTitle['icon'] ?? 'military_tech');

        $presented = $this->feed->presentOne($post, $viewerId, $canModerate);

        $comments = $this->commentService->listForPost(
            $postId,
            $viewerId,
            Guard::can($state->user, 'comment.moderate'),
            1,
            20,
        );

        return $this->page->render($request, 'pages/post.twig', [
            'post' => $presented,
            'comments' => $comments,
            // 评论门槛：登录但未达 Lv.2 时不给 Twikoo 挂载点，避免绕过门槛
            'commentBlocked' => $viewerId !== null
                && (int) ($this->levels->stats($viewerId)['level'] ?? 0) < LevelGate::COMMENT,
        ]);
    }

    /** @param array<string, string> $args */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $postId = (int) ($args['id'] ?? 0);

        $post = $this->posts->find($postId);

        if ($post === null || $post['deleted_at'] !== null) {
            throw HttpException::notFound('动态不存在或已被删除');
        }

        $isOwner = (int) $post['user_id'] === (int) $user['id'];
        $canModerate = Guard::can($user, 'post.moderate');

        if (!$isOwner && !$canModerate) {
            throw HttpException::forbidden();
        }

        $topics = array_map(
            static fn (array $t): string => (string) $t['name'],
            $this->posts->topicsForPost($postId),
        );

        $media = array_map(static function (array $m): array {
            $disk = ltrim((string) $m['disk_path'], '/');
            $thumb = (string) ($m['thumb_path'] ?? '');

            return [
                'id' => (int) $m['id'],
                'kind' => (string) ($m['kind'] ?? 'image'),
                'thumb' => $thumb !== '' ? '/uploads/' . ltrim($thumb, '/') : '/uploads/' . $disk,
            ];
        }, $this->posts->mediaForPost($postId));

        return $this->page->render($request, 'pages/compose.twig', [
            'tags' => $this->tags->catalogue(),
            'canTag' => (int) ($this->levels->stats((int) $user['id'])['level'] ?? 0) >= 3,
            'editPost' => [
                'id' => $postId,
                'content' => (string) $post['content'],
                'visibility' => (string) $post['visibility'],
                'topics' => $topics,
                'media' => $media,
                'is_paid' => (int) ($post['is_paid'] ?? 0) === 1,
                'price' => (float) ($post['price'] ?? 0),
                'tags' => array_map(
                    static fn (array $t): string => (string) $t['slug'],
                    $this->tags->forPost($postId),
                ),
            ],
        ]);
    }

    public function compose(Request $request, Response $response): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/compose.twig', [
            'tags' => $this->tags->catalogue(),
            'canTag' => (int) ($this->levels->stats((int) $user['id'])['level'] ?? 0) >= 3,
        ]);
    }

    /** @param array<string, string> $args */
    public function topic(Request $request, Response $response, array $args): Response
    {
        $slug = (string) ($args['slug'] ?? '');
        $state = Ctx::state($request);

        $topic = $this->posts->findTopicBySlug($slug);

        if ($topic === null) {
            throw HttpException::notFound('话题不存在');
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));
        $result = $this->posts->byTopic((int) $topic['id'], $page, 10);

        return $this->page->render($request, 'pages/topic.twig', [
            'topic' => $topic,
            'feed' => $result,
            'feedItems' => $this->feed->presentRows(
                $result['items'],
                $state->userId(),
                Guard::can($state->user, 'post.moderate'),
            ),
        ]);
    }
}
