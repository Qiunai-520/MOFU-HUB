<?php

declare(strict_types=1);

namespace Mofu\Modules\Messages;

use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\View\Page;
use Mofu\Core\View\Present;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Users\UserRepository;
use Mofu\Modules\Users\ProfileDecorations;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class MessageController
{
    public function __construct(
        private readonly MessageService $messages,
        private readonly NotificationService $notifications,
        private readonly UserRepository $users,
        private readonly Page $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->inbox($request, $response, 'auto');
    }

    /**
     * 消息中心：私信与通知在**同一页面内**切换（客户端切换，不整页刷新）。
     * /messages 与 /notifications 都渲染这一个页面，仅默认标签不同。
     *
     * `$defaultTab` 传 'auto'（/messages）时按未读私信决定：有未读进私信，否则进通知。
     * 浏览器端还会用 localStorage 记住上次所在标签并覆盖这里的选择。
     */
    public function inbox(Request $request, Response $response, string $defaultTab = 'auto'): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $user['id'];
        $query = $request->getQueryParams();

        $tab = $this->resolveTab($query['tab'] ?? null, $defaultTab, $userId);
        $page = max(1, (int) ($query['page'] ?? 1));
        $filter = ($query['filter'] ?? '') === 'unread' ? 'unread' : 'all';

        return $this->page->render($request, 'pages/messages/index.twig', [
            'tab' => $tab,
            'conversations' => $this->messages->listConversations($userId, $page),
            'notifications' => $this->notifications->listForUser($userId, $filter, $page, 30),
            'filter' => $filter,
            'unreadCount' => $this->notifications->unreadCount($userId),
        ]);
    }

    /** 显式 ?tab= 优先；其次路由给的固定标签；'auto' 则按未读私信决定 */
    private function resolveTab(?string $requested, string $defaultTab, int $userId): string
    {
        if ($requested === 'notifications' || $requested === 'messages') {
            return $requested;
        }

        if ($defaultTab === 'notifications' || $defaultTab === 'messages') {
            return $defaultTab;
        }

        return $this->messages->unreadCount($userId) > 0 ? 'messages' : 'notifications';
    }

    /** @param array<string, string> $args */
    public function openWith(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $username = (string) ($args['username'] ?? '');

        $target = $this->users->findByUsername($username);

        if ($target === null || ($target['status'] ?? 'active') === 'banned') {
            throw HttpException::notFound('用户不存在');
        }

        $viewerId = (int) $user['id'];
        $targetId = (int) $target['id'];

        if ($viewerId === $targetId) {
            return $response->withHeader('Location', '/messages')->withStatus(302);
        }

        $conversation = $this->messages->findOrCreate($viewerId, $targetId);
        $conversationId = (int) ($conversation['id'] ?? 0);

        if ($conversationId <= 0) {
            throw HttpException::notFound('无法打开会话');
        }

        return $response->withHeader('Location', '/messages/' . $conversationId)->withStatus(302);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = Guard::requireLogin(Ctx::user($request));
        $viewerId = (int) $user['id'];
        $conversationId = (int) ($args['id'] ?? 0);

        $conversation = $this->messages->find($conversationId, $viewerId);

        if ($conversation === null) {
            throw HttpException::notFound('会话不存在');
        }

        $otherId = $this->messages->otherUserId($conversation, $viewerId);
        $other = $this->users->findById($otherId);

        if ($other === null) {
            throw HttpException::notFound('用户不存在');
        }

        $nickname = trim((string) ($other['nickname'] ?? '')) !== ''
            ? (string) $other['nickname']
            : (string) $other['username'];

        $otherPresented = [
            'id' => $otherId,
            'username' => (string) $other['username'],
            'nickname' => $nickname,
            'initial' => mb_substr($nickname, 0, 1),
            'hue' => Present::avatarHue($otherId),
            'url' => '/u/' . (string) $other['username'],
            'avatar_url' => Present::uploadUrl(
                isset($other['avatar_thumb']) ? (string) $other['avatar_thumb'] : null,
                isset($other['avatar_path']) ? (string) $other['avatar_path'] : null,
            ),
            ...ProfileDecorations::present($other),
        ];

        $check = $this->messages->canMessage($viewerId, $otherId);

        // 打开会话即标记已读
        $this->messages->markRead($viewerId, $conversationId);

        return $this->page->render($request, 'pages/messages/show.twig', [
            'conversationId' => $conversationId,
            'other' => $otherPresented,
            'messages' => $this->messages->listMessages($conversationId, $viewerId, null, null, 30),
            'canSend' => $check['allowed'],
            'lockedReason' => $check['reason'],
        ]);
    }
}
