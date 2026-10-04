<?php

declare(strict_types=1);

namespace Mofu\Modules\Posts;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Modules\Comments\CommentService;
use Mofu\Modules\History\HistoryService;
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
        }

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
                'thumb' => $thumb !== '' ? '/uploads/' . ltrim($thumb, '/') : '/uploads/' . $disk,
            ];
        }, $this->posts->mediaForPost($postId));

        return $this->page->render($request, 'pages/compose.twig', [
            'editPost' => [
                'id' => $postId,
                'content' => (string) $post['content'],
                'visibility' => (string) $post['visibility'],
                'topics' => $topics,
                'media' => $media,
            ],
        ]);
    }

    public function compose(Request $request, Response $response): Response
    {
        Guard::requireLogin(Ctx::user($request));

        return $this->page->render($request, 'pages/compose.twig');
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
