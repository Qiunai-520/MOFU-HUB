<?php

declare(strict_types=1);

namespace Mofu\Modules\Admin;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\Support\AuditLogger;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Page;
use Mofu\Modules\Appeals\AppealService;
use Mofu\Modules\Boards\BoardService;
use Mofu\Modules\Media\MediaService;
use Mofu\Modules\Settings\AboutTeam;
use Mofu\Modules\Settings\HomeBanners;
use Mofu\Modules\Communities\CommunityService;
use Mofu\Modules\Confessions\ConfessionPresenter;
use Mofu\Modules\Events\EventService;
use Mofu\Modules\News\NewsService;
use Mofu\Modules\Confessions\ConfessionRepository;
use Mofu\Modules\Confessions\ConfessionService;
use Mofu\Modules\Comments\CommentExporter;
use Mofu\Modules\Notifications\NotificationService;
use Mofu\Modules\Reports\ReportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 基础管理后台：仪表盘、用户、动态、评论、表白墙审核与举报处理。
 * 统计与列表均来自真实数据库；操作写入审计与审核记录。
 */
final class AdminController
{
    public function __construct(
        private readonly Query $query,
        private readonly AuditLogger $audit,
        private readonly Page $page,
        private readonly ConfessionRepository $confessionRepository,
        private readonly ConfessionService $confessionService,
        private readonly ReportService $reports,
        private readonly NotificationService $notifications,
        private readonly AppealService $appeals,
        private readonly CommunityService $communities,
        private readonly BoardService $boards,
        private readonly EventService $events,
        private readonly NewsService $newsService,
        private readonly MediaService $media,
        private readonly \Mofu\Modules\Settings\SettingsService $settings,
        private readonly CommentExporter $commentExporter,
    ) {
    }

    // ---------------------------------------------------------------- 仪表盘

    public function dashboard(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $since7d = date('Y-m-d H:i:s', time() - 7 * 86400);

        $stats = [
            'users' => (int) $this->query->value('SELECT COUNT(*) FROM users'),
            'users_7d' => (int) $this->query->value('SELECT COUNT(*) FROM users WHERE created_at >= :since', ['since' => $since7d]),
            'posts' => (int) $this->query->value('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL'),
            'posts_7d' => (int) $this->query->value('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL AND created_at >= :since', ['since' => $since7d]),
            'comments' => (int) $this->query->value('SELECT COUNT(*) FROM comments'),
            'pending_reports' => $this->reports->pendingCount(),
        ];

        $confessionCounts = $this->confessionRepository->statusCounts();

        $stats['confessions'] = array_sum($confessionCounts);
        $stats['confessions_pending'] = $confessionCounts['pending'];

        $recentPosts = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'excerpt' => Text::excerpt((string) $row['content'], 60),
            'status' => (string) $row['status'],
            'username' => (string) $row['username'],
            'time_ago' => Text::relativeTime((string) $row['created_at']),
        ], $this->query->all(
            'SELECT p.id, p.content, p.status, p.created_at, u.username
             FROM posts p JOIN users u ON u.id = p.user_id
             WHERE p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 8',
        ));

        $recentUsers = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'time_ago' => Text::relativeTime((string) $row['created_at']),
        ], $this->query->all('SELECT id, username, created_at FROM users ORDER BY id DESC LIMIT 8'));

        return $this->page->render($request, 'pages/admin/dashboard.twig', [
            'stats' => $stats,
            'recentPosts' => $recentPosts,
            'recentUsers' => $recentUsers,
        ]);
    }

    // ---------------------------------------------------------------- 用户管理

    public function users(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'user.manage');

        $params = $request->getQueryParams();
        $q = trim((string) ($params['q'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));

        $where = '';
        $bind = [];

        if ($q !== '') {
            $where = 'WHERE u.username LIKE :q1 OR u.email LIKE :q2 OR pr.nickname LIKE :q3';
            $bind['q1'] = $bind['q2'] = $bind['q3'] = '%' . $q . '%';
        }

        $sql = 'SELECT u.id, u.username, u.email, u.status, u.created_at, pr.nickname,
                       (SELECT MAX(r.level) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_level,
                       (SELECT GROUP_CONCAT(r.slug ORDER BY r.level DESC) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_slugs
                FROM users u
                LEFT JOIN user_profiles pr ON pr.user_id = u.id
                ' . $where . '
                ORDER BY u.id DESC';
        $countSql = 'SELECT COUNT(*) FROM users u LEFT JOIN user_profiles pr ON pr.user_id = u.id ' . $where;

        $result = $this->query->paginate($sql, $countSql, $bind, $page, 20);

        return $this->page->render($request, 'pages/admin/users.twig', [
            'users' => $result,
            'q' => $q,
            'ok' => isset($params['ok']),
            'roles' => $this->query->all('SELECT slug, name, level FROM roles ORDER BY level ASC'),
        ]);
    }

    /** @param array<string, string> $args */
    public function setUserStatus(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

        $targetId = (int) ($args['id'] ?? 0);
        $status = (string) (((array) $request->getParsedBody())['status'] ?? '');

        if (!in_array($status, ['active', 'suspended', 'banned'], true)) {
            throw HttpException::badRequest('状态无效');
        }

        $this->assertCanManageUser($actor, $targetId);

        $this->query->update('users', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $targetId]);

        $this->audit->log((int) $actor['id'], 'admin.user.status', 'user', $targetId, ['status' => $status]);

        return $this->redirect($response, '/admin/users?ok=1');
    }

    /** @param array<string, string> $args */
    public function setUserRole(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

        $targetId = (int) ($args['id'] ?? 0);
        $roleSlug = (string) (((array) $request->getParsedBody())['role'] ?? '');

        $this->assertCanManageUser($actor, $targetId);

        $role = $this->query->one('SELECT id, level FROM roles WHERE slug = :slug', ['slug' => $roleSlug]);

        if ($role === null) {
            throw HttpException::badRequest('角色无效');
        }

        $actorLevel = $this->roleLevel((int) $actor['id']);

        if ((int) $role['level'] >= $actorLevel) {
            throw HttpException::forbidden('不能授予不低于自身级别的角色');
        }

        $now = date('Y-m-d H:i:s');

        $this->query->transaction(function (Query $q) use ($targetId, $role, $now): void {
            $q->delete('user_roles', 'user_id = :user_id', ['user_id' => $targetId]);
            $q->insert('user_roles', [
                'user_id' => $targetId,
                'role_id' => (int) $role['id'],
                'assigned_at' => $now,
            ]);
        });

        $this->audit->log((int) $actor['id'], 'admin.user.role', 'user', $targetId, ['role' => $roleSlug]);

        return $this->redirect($response, '/admin/users?ok=1');
    }

    // ---------------------------------------------------------------- 动态管理

    public function posts(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'post.moderate');

        $params = $request->getQueryParams();
        $status = in_array($params['status'] ?? '', ['published', 'hidden', 'removed'], true) ? (string) $params['status'] : '';
        $page = max(1, (int) ($params['page'] ?? 1));

        $where = 'WHERE p.deleted_at IS NULL';
        $bind = [];

        if ($status !== '') {
            $where .= ' AND p.status = :status';
            $bind['status'] = $status;
        }

        $sql = 'SELECT p.id, p.content, p.status, p.visibility, p.like_count, p.comment_count, p.created_at,
                       u.username, pr.nickname
                FROM posts p JOIN users u ON u.id = p.user_id
                LEFT JOIN user_profiles pr ON pr.user_id = u.id
                ' . $where . '
                ORDER BY p.id DESC';
        $countSql = 'SELECT COUNT(*) FROM posts p ' . $where;

        $result = $this->query->paginate($sql, $countSql, $bind, $page, 20);

        $result['items'] = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'excerpt' => Text::excerpt((string) $row['content'], 80),
            'status' => (string) $row['status'],
            'visibility' => (string) $row['visibility'],
            'like_count' => (int) $row['like_count'],
            'comment_count' => (int) $row['comment_count'],
            'username' => (string) $row['username'],
            'nickname' => trim((string) ($row['nickname'] ?? '')) !== '' ? (string) $row['nickname'] : (string) $row['username'],
            'time_ago' => Text::relativeTime((string) $row['created_at']),
        ], $result['items']);

        return $this->page->render($request, 'pages/admin/posts.twig', [
            'posts' => $result,
            'status' => $status,
            'ok' => isset($params['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function setPostStatus(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'post.moderate');

        $postId = (int) ($args['id'] ?? 0);
        $status = (string) (((array) $request->getParsedBody())['status'] ?? '');

        if (!in_array($status, ['published', 'hidden', 'removed'], true)) {
            throw HttpException::badRequest('状态无效');
        }

        $post = $this->query->one('SELECT id FROM posts WHERE id = :id AND deleted_at IS NULL', ['id' => $postId]);

        if ($post === null) {
            throw HttpException::notFound('动态不存在');
        }

        $this->query->update('posts', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $postId]);

        $this->recordModeration((int) $actor['id'], 'post.' . $status, 'post', $postId);

        return $this->redirect($response, '/admin/posts?ok=1');
    }

    /** @param array<string, string> $args */
    public function deletePost(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'post.moderate');

        $postId = (int) ($args['id'] ?? 0);

        $this->query->update('posts', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id AND deleted_at IS NULL', ['id' => $postId]);

        $this->recordModeration((int) $actor['id'], 'post.delete', 'post', $postId);

        return $this->redirect($response, '/admin/posts?ok=1');
    }

    // ---------------------------------------------------------------- 评论管理

    public function comments(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'comment.moderate');

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));

        $sql = 'SELECT c.id, c.content, c.status, c.created_at, c.post_id, u.username, pr.nickname
                FROM comments c JOIN users u ON u.id = c.user_id
                LEFT JOIN user_profiles pr ON pr.user_id = u.id
                ORDER BY c.id DESC';
        $countSql = 'SELECT COUNT(*) FROM comments c';

        $result = $this->query->paginate($sql, $countSql, [], $page, 20);

        $result['items'] = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'content' => Text::excerpt((string) $row['content'], 100),
            'status' => (string) $row['status'],
            'post_id' => (int) $row['post_id'],
            'username' => (string) $row['username'],
            'nickname' => trim((string) ($row['nickname'] ?? '')) !== '' ? (string) $row['nickname'] : (string) $row['username'],
            'time_ago' => Text::relativeTime((string) $row['created_at']),
        ], $result['items']);

        return $this->page->render($request, 'pages/admin/comments.twig', [
            'comments' => $result,
            'ok' => isset($params['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function removeComment(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'comment.moderate');

        $commentId = (int) ($args['id'] ?? 0);
        $comment = $this->query->one('SELECT id, post_id, status FROM comments WHERE id = :id', ['id' => $commentId]);

        if ($comment === null) {
            throw HttpException::notFound('评论不存在');
        }

        if ($comment['status'] !== 'removed') {
            $this->query->transaction(function (Query $q) use ($commentId, $comment): void {
                $q->update('comments', ['status' => 'removed'], 'id = :id', ['id' => $commentId]);
                $q->run('UPDATE posts SET comment_count = GREATEST(0, comment_count - 1) WHERE id = :id', [
                    'id' => (int) $comment['post_id'],
                ]);
            });
        }

        $this->recordModeration((int) $actor['id'], 'comment.remove', 'comment', $commentId);

        return $this->redirect($response, '/admin/comments?ok=1');
    }

    // ---------------------------------------------------------------- 表白墙审核

    public function confessions(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'post.moderate');

        $params = $request->getQueryParams();
        $status = in_array($params['status'] ?? '', ['pending', 'approved', 'rejected', 'hidden'], true) ? (string) $params['status'] : '';
        $page = max(1, (int) ($params['page'] ?? 1));

        $result = $this->confessionRepository->moderationPage($status, $page, 20);

        $result['items'] = array_map(function (array $row): array {
            $presented = ConfessionPresenter::present($row, [], null, true, false, false);

            $presented['creator_username'] = (string) ($row['author_username'] ?? '');
            $presented['report_count'] = (int) $row['report_count'];

            return $presented;
        }, $result['items']);

        return $this->page->render($request, 'pages/admin/confessions.twig', [
            'items' => $result,
            'status' => $status,
            'ok' => isset($params['ok']),
            'moderationPre' => $this->confessionService->moderationEnabled(),
        ]);
    }

    /** @param array<string, string> $args */
    public function moderateConfession(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'post.moderate');

        $id = (int) ($args['id'] ?? 0);
        $action = (string) (((array) $request->getParsedBody())['action'] ?? '');

        $row = $this->confessionRepository->find($id);

        if ($row === null || $row['deleted_at'] !== null) {
            throw HttpException::notFound('投稿不存在');
        }

        $now = date('Y-m-d H:i:s');
        $creatorId = $row['creator_id'] !== null ? (int) $row['creator_id'] : null;
        $board = (string) $row['board'];

        switch ($action) {
            case 'approve':
                $this->confessionRepository->update($id, ['status' => 'approved', 'updated_at' => $now]);
                $this->notifyConfessionOwner($creatorId, $id, $board, '你的投稿已通过审核');
                break;

            case 'reject':
                $this->confessionRepository->update($id, ['status' => 'rejected', 'updated_at' => $now]);
                $this->notifyConfessionOwner($creatorId, $id, $board, '你的投稿未通过审核');
                break;

            case 'feature':
                $this->confessionRepository->update($id, ['is_featured' => 1, 'updated_at' => $now]);
                break;

            case 'unfeature':
                $this->confessionRepository->update($id, ['is_featured' => 0, 'updated_at' => $now]);
                break;

            case 'hide':
                $this->confessionRepository->update($id, ['status' => 'hidden', 'updated_at' => $now]);
                $this->notifyConfessionOwner($creatorId, $id, $board, '你的投稿已被下架');
                break;

            case 'restore':
                $this->confessionRepository->update($id, ['status' => 'approved', 'updated_at' => $now]);
                break;

            case 'delete':
                $this->confessionRepository->softDelete($id);
                break;

            default:
                throw HttpException::badRequest('无效的操作');
        }

        $this->recordModeration((int) $actor['id'], 'confession.' . $action, 'confession', $id);

        return $this->redirect($response, '/admin/confessions?ok=1');
    }

    // ---------------------------------------------------------------- 举报处理

    public function reports(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'report.handle');

        $params = $request->getQueryParams();
        $status = in_array($params['status'] ?? '', ['pending', 'accepted', 'rejected'], true) ? (string) $params['status'] : '';
        $page = max(1, (int) ($params['page'] ?? 1));

        return $this->page->render($request, 'pages/admin/reports.twig', [
            'reports' => $this->reports->queue($status, $page, 20),
            'status' => $status,
            'ok' => isset($params['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function resolveReport(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'report.handle');

        $reportId = (int) ($args['id'] ?? 0);
        $action = (string) (((array) $request->getParsedBody())['action'] ?? '');

        if (!in_array($action, ['accept', 'reject'], true)) {
            throw HttpException::badRequest('无效的操作');
        }

        $report = $this->reports->find($reportId);

        if ($report === null) {
            throw HttpException::notFound('举报记录不存在');
        }

        $result = $this->reports->resolve($reportId, (int) $actor['id'], $action === 'accept');

        $reporterId = (int) $report['reporter_id'];

        // 通知举报人
        $this->notifications->push(
            $reporterId,
            null,
            'report_result',
            'report',
            $reportId,
            ['message' => $result['accepted'] ? '你举报的内容经核实已被处理' : '你举报的内容经核查未违规'],
        );

        // 通知被处理内容的作者
        if ($result['accepted'] && $result['target_owner'] !== null) {
            $this->notifications->push(
                $result['target_owner'],
                null,
                'report_result',
                $result['target_type'],
                $result['target_id'],
                ['message' => '你发布的内容因举报核实已被下架'],
            );
        }

        $this->recordModeration((int) $actor['id'], 'report.' . $action, $result['target_type'], $result['target_id']);

        return $this->redirect($response, '/admin/reports?ok=1');
    }

    // ---------------------------------------------------------------- 申诉处理

    public function appeals(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'user.manage');

        $params = $request->getQueryParams();
        $status = ($params['status'] ?? '') === 'pending' ? 'pending' : '';
        $page = max(1, (int) ($params['page'] ?? 1));

        return $this->page->render($request, 'pages/admin/appeals.twig', [
            'appeals' => $this->appeals->listForAdmin($status, $page, 20),
            'status' => $status,
            'ok' => isset($params['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function resolveAppeal(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

        $appealId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');
        $reply = (string) ($body['reply'] ?? '');

        if (!in_array($action, ['accept', 'reject'], true)) {
            throw HttpException::badRequest('无效的操作');
        }

        $this->appeals->resolve($appealId, (int) $actor['id'], $action === 'accept', $reply);
        $this->recordModeration((int) $actor['id'], 'appeal.' . $action, 'appeal', $appealId);

        return $this->redirect($response, '/admin/appeals?ok=1');
    }

    // ---------------------------------------------------------------- 圈子管理

    public function circles(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $params = $request->getQueryParams();
        $filter = ($params['filter'] ?? '') === 'inactive' ? 'inactive' : '';
        $page = max(1, (int) ($params['page'] ?? 1));

        return $this->page->render($request, 'pages/admin/circles.twig', [
            'circles' => $this->communities->adminPage($filter, $page, 20),
            'filter' => $filter,
            'ok' => isset($params['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function setCircleActive(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $communityId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $active = ($body['action'] ?? '') === 'activate';

        $this->communities->setActive($communityId, $active);
        $this->recordModeration((int) $actor['id'], 'circle.' . ($active ? 'activate' : 'deactivate'), 'community', $communityId);

        return $this->redirect($response, '/admin/circles?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteCircle(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $communityId = (int) ($args['id'] ?? 0);
        $this->communities->delete($communityId);
        $this->recordModeration((int) $actor['id'], 'circle.delete', 'community', $communityId);

        return $this->redirect($response, '/admin/circles?ok=1');
    }

    // ---------------------------------------------------------------- 团队名单

    /** 关于页「团队名单」编辑（存于 system_settings.site.about_team） */
    public function team(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $params = $request->getQueryParams();

        return $this->page->render($request, 'pages/admin/team.twig', [
            'members' => AboutTeam::decode($this->settings->get(AboutTeam::KEY)),
            'ok' => isset($params['ok']),
            'error' => isset($params['error']),
        ]);
    }

    public function saveTeam(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $body = (array) $request->getParsedBody();
        $members = AboutTeam::fromRows(
            (array) ($body['member_name'] ?? []),
            (array) ($body['member_role'] ?? []),
            (array) ($body['member_bio'] ?? []),
            (array) ($body['member_avatar'] ?? []),
            (array) ($body['member_email'] ?? []),
            (array) ($body['member_since'] ?? []),
        );

        if ($members === []) {
            return $this->redirect($response, '/admin/team?error=1');
        }

        $this->settings->set(AboutTeam::KEY, AboutTeam::encode($members), (int) $actor['id']);
        $this->audit->log((int) $actor['id'], 'team.save', 'settings', 0, ['count' => count($members)]);

        return $this->redirect($response, '/admin/team?ok=1');
    }

    /**
     * 导出站内评论为 Twikoo 可导入的 JSON（后台「评论管理」页的按钮）。
     */
    public function exportCommentsTwikoo(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $items = $this->commentExporter->twikoo();
        $body = (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="twikoo-comments.json"');
    }

    public function banners(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $params = $request->getQueryParams();

        return $this->page->render($request, 'pages/admin/banners.twig', [
            'items' => HomeBanners::decode($this->settings->get(HomeBanners::KEY)),
            'ok' => isset($params['ok']),
        ]);
    }

    public function saveBanners(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $body = (array) $request->getParsedBody();
        $items = HomeBanners::fromRows(
            (array) ($body['banner_title'] ?? []),
            (array) ($body['banner_summary'] ?? []),
            (array) ($body['banner_link'] ?? []),
            (array) ($body['banner_image'] ?? []),
        );

        $this->settings->set(HomeBanners::KEY, HomeBanners::encode($items), (int) $actor['id']);
        $this->audit->log((int) $actor['id'], 'banners.save', 'settings', 0, ['count' => count($items)]);

        return $this->redirect($response, '/admin/banners?ok=1');
    }

    // ---------------------------------------------------------------- 板块帖子管理

    public function boardEntries(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $params = $request->getQueryParams();
        $status = in_array($params['status'] ?? '', ['published', 'hidden', 'removed'], true) ? (string) $params['status'] : '';
        $boardSlug = trim((string) ($params['board'] ?? ''));
        $page = max(1, (int) ($params['page'] ?? 1));

        return $this->page->render($request, 'pages/admin/boards.twig', [
            'entries' => $this->boards->adminPage($status, $boardSlug, $page, 20),
            'status' => $status,
            'boardSlug' => $boardSlug,
            'boards' => $this->boards->all(),
            'ok' => isset($params['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function setEntryStatus(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $entryId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');

        $status = match ($action) {
            'hide' => 'hidden',
            'restore' => 'published',
            'remove' => 'removed',
            default => '',
        };

        if ($status === '') {
            throw HttpException::badRequest('无效的操作');
        }

        $this->boards->setStatus($entryId, $status);
        $this->recordModeration((int) $actor['id'], 'entry.' . $action, 'board_entry', $entryId);

        return $this->redirect($response, '/admin/boards?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteEntry(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $entryId = (int) ($args['id'] ?? 0);
        $this->boards->adminDelete($entryId);
        $this->recordModeration((int) $actor['id'], 'entry.delete', 'board_entry', $entryId);

        return $this->redirect($response, '/admin/boards?ok=1');
    }

    // ---------------------------------------------------------------- 板块外观（Logo）

    public function boardSettings(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $params = $request->getQueryParams();

        return $this->page->render($request, 'pages/admin/board-settings.twig', [
            'boards' => $this->boards->adminBoards(),
            'ok' => isset($params['ok']),
            'error' => isset($params['error']) ? 'Logo 设置失败，请重试（需为 JPG/PNG/WebP/GIF）' : null,
        ]);
    }

    /** @param array<string, string> $args */
    public function saveBoardLogo(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $boardId = (int) ($args['id'] ?? 0);
        $exists = $this->query->value('SELECT id FROM boards WHERE id = :id', ['id' => $boardId]);

        if ($exists === null) {
            throw HttpException::notFound('板块不存在');
        }

        $body = (array) $request->getParsedBody();

        if (($body['action'] ?? '') === 'clear') {
            $this->boards->setLogo($boardId, null);
            $this->recordModeration((int) $actor['id'], 'board.logo.clear', 'board', $boardId);

            return $this->redirect($response, '/admin/board-settings?ok=1');
        }

        $file = $request->getUploadedFiles()['logo'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->redirect($response, '/admin/board-settings?error=1');
        }

        try {
            $media = $this->media->storeImage((int) $actor['id'], $file);
        } catch (HttpException) {
            return $this->redirect($response, '/admin/board-settings?error=1');
        }

        $this->boards->setLogo($boardId, (int) $media['id']);
        $this->recordModeration((int) $actor['id'], 'board.logo.set', 'board', $boardId);

        return $this->redirect($response, '/admin/board-settings?ok=1');
    }

    // ---------------------------------------------------------------- 活动管理

    public function events(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $params = $request->getQueryParams();
        $status = in_array($params['status'] ?? '', ['published', 'cancelled', 'hidden', 'removed'], true)
            ? (string) $params['status']
            : '';
        $page = max(1, (int) ($params['page'] ?? 1));

        return $this->page->render($request, 'pages/admin/events.twig', [
            'events' => $this->events->adminPage($status, $page, 20),
            'status' => $status,
            'ok' => isset($params['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function setEventStatus(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $eventId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');

        $status = match ($action) {
            'hide' => 'hidden',
            'restore' => 'published',
            'remove' => 'removed',
            default => '',
        };

        if ($status === '') {
            throw HttpException::badRequest('无效的操作');
        }

        $this->events->setStatus($eventId, $status);
        $this->recordModeration((int) $actor['id'], 'event.' . $action, 'event', $eventId);

        return $this->redirect($response, '/admin/events?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteEvent(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $eventId = (int) ($args['id'] ?? 0);
        $this->events->adminDelete($eventId);
        $this->recordModeration((int) $actor['id'], 'event.delete', 'event', $eventId);

        return $this->redirect($response, '/admin/events?ok=1');
    }

    // ---------------------------------------------------------------- 校园资讯

    public function news(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'news.manage');

        $params = $request->getQueryParams();
        $status = isset(NewsService::STATUSES[(string) ($params['status'] ?? '')]) ? (string) $params['status'] : '';
        $category = isset(NewsService::CATEGORIES[(string) ($params['category'] ?? '')]) ? (string) $params['category'] : '';
        $page = max(1, (int) ($params['page'] ?? 1));

        return $this->page->render($request, 'pages/admin/news.twig', [
            'news' => $this->newsService->adminPage($status, $category, $page, 20),
            'status' => $status,
            'category' => $category,
            'ok' => isset($params['ok']),
        ]);
    }

    public function newsNew(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'news.manage');

        return $this->page->render($request, 'pages/admin/news-form.twig', [
            'editing' => false,
            'article' => null,
            'errors' => [],
            'values' => ['title' => '', 'summary' => '', 'content' => '', 'category' => 'campus', 'is_pinned' => ''],
        ]);
    }

    public function newsCreate(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'news.manage');

        $body = (array) $request->getParsedBody();
        $cover = $this->uploadedNewsCover($request);

        try {
            $newsId = $this->newsService->create((int) $actor['id'], $body, $cover);
        } catch (HttpException $e) {
            return $this->page->render($request, 'pages/admin/news-form.twig', [
                'editing' => false,
                'article' => null,
                'errors' => ['form' => $e->getMessage()],
                'values' => $this->newsValuesFromInput($body),
            ], $e->getStatusCode());
        }

        $this->recordModeration((int) $actor['id'], 'news.create', 'news', $newsId);

        return $this->redirect($response, '/admin/news?ok=1');
    }

    /** @param array<string, string> $args */
    public function newsEdit(Request $request, Response $response, array $args): Response
    {
        Guard::require(Ctx::user($request), 'news.manage');

        $article = $this->newsService->findOrFail((int) ($args['id'] ?? 0));

        return $this->page->render($request, 'pages/admin/news-form.twig', [
            'editing' => true,
            'article' => $article,
            'errors' => [],
            'values' => $this->newsValuesFromRow($article),
        ]);
    }

    /** @param array<string, string> $args */
    public function newsUpdate(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'news.manage');

        $newsId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();

        try {
            $this->newsService->update($newsId, $body, $this->uploadedNewsCover($request));
        } catch (HttpException $e) {
            return $this->page->render($request, 'pages/admin/news-form.twig', [
                'editing' => true,
                'article' => $this->newsService->findOrFail($newsId),
                'errors' => ['form' => $e->getMessage()],
                'values' => $this->newsValuesFromInput($body),
            ], $e->getStatusCode());
        }

        $this->recordModeration((int) $actor['id'], 'news.update', 'news', $newsId);

        return $this->redirect($response, '/admin/news?ok=1');
    }

    /** @param array<string, string> $args */
    public function newsStatus(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'news.manage');

        $newsId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $action = (string) ($body['action'] ?? '');

        $status = match ($action) {
            'publish', 'restore' => 'published',
            'draft' => 'draft',
            'hide' => 'hidden',
            'remove' => 'removed',
            default => '',
        };

        if ($status === '') {
            throw HttpException::badRequest('无效的操作');
        }

        $this->newsService->setStatus($newsId, $status);
        $this->recordModeration((int) $actor['id'], 'news.' . $action, 'news', $newsId);

        return $this->redirect($response, '/admin/news?ok=1');
    }

    /** @param array<string, string> $args */
    public function newsPin(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'news.manage');

        $newsId = (int) ($args['id'] ?? 0);
        $this->newsService->togglePin($newsId);
        $this->recordModeration((int) $actor['id'], 'news.pin', 'news', $newsId);

        return $this->redirect($response, '/admin/news?ok=1');
    }

    /** @param array<string, string> $args */
    public function newsDelete(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'news.manage');

        $newsId = (int) ($args['id'] ?? 0);
        $this->newsService->adminDelete($newsId);
        $this->recordModeration((int) $actor['id'], 'news.delete', 'news', $newsId);

        return $this->redirect($response, '/admin/news?ok=1');
    }

    // ---------------------------------------------------------------- 内部

    private function notifyConfessionOwner(?int $creatorId, int $confessionId, string $board, string $message): void
    {
        if ($creatorId === null) {
            return;
        }

        $this->notifications->push($creatorId, null, 'confession_status', 'confession', $confessionId, [
            'message' => $message,
            'url' => ConfessionService::boardUrl($board) . '/' . $confessionId,
        ]);
    }

    /** @param array<string, mixed> $actor */
    private function assertCanManageUser(array $actor, int $targetId): void
    {
        $actorId = (int) $actor['id'];

        if ($actorId === $targetId) {
            throw HttpException::forbidden('不能对自己执行该操作');
        }

        $actorLevel = $this->roleLevel($actorId);
        $targetLevel = $this->roleLevel($targetId);

        if ($targetLevel >= $actorLevel) {
            throw HttpException::forbidden('无法操作不低于自身级别的用户');
        }
    }

    private function roleLevel(int $userId): int
    {
        $level = $this->query->value(
            'SELECT MAX(r.level) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id',
            ['id' => $userId],
        );

        return (int) ($level ?? 0);
    }

    private function uploadedNewsCover(Request $request): ?UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $cover = $files['cover'] ?? null;

        return $cover instanceof UploadedFileInterface ? $cover : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private function newsValuesFromInput(array $input): array
    {
        return [
            'title' => trim((string) ($input['title'] ?? '')),
            'summary' => trim((string) ($input['summary'] ?? '')),
            'content' => trim((string) ($input['content'] ?? '')),
            'category' => (string) ($input['category'] ?? 'campus'),
            'is_pinned' => (string) ($input['is_pinned'] ?? '') === '1' ? '1' : '',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function newsValuesFromRow(array $row): array
    {
        return [
            'title' => (string) $row['title'],
            'summary' => (string) $row['summary'],
            'content' => (string) $row['content'],
            'category' => (string) $row['category'],
            'is_pinned' => (int) $row['is_pinned'] === 1 ? '1' : '',
        ];
    }

    private function recordModeration(int $actorId, string $action, string $targetType, int $targetId): void
    {
        $this->query->insert('moderation_records', [
            'moderator_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'note' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log($actorId, 'admin.' . $action, $targetType, $targetId);
    }

    private function redirect(Response $response, string $to): Response
    {
        return $response->withHeader('Location', $to)->withStatus(302);
    }
}
