<?php

declare(strict_types=1);

namespace Mofu\Modules\Admin;

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Migrator;
use Mofu\Core\Database\Query;
use Mofu\Core\Support\Backup\DbDumper;
use Mofu\Core\Http\Ctx;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Security\Guard;
use Mofu\Core\Support\AuditLogger;
use Mofu\Core\Support\Logger;
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
use Mofu\Modules\Moderation\BanService;
use Mofu\Modules\Announcements\AnnouncementService;
use Mofu\Modules\Growth\AchievementService;
use Mofu\Modules\Growth\CheckInService;
use Mofu\Modules\Growth\GrowthService;
use Mofu\Modules\Growth\LevelService;
use Mofu\Modules\Growth\ShopService;
use Mofu\Modules\Growth\TitleService;
use Mofu\Modules\Membership\QuizService;
use Mofu\Modules\Tags\TagService;
use Mofu\Modules\Jury\JuryService;
use Mofu\Modules\Lab\LabService;
use Mofu\Modules\Growth\WalletService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use PDO;

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
        private readonly BanService $bans,
        private readonly AnnouncementService $announcements,
        private readonly TitleService $titles,
        private readonly AchievementService $achievements,
        private readonly ShopService $shop,
        private readonly CheckInService $checkin,
        private readonly QuizService $quiz,
        private readonly WalletService $wallet,
        private readonly LevelService $levels,
        private readonly GrowthService $growth,
        private readonly Config $config,
        private readonly TagService $tags,
        private readonly JuryService $jury,
        private readonly LabService $lab,
        private readonly PDO $pdo,
    ) {
    }

    // ---------------------------------------------------------------- 后台首页

    /** 后台入口：直接进入运营概览，避免多一层空首页。 */
    public function index(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        return $this->redirect($response, '/admin/dashboard');
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

        // 近 7 天趋势（注册 / 动态 / 评论）：3 条按日聚合查询，避免逐日逐指标各查一次（原为 21 条）
        $since = date('Y-m-d', strtotime('-6 day')) . ' 00:00:00';
        $usersByDay = $this->countsByDay('users', 'created_at', $since);
        $postsByDay = $this->countsByDay('posts', 'created_at', $since, 'deleted_at IS NULL');
        $commentsByDay = $this->countsByDay('comments', 'created_at', $since);

        $trend = [];
        $trendMax = ['users' => 1, 'posts' => 1, 'comments' => 1];

        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' day'));
            $row = [
                'date' => date('m-d', strtotime($day)),
                'users' => $usersByDay[$day] ?? 0,
                'posts' => $postsByDay[$day] ?? 0,
                'comments' => $commentsByDay[$day] ?? 0,
            ];

            foreach ($trendMax as $metric => $current) {
                $trendMax[$metric] = max($current, $row[$metric]);
            }

            $trend[] = $row;
        }

        $trendPoints = [];

        foreach ($trendMax as $metric => $max) {
            $points = [];

            foreach ($trend as $index => $row) {
                $points[] = round(24 + $index * 42, 1) . ',' . round(116 - ($row[$metric] / $max) * 96, 1);
            }

            $trendPoints[$metric] = implode(' ', $points);
        }

        // 内容构成（环形图）：动态 / 评论 / 表白墙 / 板块帖子
        $composition = [
            ['label' => '动态', 'value' => (int) $this->query->value('SELECT COUNT(*) FROM posts WHERE deleted_at IS NULL'), 'color' => 'var(--color-accent-text)'],
            ['label' => '评论', 'value' => (int) $this->query->value('SELECT COUNT(*) FROM comments'), 'color' => 'var(--color-info)'],
            ['label' => '表白墙 / 树洞', 'value' => (int) $this->query->value('SELECT COUNT(*) FROM confessions WHERE deleted_at IS NULL'), 'color' => 'var(--color-label-secondary)'],
            ['label' => '板块帖子', 'value' => (int) $this->query->value('SELECT COUNT(*) FROM board_entries'), 'color' => 'var(--color-destructive)'],
        ];
        $compositionTotal = max(1, array_sum(array_column($composition, 'value')));

        $pieSegments = [];
        $offset = 0.0;

        foreach ($composition as $item) {
            $percent = $item['value'] / $compositionTotal * 100;
            $pieSegments[] = [
                'label' => $item['label'],
                'value' => $item['value'],
                'color' => $item['color'],
                'percent' => round($percent, 1),
                'dash' => round($percent, 2) . ' ' . round(100 - $percent, 2),
                'offset' => round(-$offset, 2),
            ];
            $offset += $percent;
        }

        return $this->page->render($request, 'pages/admin/dashboard.twig', [
            'stats' => $stats,
            'recentPosts' => $recentPosts,
            'recentUsers' => $recentUsers,
            'trend' => $trend,
            'trendPoints' => $trendPoints,
            'pieSegments' => $pieSegments,
        ]);
    }

    /**
     * 按天聚合计数（管理端图表用）。
     *
     * @return array<string, int> Y-m-d => 计数
     */
    private function countsByDay(string $table, string $column, string $since, string $extraWhere = ''): array
    {
        $where = $extraWhere === '' ? '' : ' AND ' . $extraWhere;

        $rows = $this->query->all(
            sprintf(
                'SELECT DATE(%s) AS d, COUNT(*) AS c FROM %s WHERE %s >= :since%s GROUP BY DATE(%s)',
                $column,
                $this->query->identifier($table),
                $column,
                $where,
                $column,
            ),
            ['since' => $since],
        );

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row['d']] = (int) $row['c'];
        }

        return $out;
    }

    // ---------------------------------------------------------------- 用户管理

    public function users(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

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
                       (SELECT GROUP_CONCAT(r.slug ORDER BY r.level DESC) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_slugs,
                       COALESCE((
                           SELECT r2.slug
                           FROM user_roles ur2
                           JOIN roles r2 ON r2.id = ur2.role_id
                           WHERE ur2.user_id = u.id
                           ORDER BY r2.level DESC, r2.id ASC
                           LIMIT 1
                       ), \'user\') AS primary_role_slug
                FROM users u
                LEFT JOIN user_profiles pr ON pr.user_id = u.id
                ' . $where . '
                ORDER BY u.id DESC';
        $countSql = 'SELECT COUNT(*) FROM users u LEFT JOIN user_profiles pr ON pr.user_id = u.id ' . $where;

        $result = $this->query->paginate($sql, $countSql, $bind, $page, 20);
        $actorId = (int) $actor['id'];
        $actorLevel = $this->roleLevel($actorId);
        $result['items'] = array_map(function (array $row) use ($actorId, $actorLevel): array {
            $row['is_self'] = (int) $row['id'] === $actorId;
            $row['can_manage'] = !$row['is_self'] && (int) ($row['role_level'] ?? 0) < $actorLevel;

            return $row;
        }, $result['items']);
        $allRoles = $this->query->all('SELECT slug, name, level FROM roles ORDER BY level ASC');

        return $this->page->render($request, 'pages/admin/users.twig', [
            'users' => $result,
            'q' => $q,
            'ok' => isset($params['ok']),
            'roles' => array_values(array_filter(
                $allRoles,
                static fn (array $role): bool => (string) $role['slug'] !== 'super_admin',
            )),
            'allRoles' => $allRoles,
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

        // 统一走封禁服务：留下可追溯的处罚记录（封禁提示页 / 申诉需要），而不是直接改 users.status
        $reason = trim((string) (((array) $request->getParsedBody())['reason'] ?? '')) ?: '管理员调整';

        switch ($status) {
            case 'active':
                $this->bans->unban($targetId, (int) $actor['id'], '管理员解除');
                break;
            case 'suspended':
                $this->bans->suspend($targetId, (int) $actor['id'], $reason, 168);
                break;
            case 'banned':
                $this->bans->ban($targetId, (int) $actor['id'], $reason);
                break;
        }

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

        // 超级管理员唯一：后台不提供授予，也不能改动现任超管的角色
        if ($roleSlug === 'super_admin') {
            throw HttpException::forbidden('超级管理员唯一，不能通过后台授予');
        }

        if ($this->isSuperAdmin($targetId)) {
            throw HttpException::forbidden('不能修改超级管理员的角色');
        }

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
            (array) ($body['banner_target'] ?? []),
            (array) ($body['banner_type'] ?? []),
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
            'logoBoards' => $this->boards->adminBoards(),
            'conditions' => BoardService::CONDITIONS,
            'lostTypes' => BoardService::LOST_TYPES,
            'ok' => isset($params['ok']),
        ]);
    }

    /** 后台新建板块帖子 */
    public function createBoardEntry(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $input = (array) $request->getParsedBody();
        $board = $this->boards->findBySlug(trim((string) ($input['board_slug'] ?? '')));

        if ($board === null) {
            throw HttpException::badRequest('请选择板块');
        }

        // 板块专属字段未填时给最小合法默认值，避免后台发帖因缺少「成色 / 类型」直接失败
        $kind = (string) ($board['kind'] ?? '');

        if ($kind === 'market') {
            if (trim((string) ($input['condition'] ?? '')) === '') {
                $input['condition'] = (string) array_key_first(BoardService::CONDITIONS);
            }

            if (trim((string) ($input['price'] ?? '')) === '') {
                $input['price'] = '1';
            }
        }

        if ($kind === 'lost' && trim((string) ($input['lost_type'] ?? '')) === '') {
            $input['lost_type'] = (string) array_key_first(BoardService::LOST_TYPES);
        }

        $entryId = $this->boards->create((int) $actor['id'], $board, $input);
        $this->recordModeration((int) $actor['id'], 'board.entry_create', 'board_entry', $entryId);

        return $this->redirect($response, '/admin/boards?ok=1');
    }

    /** 后台编辑帖子：标题必填，正文留空表示不变 */
    public function saveBoardEntry(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $entryId = (int) ($args['id'] ?? 0);
        $this->boards->updateEntry($entryId, (array) $request->getParsedBody());
        $this->recordModeration((int) $actor['id'], 'board.entry_update', 'board_entry', $entryId);

        return $this->redirect($response, '/admin/boards?ok=1');
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
    private function assertCanManageUser(array $actor, int $targetId, bool $allowSelf = false): void
    {
        $actorId = (int) $actor['id'];

        if ($actorId === $targetId) {
            // 自我操作（仅称号授予这类场景放行）：不参与「同级不可操作」的比较
            if (!$allowSelf) {
                throw HttpException::forbidden('不能对自己执行该操作');
            }

            return;
        }

        $actorLevel = $this->roleLevel($actorId);
        $targetLevel = $this->roleLevel($targetId);

        if ($targetLevel >= $actorLevel) {
            throw HttpException::forbidden('无法操作不低于自身级别的用户');
        }
    }

    private function isSuperAdmin(int $userId): bool
    {
        return $this->query->value(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :id AND r.slug = 'super_admin'",
            ['id' => $userId],
        ) !== null;
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

    // ---------------------------------------------------------------- 公告 / 成长体系

    public function announcements(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'announcement.manage');

        return $this->page->render($request, 'pages/admin/announcements.twig', [
            'items' => $this->announcements->adminList(),
            'types' => AnnouncementService::TYPE_LABELS,
            'statuses' => AnnouncementService::STATUS_LABELS,
            'ok' => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveAnnouncement(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'announcement.manage');

        $id = (int) ($args['id'] ?? 0);
        $this->announcements->save($id > 0 ? $id : null, (array) $request->getParsedBody(), (int) $actor['id']);

        return $this->redirect($response, '/admin/announcements?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteAnnouncement(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'announcement.manage');

        $this->announcements->delete((int) $args['id'], (int) $actor['id']);

        return $this->redirect($response, '/admin/announcements?ok=1');
    }

    public function titles(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'title.manage');

        return $this->page->render($request, 'pages/admin/titles.twig', [
            'titles' => $this->titles->adminList(),
            'rarities' => TitleService::RARITY_LABELS,
            'conditions' => TitleService::CONDITION_LABELS,
            'achievements' => $this->achievements->adminList(),
            'ok' => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveTitle(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'title.manage');

        $id = (int) ($args['id'] ?? 0);
        $this->titles->save($id > 0 ? $id : null, (array) $request->getParsedBody(), (int) $actor['id']);
        $this->recordModeration((int) $actor['id'], 'title.save', 'title', $id);

        return $this->redirect($response, '/admin/titles?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteTitle(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'title.manage');

        $this->titles->delete((int) $args['id']);
        $this->audit->log((int) $actor['id'], 'title.delete', 'title', (int) $args['id']);

        return $this->redirect($response, '/admin/titles?ok=1');
    }

    /** @param array<string, string> $args */
    public function grantTitle(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'title.manage');

        $input = (array) $request->getParsedBody();
        $titleId = (int) ($input['title_id'] ?? 0);
        $userId = (int) ($input['user_id'] ?? 0);

        if ($userId <= 0 || $titleId <= 0) {
            throw HttpException::badRequest('请填写用户 ID 与称号');
        }

        // 称号只是装饰，允许给自己授予/收回（其余管理操作仍禁止对自己下手）
        $this->assertCanManageUser($actor, $userId, true);

        if (($input['action'] ?? 'grant') === 'revoke') {
            $this->titles->revoke($userId, $titleId);
            $this->audit->log((int) $actor['id'], 'title.revoke', 'user', $userId, ['title_id' => $titleId]);
        } else {
            $this->titles->grant($userId, $titleId, 'admin');
            $this->audit->log((int) $actor['id'], 'title.grant', 'user', $userId, ['title_id' => $titleId]);
        }

        return $this->redirect($response, '/admin/titles?ok=1');
    }

    // ---------------------------------------------------------------- 标签

    public function tags(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'tag.manage');

        $params = $request->getQueryParams();

        return $this->page->render($request, 'pages/admin/tags.twig', [
            'tags' => $this->tags->adminList(),
            'ok' => isset($params['ok']),
            'error' => isset($params['error']) ? (string) $params['error'] : '',
        ]);
    }

    /** @param array<string, string> $args */
    public function saveTag(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'tag.manage');

        $id = (int) ($args['id'] ?? 0);

        try {
            $tagId = $this->tags->save($id > 0 ? $id : null, (array) $request->getParsedBody());
        } catch (HttpException $e) {
            return $this->redirect($response, '/admin/tags?error=' . rawurlencode($e->getMessage()));
        }

        $this->audit->log((int) $actor['id'], 'tag.save', 'tag', $tagId);

        return $this->redirect($response, '/admin/tags?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteTag(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'tag.manage');

        $id = (int) ($args['id'] ?? 0);
        $this->tags->delete($id);
        $this->audit->log((int) $actor['id'], 'tag.delete', 'tag', $id);

        return $this->redirect($response, '/admin/tags?ok=1');
    }

    // ---------------------------------------------------------------- 风纪委员会

    public function jury(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'jury.manage');

        $params = $request->getQueryParams();

        return $this->page->render($request, 'pages/admin/jury.twig', [
            'applications' => $this->jury->applications('pending'),
            'members' => $this->jury->members(),
            'cases' => $this->jury->cases(),
            'ratings' => JuryService::RATING_LABELS,
            'stipendMax' => JuryService::STIPEND_MAX,
            'ok' => isset($params['ok']),
            'notice' => isset($params['notice']) ? (string) $params['notice'] : '',
        ]);
    }

    /** @param array<string, string> $args */
    public function decideJuryApplication(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'jury.manage');

        $body = (array) $request->getParsedBody();
        $this->jury->decide(
            (int) ($args['id'] ?? 0),
            (string) ($body['action'] ?? '') === 'approve',
            (string) ($body['note'] ?? ''),
            (int) $actor['id'],
        );

        return $this->redirect($response, '/admin/jury?ok=1');
    }

    /** @param array<string, string> $args */
    public function rateJuryMember(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'jury.manage');

        $body = (array) $request->getParsedBody();
        $this->jury->setRating((int) ($args['id'] ?? 0), (string) ($body['rating'] ?? 'none'));

        return $this->redirect($response, '/admin/jury?ok=1');
    }

    /** @param array<string, string> $args */
    public function removeJuryMember(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'jury.manage');

        $this->jury->removeMember((int) ($args['id'] ?? 0), (int) $actor['id']);

        return $this->redirect($response, '/admin/jury?ok=1');
    }

    public function openJuryCase(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'jury.manage');

        $body = (array) $request->getParsedBody();
        $this->jury->openCase(
            (string) ($body['target_type'] ?? 'post'),
            (int) ($body['target_id'] ?? 0),
            (int) $actor['id'],
            (string) ($body['reason'] ?? ''),
            (string) ($body['snapshot'] ?? ''),
        );

        return $this->redirect($response, '/admin/jury?ok=1');
    }

    /** @param array<string, string> $args */
    public function closeJuryCase(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'jury.manage');

        $body = (array) $request->getParsedBody();
        $this->jury->closeCase((int) ($args['id'] ?? 0), (string) ($body['verdict'] ?? 'clean'), (int) $actor['id']);

        return $this->redirect($response, '/admin/jury?ok=1');
    }

    public function payJuryStipend(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'jury.manage');

        $result = $this->jury->payMonthlyStipend();

        return $this->redirect($response, '/admin/jury?ok=1&notice=' . rawurlencode(sprintf('已发放 %s 月津贴：%d 人 / %s M币', $result['month'], $result['paid'], $result['total'])));
    }

    public function settleJuryYear(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'jury.manage');

        $body = (array) $request->getParsedBody();
        $year = (int) ($body['year'] ?? date('Y'));
        $result = $this->jury->settleYear($year);

        return $this->redirect($response, '/admin/jury?ok=1&notice=' . rawurlencode(sprintf('%d 年结算：%d 人 / %s M币', $result['year'], $result['paid'], $result['total'])));
    }

    // ---------------------------------------------------------------- 社区实验室

    public function lab(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'lab.manage');

        $params = $request->getQueryParams();

        return $this->page->render($request, 'pages/admin/lab.twig', [
            'features' => $this->lab->adminList(),
            'rollouts' => LabService::ROLLOUT_LABELS,
            'ok' => isset($params['ok']),
            'error' => isset($params['error']) ? (string) $params['error'] : '',
        ]);
    }

    /** @param array<string, string> $args */
    public function saveLab(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'lab.manage');

        $id = (int) ($args['id'] ?? 0);

        try {
            $featureId = $this->lab->save($id > 0 ? $id : null, (array) $request->getParsedBody());
        } catch (HttpException $e) {
            return $this->redirect($response, '/admin/lab?error=' . rawurlencode($e->getMessage()));
        }

        $this->audit->log((int) $actor['id'], 'lab.save', 'lab', $featureId);

        return $this->redirect($response, '/admin/lab?ok=1');
    }

    /** @param array<string, string> $args */
    public function toggleLab(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'lab.manage');

        $body = (array) $request->getParsedBody();
        $id = (int) ($args['id'] ?? 0);
        $this->lab->toggle($id, (string) ($body['action'] ?? '') === 'enable');
        $this->audit->log((int) $actor['id'], 'lab.toggle', 'lab', $id);

        return $this->redirect($response, '/admin/lab?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteLab(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'lab.manage');

        $id = (int) ($args['id'] ?? 0);
        $this->lab->delete($id);
        $this->audit->log((int) $actor['id'], 'lab.delete', 'lab', $id);

        return $this->redirect($response, '/admin/lab?ok=1');
    }

    public function saveAchievement(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'title.manage');

        $this->achievements->save(null, (array) $request->getParsedBody(), (int) $actor['id']);

        return $this->redirect($response, '/admin/titles?ok=1');
    }

    // ---------------------------------------------------------------- 小店 / 签到

    public function shop(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'shop.manage');

        return $this->page->render($request, 'pages/admin/shop.twig', [
            'products' => $this->shop->products(false),
            'sales' => $this->shop->salesSummary(),
            'orders' => $this->shop->orders(1, 20)['items'],
            'types' => ShopService::TYPE_LABELS,
            'ok' => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function saveProduct(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'shop.manage');

        $id = (int) ($args['id'] ?? 0);
        $this->shop->saveProduct($id > 0 ? $id : null, (array) $request->getParsedBody(), (int) $actor['id']);

        return $this->redirect($response, '/admin/shop?ok=1');
    }

    /** @param array<string, string> $args */
    public function productAction(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'shop.manage');

        $input = (array) $request->getParsedBody();
        $id = (int) $args['id'];
        $action = (string) ($input['action'] ?? 'toggle');

        if ($action === 'grant') {
            $this->shop->grantProduct((int) ($input['user_id'] ?? 0), $id, (int) $actor['id']);
        } else {
            $this->shop->toggle($id, $action === 'enable', (int) $actor['id']);
        }

        return $this->redirect($response, '/admin/shop?ok=1');
    }

    public function checkin(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'checkin.manage');

        return $this->page->render($request, 'pages/admin/checkin.twig', [
            'rewards' => $this->checkin->rewards(),
            'ok' => isset($request->getQueryParams()['ok']),
        ]);
    }

    public function saveCheckinRewards(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'checkin.manage');

        $raw = (string) (((array) $request->getParsedBody())['rewards'] ?? '');
        $rows = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $parts = array_map('trim', explode(',', $line));

            if (count($parts) < 3 || $parts[0] === '') {
                continue;
            }

            $rows[] = [
                'day' => (int) $parts[0],
                'reward_mofu' => (int) $parts[1],
                'reward_xp' => (int) $parts[2],
                'label' => $parts[3] ?? '',
                // 第 5 列可选：达成该连续天数时额外授予的称号 ID
                'title_id' => isset($parts[4]) && $parts[4] !== '' ? (int) $parts[4] : null,
            ];
        }

        $this->checkin->saveRewards($rows);
        $this->audit->log((int) $actor['id'], 'checkin.rewards', 'checkin', null, ['rows' => count($rows)]);

        return $this->redirect($response, '/admin/checkin?ok=1');
    }

    // ---------------------------------------------------------------- 会员 / 答题

    public function membership(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'user.manage');

        return $this->page->render($request, 'pages/admin/membership.twig', [
            'levels' => $this->levels->levels(),
            'questions' => $this->quiz->adminList(QuizService::TIER_FORMAL),
            'flashQuestions' => $this->quiz->adminList(QuizService::TIER_FLASH),
            'categories' => QuizService::CATEGORIES,
            'pass_score' => QuizService::PASS_SCORE,
            'question_count' => QuizService::QUESTION_COUNT,
            'flash_pass_score' => QuizService::FLASH_PASS_SCORE,
            'flash_question_count' => QuizService::FLASH_QUESTION_COUNT,
            'ok' => isset($request->getQueryParams()['ok']),
        ]);
    }

    public function saveLevels(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

        $raw = (string) (((array) $request->getParsedBody())['levels'] ?? '');
        $rows = [];

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $parts = array_map('trim', explode(',', $line));

            if (count($parts) < 2 || $parts[0] === '') {
                continue;
            }

            $rows[] = [
                'level' => (int) $parts[0],
                'xp_required' => (int) $parts[1],
                'name' => $parts[2] ?? '',
                'reward_mofu' => (int) ($parts[3] ?? 0),
            ];
        }

        $this->levels->configure($rows);
        $this->audit->log((int) $actor['id'], 'membership.levels', 'level_config', null, ['rows' => count($rows)]);

        return $this->redirect($response, '/admin/membership?ok=1');
    }

    public function saveQuestion(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

        $input = (array) $request->getParsedBody();
        $options = array_values(array_filter(array_map('trim', (array) ($input['options'] ?? [])), static fn (string $o): bool => $o !== ''));
        $tier = (int) ($input['tier'] ?? QuizService::TIER_FORMAL);

        $this->quiz->saveQuestion(null, [
            'question' => (string) ($input['question'] ?? ''),
            'category' => (string) ($input['category'] ?? ''),
            'options' => $options,
            'answer' => (int) ($input['answer'] ?? 0),
            'active' => !empty($input['active']),
        ], $tier);

        $this->audit->log((int) $actor['id'], 'membership.question_add', 'quiz', null, ['tier' => $tier]);

        return $this->redirect($response, '/admin/membership?ok=1');
    }

    /** @param array<string, string> $args */
    public function deleteQuestion(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

        $this->quiz->deleteQuestion((int) $args['id']);
        $this->audit->log((int) $actor['id'], 'membership.question_delete', 'quiz', (int) $args['id']);

        return $this->redirect($response, '/admin/membership?ok=1');
    }

    /** 管理员自由发放经验 / M币 / 大会员 */
    public function grantGrowth(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.manage');

        $input = (array) $request->getParsedBody();
        $userId = (int) ($input['user_id'] ?? 0);
        $action = (string) ($input['action'] ?? '');

        if ($userId <= 0) {
            throw HttpException::badRequest('请填写用户 ID');
        }

        // 不限制对象（含自己与同级别管理员）：持有 user.manage 即可自由发放
        switch ($action) {
            case 'xp':
                $amount = (int) ($input['amount'] ?? 0);
                $this->levels->addXp($userId, max(1, min(100000, $amount)), 'admin', '管理员发放');
                break;
            case 'coins':
                // 与 xp 一致限制单次额度，避免随意铸币
                $this->wallet->adjust($userId, max(-100000, min(100000, (int) ($input['amount'] ?? 0))), '管理员发放 M币', (int) $actor['id']);
                break;
            case 'premium':
                $this->levels->setMembership($userId, 'premium');
                break;
            case 'normal':
                $this->levels->setMembership($userId, 'normal');
                break;
            case 'formal':
                $this->levels->markFormal($userId);
                break;
            default:
                throw HttpException::badRequest('无效的操作');
        }

        $this->audit->log((int) $actor['id'], 'membership.grant.' . $action, 'user', $userId, ['amount' => (int) ($input['amount'] ?? 0)]);

        return $this->redirect($response, '/admin/growth?user_id=' . $userId . '&ok=1');
    }

    // ---------------------------------------------------------------- 用户成长 / 经济

    public function growth(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'user.manage');

        $userId = (int) ($request->getQueryParams()['user_id'] ?? 0);
        $user = $userId > 0 ? $this->query->one(
            'SELECT u.id, u.username, u.status, COALESCE(NULLIF(p.nickname, \'\'), u.username) AS nickname
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = :id',
            ['id' => $userId],
        ) : null;

        return $this->page->render($request, 'pages/admin/growth.twig', [
            'subject' => $user,
            'profile' => $user !== null ? $this->growth->profile($userId) : null,
            'bans' => $user !== null ? $this->bans->history($userId) : [],
            'titles' => $this->titles->adminList(),
            'ok' => isset($request->getQueryParams()['ok']),
        ]);
    }

    /** @param array<string, string> $args */
    public function userModerate(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        $userId = (int) $args['id'];
        $input = (array) $request->getParsedBody();
        $action = (string) ($input['action'] ?? '');
        $reason = trim((string) ($input['reason'] ?? ''));
        $note = trim((string) ($input['note'] ?? ''));
        $returnTo = trim((string) ($input['return'] ?? ''));
        $isPublic = !isset($input['is_public']) || (string) $input['is_public'] !== '0';
        $publicNote = trim((string) ($input['public_note'] ?? ''));

        $this->assertCanManageUser($actor, $userId);

        switch ($action) {
            case 'warn':
                Guard::require($actor, 'user.warn');
                $this->bans->warn($userId, (int) $actor['id'], $reason !== '' ? $reason : '违反社区规范', $note, $isPublic, $publicNote);
                break;

            case 'suspend':
                Guard::require($actor, 'user.ban.temp');
                $this->bans->suspend($userId, (int) $actor['id'], $reason !== '' ? $reason : '违反社区规范', (int) ($input['hours'] ?? 168), $note, $isPublic, $publicNote);
                break;

            case 'ban':
                Guard::require($actor, 'user.ban.permanent');
                $this->bans->ban($userId, (int) $actor['id'], $reason !== '' ? $reason : '严重违反社区规范', $note, $isPublic, $publicNote);
                break;

            case 'unban':
                Guard::require($actor, 'ban.manage');
                $this->bans->unban($userId, (int) $actor['id'], $note);
                break;

            default:
                throw HttpException::badRequest('无效的操作');
        }

        // 独立封禁页会带上来源路径：执行完回到原页面
        return $this->redirect($response, $returnTo !== '' ? $this->safeAdminReturn($returnTo) : '/admin/growth?user_id=' . $userId . '&ok=1');
    }

    /**
     * 独立封禁页：从用户管理页进入，执行后回到来源页面。
     *
     * @param array<string, string> $args
     */
    public function userBanForm(Request $request, Response $response, array $args): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'user.warn');

        $userId = (int) ($args['id'] ?? 0);
        $subject = $this->query->one(
            'SELECT u.id, u.username, u.status, COALESCE(NULLIF(p.nickname, \'\'), u.username) AS nickname
             FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = :id',
            ['id' => $userId],
        );

        if ($subject === null) {
            throw HttpException::notFound('用户不存在');
        }

        return $this->page->render($request, 'pages/admin/user-ban.twig', [
            'subject' => $subject,
            'bans' => $this->bans->history($userId),
            'returnTo' => $this->safeAdminReturn((string) ($request->getQueryParams()['return'] ?? '')),
            // 按实际权限渲染可选项，避免“能打开页面、提交才 403”的困惑
            'can' => [
                'warn' => Guard::can($actor, 'user.warn'),
                'temp' => Guard::can($actor, 'user.ban.temp'),
                'permanent' => Guard::can($actor, 'user.ban.permanent'),
                'revoke' => Guard::can($actor, 'ban.manage'),
            ],
        ]);
    }

    /** 仅接受站内 /admin 路径，避免开放重定向 */
    private function safeAdminReturn(string $to): string
    {
        $to = trim($to);

        if ($to === '' || !str_starts_with($to, '/admin') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            return '/admin/users';
        }

        return $to;
    }

    public function economy(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'economy.manage');

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $keyword = trim((string) ($params['q'] ?? ''));

        $where = '1 = 1';
        $bind = [];

        if ($keyword !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword) . '%';
            $where = '(u.username LIKE :kw_user OR w.description LIKE :kw_desc OR w.source LIKE :kw_source)';
            $bind = ['kw_user' => $like, 'kw_desc' => $like, 'kw_source' => $like];
        }

        $logs = $this->query->paginate(
            'SELECT w.*, u.username FROM wallet_transactions w JOIN users u ON u.id = w.user_id WHERE ' . $where . ' ORDER BY w.id DESC',
            'SELECT COUNT(*) FROM wallet_transactions w JOIN users u ON u.id = w.user_id WHERE ' . $where,
            $bind,
            $page,
            40,
        );

        return $this->page->render($request, 'pages/admin/economy.twig', [
            'overview' => $this->wallet->overview(),
            'logs' => $logs,
            'keyword' => $keyword,
        ]);
    }

    // ---------------------------------------------------------------- 系统与升级

    /** 系统与升级：显示迁移状态（虚拟主机没有终端，这里提供网页入口） */
    public function system(Request $request, Response $response): Response
    {
        $this->requireSystemAccess($request);

        $migrator = $this->migrator();
        $applied = $migrator->applied();
        $available = array_keys($migrator->available());
        $pending = array_values(array_diff($available, $applied));

        return $this->page->render($request, 'pages/admin/system.twig', [
            'title' => '系统与升级',
            'appliedCount' => count($applied),
            'totalCount' => count($available),
            'pending' => $pending,
            'latestApplied' => $applied === [] ? '' : (string) end($applied),
            'ok' => isset($request->getQueryParams()['ok']),
            'ran' => (string) ($request->getQueryParams()['ran'] ?? ''),
            'failed' => (string) ($request->getQueryParams()['failed'] ?? ''),
        ]);
    }

    /** 执行数据库升级（应用所有待执行的迁移） */
    public function runMigrations(Request $request, Response $response): Response
    {
        $actor = $this->requireSystemAccess($request);

        try {
            $migrator = $this->migrator();
            $pending = array_values(array_diff(array_keys($migrator->available()), $migrator->applied()));

            if ($pending !== []) {
                $dir = (string) $this->config->get('root') . '/storage/backups';

                if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
                    throw new \RuntimeException('无法创建 storage/backups 目录，已取消升级');
                }

                $name = 'mofu-pre-upgrade-' . date('Ymd-His') . '.sql';
                $backup = (new DbDumper($this->pdo))->dumpTo($dir . '/' . $name);
                $this->prunePreUpgradeBackups($dir);
                $this->audit->log((int) $actor['id'], 'system.pre_upgrade_backup', 'system', null, [
                    'file' => $name,
                    'tables' => $backup['tables'],
                    'rows' => $backup['rows'],
                ]);
            }

            $executed = $migrator->migrate();
        } catch (Throwable $e) {
            error_log('[mofu] web migrate failed: ' . $e->getMessage());

            return $response->withHeader('Location', '/admin/system?failed=' . rawurlencode($e->getMessage()))->withStatus(302);
        }

        $this->audit->log((int) $actor['id'], 'system.migrate', 'system', null, ['executed' => $executed]);

        return $response->withHeader('Location', '/admin/system?ok=1&ran=' . rawurlencode(implode(',', $executed)))->withStatus(302);
    }

    /** 网页版一键备份：导出数据库为 .sql 并直接下载（虚拟主机无终端时的替代） */
    public function exportBackup(Request $request, Response $response): Response
    {
        $actor = $this->requireSystemAccess($request);

        ignore_user_abort(true);
        set_time_limit(0);

        $root = (string) $this->config->get('root');
        $dir = $root . '/storage/backups';

        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw HttpException::badRequest('无法创建 storage/backups 目录，请确认 storage/ 可写');
        }

        $name = 'mofu-backup-' . date('Ymd-His') . '.sql';
        $file = $dir . '/' . $name;

        $result = (new DbDumper($this->pdo))->dumpTo($file);

        $this->audit->log((int) $actor['id'], 'system.backup', 'system', null, [
            'file' => $name,
            'tables' => $result['tables'],
            'rows' => $result['rows'],
        ]);

        $size = (int) (is_file($file) ? filesize($file) : 0);

        // 直接流式输出（Slim 响应对象在这里不适用），与安装向导的渲染方式一致
        if (!headers_sent()) {
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex, nofollow');
        }

        try {
            readfile($file);
        } finally {
            @unlink($file);
        }
        exit;
    }

    /** 保留最近 10 份升级前自动备份，避免长期积累整库 SQL。 */
    private function prunePreUpgradeBackups(string $dir, int $keep = 10): void
    {
        $files = glob(rtrim($dir, '/') . '/mofu-pre-upgrade-*.sql') ?: [];

        usort(
            $files,
            static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a),
        );

        foreach (array_slice($files, max(1, $keep)) as $old) {
            @unlink($old);
        }
    }

    /**
     * system.manage 是新增权限。首次部署本版本时迁移 045 可能尚未执行，
     * 因此允许既有 super_admin 继续进入系统页完成这次升级；迁移后仍按权限点生效。
     *
     * @return array<string, mixed>
     */
    private function requireSystemAccess(Request $request): array
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        $roles = array_map('strval', (array) ($actor['roles'] ?? []));

        if (!Guard::can($actor, 'system.manage') && !in_array('super_admin', $roles, true)) {
            throw HttpException::forbidden();
        }

        return $actor;
    }

    // ---------------------------------------------------------------- 更新日志

    /** 更新日志管理：编辑 Markdown 或上传 .md 文件 */
    public function changelog(Request $request, Response $response): Response
    {
        Guard::require(Ctx::user($request), 'admin.access');

        $key = \Mofu\Modules\Home\HomeController::CHANGELOG_KEY;

        return $this->page->render($request, 'pages/admin/changelog.twig', [
            'content' => (string) ($this->settings->get($key, '') ?? ''),
            'updatedAt' => (string) ($this->settings->get($key . '.updated_at', '') ?? ''),
            'ok' => isset($request->getQueryParams()['ok']),
            'error' => (string) ($request->getQueryParams()['error'] ?? ''),
        ]);
    }

    public function saveChangelog(Request $request, Response $response): Response
    {
        $actor = Guard::requireLogin(Ctx::user($request));
        Guard::require($actor, 'admin.access');

        $key = \Mofu\Modules\Home\HomeController::CHANGELOG_KEY;
        $content = (string) (((array) $request->getParsedBody())['content'] ?? '');

        // 上传 .md 时以文件内容为准
        $file = $request->getUploadedFiles()['file'] ?? null;

        if ($file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK) {
            if ((int) ($file->getSize() ?? 0) > 512 * 1024) {
                return $response->withHeader('Location', '/admin/changelog?error=' . rawurlencode('文件过大（最大 512KB）'))->withStatus(302);
            }

            $content = (string) $file->getStream();
        }

        $content = trim($content);

        if ($content === '') {
            return $response->withHeader('Location', '/admin/changelog?error=' . rawurlencode('内容不能为空（可留空使用内置默认日志）'))->withStatus(302);
        }

        $this->settings->set($key, $content, (int) $actor['id']);
        $this->settings->set($key . '.updated_at', date('Y-m-d H:i'), (int) $actor['id']);
        $this->audit->log((int) $actor['id'], 'about.changelog', 'system', null, ['length' => mb_strlen($content)]);

        return $response->withHeader('Location', '/admin/changelog?ok=1')->withStatus(302);
    }

    private function migrator(): Migrator
    {
        $root = (string) $this->config->get('root');

        return new Migrator($this->query, $root . '/migrations', new Logger($root . '/storage/logs/migrate.log', true));
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

