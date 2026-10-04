<?php

declare(strict_types=1);

namespace Mofu\Modules\Events;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Core\Support\Text;
use Mofu\Core\View\Present;
use Mofu\Modules\Media\MediaService;
use Mofu\Modules\Notifications\NotificationService;
use Psr\Http\Message\UploadedFileInterface;

/**
 * 校园活动：发布、报名（直接 / 审核制）、名单管理与取消。
 *
 * 显示状态由时间派生：upcoming / ongoing / ended / cancelled；
 * 结束时间缺省时按「开始日次日 00:00」视为结束。
 */
final class EventService
{
    public const TITLE_MIN = 2;
    public const TITLE_MAX = 60;
    public const DESC_MAX = 5000;
    public const LOCATION_MAX = 80;
    public const NOTE_MAX = 200;
    public const CAPACITY_MAX = 10000;

    /** @var list<string> */
    public const TIME_TABS = ['upcoming', 'ongoing', 'past', 'all'];

    public function __construct(
        private readonly Query $query,
        private readonly NotificationService $notifications,
        private readonly MediaService $media,
    ) {
    }

    /**
     * 公开活动列表（仅 published，不含已取消）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listPage(string $tab, int $page, ?int $viewerId, int $perPage = 12): array
    {
        $tab = in_array($tab, self::TIME_TABS, true) ? $tab : 'upcoming';
        $now = date('Y-m-d H:i:s');

        $base = $this->baseFrom() . " WHERE e.status = 'published' AND e.deleted_at IS NULL";
        $params = [];
        $order = 'ORDER BY e.start_at DESC, e.id DESC';

        if ($tab === 'upcoming') {
            $base .= ' AND e.start_at > :now';
            $params['now'] = $now;
            $order = 'ORDER BY e.start_at ASC, e.id ASC';
        } elseif ($tab === 'ongoing') {
            $base .= ' AND e.start_at <= :now_start AND COALESCE(e.end_at, DATE_ADD(DATE(e.start_at), INTERVAL 1 DAY)) > :now_end';
            $params['now_start'] = $now;
            $params['now_end'] = $now;
        } elseif ($tab === 'past') {
            $base .= ' AND COALESCE(e.end_at, DATE_ADD(DATE(e.start_at), INTERVAL 1 DAY)) <= :now';
            $params['now'] = $now;
        }

        $sql = 'SELECT e.*, ' . $this->selectExtras() . ' ' . $base . ' ' . $order;
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = $this->presentRows($result['items'], $viewerId);

        return $result;
    }

    /**
     * 我的活动：我报名的（pending/approved）/ 我发起的（含已取消与隐藏，仅排除已删除）。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function minePage(int $userId, string $tab, int $page, int $perPage = 10): array
    {
        $tab = $tab === 'organized' ? 'organized' : 'joined';
        $params = ['user' => $userId];

        if ($tab === 'organized') {
            $base = $this->baseFrom() . ' WHERE e.user_id = :user AND e.deleted_at IS NULL';
        } else {
            $base = $this->baseFrom() . "
                     JOIN event_signups es ON es.event_id = e.id AND es.user_id = :user
                     WHERE e.deleted_at IS NULL AND es.status IN ('pending', 'approved')";
        }

        $order = 'ORDER BY e.start_at DESC, e.id DESC';
        $sql = 'SELECT e.*, ' . $this->selectExtras() . ' ' . $base . ' ' . $order;
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = $this->presentRows($result['items'], $userId);

        return $result;
    }

    /**
     * 批量查询当前用户对一组活动的报名状态。
     *
     * @param list<int> $eventIds
     * @return array<int, string> event_id => status
     */
    public function mySignupStates(array $eventIds, ?int $userId): array
    {
        if ($userId === null || $eventIds === []) {
            return [];
        }

        [$placeholders, $params] = $this->inClause('e', $eventIds);
        $params['user'] = $userId;

        $rows = $this->query->all(
            sprintf(
                'SELECT event_id, status FROM event_signups WHERE user_id = :user AND event_id IN (%s)',
                $placeholders,
            ),
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['event_id']] = (string) $row['status'];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->query->one(
            'SELECT e.*, ' . $this->selectExtras() . ' ' . $this->baseFrom() . ' WHERE e.id = :id',
            ['id' => $id],
        );
    }

    /** @return array<string, mixed> */
    public function findOrFail(int $id): array
    {
        $event = $this->find($id);

        if ($event === null || $event['deleted_at'] !== null) {
            throw HttpException::notFound('活动不存在或已删除');
        }

        return $event;
    }

    /**
     * 创建活动（可选封面）。
     *
     * @param array<string, mixed> $input
     */
    public function create(int $userId, array $input, ?UploadedFileInterface $cover): int
    {
        $data = $this->validate($input);
        $coverMediaId = $this->storeCover($userId, $cover);
        $now = date('Y-m-d H:i:s');

        return $this->query->insert('events', [
            'user_id' => $userId,
            'title' => $data['title'],
            'description' => $data['description'],
            'location' => $data['location'],
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'signup_deadline' => $data['signup_deadline'],
            'capacity' => $data['capacity'],
            'requires_approval' => $data['requires_approval'] ? 1 : 0,
            'cover_media_id' => $coverMediaId,
            'status' => 'published',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string, mixed> $event */
    public function canEdit(array $event, int $userId): bool
    {
        return (int) $event['user_id'] === $userId
            && (string) $event['status'] === 'published'
            && strtotime((string) $event['start_at']) > time();
    }

    /**
     * 编辑活动（仅组织者；开始前、未取消）；新封面会替换旧封面。
     *
     * @param array<string, mixed> $input
     */
    public function update(int $eventId, int $userId, array $input, ?UploadedFileInterface $cover): void
    {
        $event = $this->findOrFail($eventId);

        if (!$this->canEdit($event, $userId)) {
            throw HttpException::badRequest('活动已开始或已取消，无法编辑');
        }

        $data = $this->validate($input);
        $coverMediaId = $this->storeCover($userId, $cover);

        $update = [
            'title' => $data['title'],
            'description' => $data['description'],
            'location' => $data['location'],
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'signup_deadline' => $data['signup_deadline'],
            'capacity' => $data['capacity'],
            'requires_approval' => $data['requires_approval'] ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($coverMediaId !== null) {
            $update['cover_media_id'] = $coverMediaId;
        }

        $this->query->update('events', $update, 'id = :id', ['id' => $eventId]);
    }

    /**
     * 报名活动，返回报名状态（approved / pending）。
     */
    public function signup(int $eventId, int $userId, string $note): string
    {
        $event = $this->findOrFail($eventId);
        $this->assertSignable($event, $userId);

        $note = trim($note);

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw HttpException::badRequest('备注最多 ' . self::NOTE_MAX . ' 字');
        }

        $existing = $this->signupRow($eventId, $userId);

        if ($existing !== null) {
            $status = (string) $existing['status'];

            if ($status === 'approved') {
                throw HttpException::badRequest('你已报名该活动');
            }

            if ($status === 'pending') {
                throw HttpException::badRequest('报名已提交，等待组织者审核');
            }
        }

        $approval = (int) $event['requires_approval'] === 1;
        $newStatus = $approval ? 'pending' : 'approved';
        $now = date('Y-m-d H:i:s');

        $this->query->transaction(function (Query $q) use ($eventId, $userId, $note, $existing, $newStatus, $event, $now): void {
            if ($newStatus === 'approved') {
                $this->assertCapacity($q, $event);
            }

            if ($existing === null) {
                $q->insert('event_signups', [
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'status' => $newStatus,
                    'note' => $note,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $q->update('event_signups', [
                    'status' => $newStatus,
                    'note' => $note,
                    'updated_at' => $now,
                ], 'event_id = :event AND user_id = :user', ['event' => $eventId, 'user' => $userId]);
            }

            if ($newStatus === 'approved') {
                $q->run('UPDATE events SET signup_count = signup_count + 1 WHERE id = :id', ['id' => $eventId]);
            }
        });

        if ($approval) {
            $this->notifications->push((int) $event['user_id'], $userId, 'event_signup_pending', 'event', $eventId, [
                'message' => '有人报名了你的活动「' . (string) $event['title'] . '」，等待审核',
                'url' => self::eventUrl($eventId),
            ]);
        }

        return $newStatus;
    }

    public function cancelSignup(int $eventId, int $userId): void
    {
        $event = $this->findOrFail($eventId);

        if (strtotime((string) $event['start_at']) <= time()) {
            throw HttpException::badRequest('活动已开始，请联系组织者');
        }

        $row = $this->signupRow($eventId, $userId);

        if ($row === null || !in_array((string) $row['status'], ['approved', 'pending'], true)) {
            throw HttpException::badRequest('你没有可取消的报名');
        }

        $approved = (string) $row['status'] === 'approved';
        $now = date('Y-m-d H:i:s');

        $this->query->transaction(function (Query $q) use ($eventId, $userId, $approved, $now): void {
            $q->update('event_signups', [
                'status' => 'cancelled',
                'updated_at' => $now,
            ], 'event_id = :event AND user_id = :user', ['event' => $eventId, 'user' => $userId]);

            if ($approved) {
                $q->run('UPDATE events SET signup_count = GREATEST(0, signup_count - 1) WHERE id = :id', ['id' => $eventId]);
            }
        });
    }

    /**
     * 组织者管理报名：approve / reject / remove。
     */
    public function manageSignup(int $eventId, int $actorId, int $targetUserId, string $action): void
    {
        $event = $this->findOrFail($eventId);

        if ((int) $event['user_id'] !== $actorId) {
            throw HttpException::forbidden('只有组织者可以管理报名');
        }

        $row = $this->signupRow($eventId, $targetUserId);

        if ($row === null) {
            throw HttpException::notFound('报名记录不存在');
        }

        $status = (string) $row['status'];
        $now = date('Y-m-d H:i:s');

        if ($action === 'approve') {
            if ($status !== 'pending') {
                throw HttpException::badRequest('该报名不在待审核状态');
            }

            $this->query->transaction(function (Query $q) use ($event, $eventId, $targetUserId, $now): void {
                $this->assertCapacity($q, $event);

                $q->update('event_signups', ['status' => 'approved', 'updated_at' => $now],
                    'event_id = :event AND user_id = :user', ['event' => $eventId, 'user' => $targetUserId]);

                $q->run('UPDATE events SET signup_count = signup_count + 1 WHERE id = :id', ['id' => $eventId]);
            });

            $this->notifications->push($targetUserId, $actorId, 'event_signup_result', 'event', $eventId, [
                'message' => '你报名的活动「' . (string) $event['title'] . '」已通过审核',
                'url' => self::eventUrl($eventId),
            ]);

            return;
        }

        if ($action === 'reject') {
            if ($status !== 'pending') {
                throw HttpException::badRequest('该报名不在待审核状态');
            }

            $this->query->update('event_signups', ['status' => 'rejected', 'updated_at' => $now],
                'event_id = :event AND user_id = :user', ['event' => $eventId, 'user' => $targetUserId]);

            $this->notifications->push($targetUserId, $actorId, 'event_signup_result', 'event', $eventId, [
                'message' => '很抱歉，你报名的活动「' . (string) $event['title'] . '」未通过审核',
                'url' => self::eventUrl($eventId),
            ]);

            return;
        }

        if ($action === 'remove') {
            if ($status !== 'approved') {
                throw HttpException::badRequest('该报名不在已通过状态');
            }

            $this->query->transaction(function (Query $q) use ($eventId, $targetUserId, $now): void {
                $q->update('event_signups', ['status' => 'removed', 'updated_at' => $now],
                    'event_id = :event AND user_id = :user', ['event' => $eventId, 'user' => $targetUserId]);

                $q->run('UPDATE events SET signup_count = GREATEST(0, signup_count - 1) WHERE id = :id', ['id' => $eventId]);
            });
        }
    }

    /**
     * 取消活动并通知全部已通过报名者。
     */
    public function cancelEvent(int $eventId, int $actorId, bool $canModerate): void
    {
        $event = $this->findOrFail($eventId);

        if ((int) $event['user_id'] !== $actorId && !$canModerate) {
            throw HttpException::forbidden();
        }

        if ((string) $event['status'] === 'cancelled') {
            throw HttpException::badRequest('活动已取消');
        }

        $this->query->update('events', [
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $eventId]);

        $rows = $this->query->all(
            "SELECT user_id FROM event_signups WHERE event_id = :event AND status = 'approved'",
            ['event' => $eventId],
        );

        foreach ($rows as $row) {
            $this->notifications->push((int) $row['user_id'], $actorId, 'event_cancelled', 'event', $eventId, [
                'message' => '活动「' . (string) $event['title'] . '」已被取消',
                'url' => self::eventUrl($eventId),
            ]);
        }
    }

    public function deleteEvent(int $eventId, int $actorId, bool $canModerate): void
    {
        $event = $this->findOrFail($eventId);

        if ((int) $event['user_id'] !== $actorId && !$canModerate) {
            throw HttpException::forbidden();
        }

        $this->query->update('events', [
            'deleted_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $eventId]);
    }

    /**
     * 报名名单（待审核 + 已通过）。
     *
     * @return array{pending: list<array<string, mixed>>, approved: list<array<string, mixed>>}
     */
    public function signups(int $eventId): array
    {
        $rows = $this->query->all(
            "SELECT es.*, u.username, u.status AS user_status, pr.nickname,
                    am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path
             FROM event_signups es
             JOIN users u ON u.id = es.user_id
             LEFT JOIN user_profiles pr ON pr.user_id = u.id
             LEFT JOIN media am ON am.id = pr.avatar_media_id
             WHERE es.event_id = :event AND es.status IN ('pending', 'approved')
             ORDER BY es.updated_at DESC, es.created_at DESC
             LIMIT 500",
            ['event' => $eventId],
        );

        $pending = [];
        $approved = [];

        foreach ($rows as $row) {
            $presented = $this->presentSignup($row);

            if ((string) $row['status'] === 'pending') {
                $pending[] = $presented;
            } else {
                $approved[] = $presented;
            }
        }

        return ['pending' => $pending, 'approved' => $approved];
    }

    // ---------------------------------------------------------------- 后台管理

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function adminPage(string $status, int $page, int $perPage = 20): array
    {
        $conditions = [];
        $params = [];

        if (in_array($status, ['published', 'cancelled', 'hidden', 'removed'], true)) {
            $conditions[] = 'e.status = :status';
            $params['status'] = $status;
        }

        $where = $conditions === [] ? '1 = 1' : implode(' AND ', $conditions);

        $base = $this->baseFrom() . ' WHERE ' . $where;
        $sql = 'SELECT e.*, ' . $this->selectExtras() . ' ' . $base . ' ORDER BY e.id DESC';
        $countSql = 'SELECT COUNT(*) ' . $base;

        $result = $this->query->paginate($sql, $countSql, $params, $page, $perPage);
        $result['items'] = array_map(static function (array $row): array {
            $startTs = strtotime((string) $row['start_at']) ?: 0;

            return [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'username' => (string) $row['username'],
                'start_text' => date('Y-m-d H:i', $startTs),
                'signup_count' => (int) $row['signup_count'],
                'status' => (string) $row['status'],
                'deleted' => $row['deleted_at'] !== null,
                'url' => self::eventUrl((int) $row['id']),
            ];
        }, $result['items']);

        return $result;
    }

    public function setStatus(int $eventId, string $status): void
    {
        if (!in_array($status, ['published', 'hidden', 'removed'], true)) {
            throw HttpException::badRequest('状态无效');
        }

        if ($this->query->value('SELECT 1 FROM events WHERE id = :id', ['id' => $eventId]) === null) {
            throw HttpException::notFound('活动不存在');
        }

        $this->query->update('events', [
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $eventId]);
    }

    public function adminDelete(int $eventId): void
    {
        if ($this->query->value('SELECT 1 FROM events WHERE id = :id', ['id' => $eventId]) === null) {
            throw HttpException::notFound('活动不存在');
        }

        $this->query->update('events', ['deleted_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $eventId]);
    }

    // ---------------------------------------------------------------- 展示

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function present(array $row, ?int $viewerId, ?string $myStatus): array
    {
        $organizerId = (int) $row['user_id'];
        $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
            ? (string) $row['nickname']
            : (string) $row['username'];
        $startTs = strtotime((string) $row['start_at']) ?: time();
        $endTs = $row['end_at'] !== null ? (strtotime((string) $row['end_at']) ?: null) : null;
        $deadlineTs = $row['signup_deadline'] !== null ? (strtotime((string) $row['signup_deadline']) ?: null) : null;

        $state = $this->deriveState($row);
        $capacity = (int) $row['capacity'];
        $signupCount = (int) $row['signup_count'];
        $isFull = $state === 'upcoming' && $capacity > 0 && $signupCount >= $capacity;

        $canSignup = true;
        $signupHint = '';

        if ($state === 'cancelled') {
            $canSignup = false;
            $signupHint = '活动已取消';
        } elseif ($state === 'ongoing') {
            $canSignup = false;
            $signupHint = '活动已开始';
        } elseif ($state === 'ended') {
            $canSignup = false;
            $signupHint = '活动已结束';
        } elseif ($viewerId !== null && $viewerId === $organizerId) {
            $canSignup = false;
            $signupHint = '你是组织者';
        } elseif ($deadlineTs !== null && $deadlineTs < time()) {
            $canSignup = false;
            $signupHint = '报名已截止';
        } elseif ($isFull) {
            $canSignup = false;
            $signupHint = '名额已满';
        } elseif ($myStatus === 'approved') {
            $canSignup = false;
            $signupHint = '你已报名';
        } elseif ($myStatus === 'pending') {
            $canSignup = false;
            $signupHint = '报名待审核';
        }

        $stateLabels = ['upcoming' => '即将开始', 'ongoing' => '进行中', 'ended' => '已结束', 'cancelled' => '已取消'];
        $stateVariants = ['upcoming' => 'accent', 'ongoing' => 'accent', 'ended' => 'muted', 'cancelled' => 'warn'];

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'description_html' => Text::renderContent((string) $row['description']),
            'excerpt' => Text::excerpt((string) $row['description']),
            'location' => (string) $row['location'],
            'status' => (string) $row['status'],
            'state' => $state,
            'state_label' => $stateLabels[$state],
            'state_variant' => $stateVariants[$state],
            'is_hidden' => !in_array((string) $row['status'], ['published', 'cancelled'], true),
            'requires_approval' => (int) $row['requires_approval'] === 1,
            'is_full' => $isFull,
            'capacity' => $capacity,
            'signup_count' => $signupCount,
            'capacity_text' => $capacity > 0
                ? '已报名 ' . $signupCount . '/' . $capacity
                : '已报名 ' . $signupCount,
            'start_text' => date('n月j日 H:i', $startTs),
            'time_text' => $this->timeText($startTs, $endTs),
            'deadline_text' => $deadlineTs !== null ? date('n月j日 H:i', $deadlineTs) : '',
            'created_at_iso' => date('c', $startTs),
            'cover_url' => Present::uploadUrl(
                isset($row['cover_thumb']) ? (string) $row['cover_thumb'] : null,
                isset($row['cover_path']) ? (string) $row['cover_path'] : null,
            ),
            'organizer' => [
                'id' => $organizerId,
                'username' => (string) $row['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($organizerId),
                'url' => '/u/' . (string) $row['username'],
                'avatar_url' => Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                ),
            ],
            'is_organizer' => $viewerId !== null && $viewerId === $organizerId,
            'can_edit' => $viewerId !== null && $this->canEdit($row, $viewerId),
            'can_signup' => $canSignup && $viewerId !== null,
            'signup_hint' => $signupHint,
            'my_status' => $myStatus,
            'url' => self::eventUrl((int) $row['id']),
            'edit_url' => '/events/' . (int) $row['id'] . '/edit',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentSignup(array $row): array
    {
        $userId = (int) $row['user_id'];
        $nickname = trim((string) ($row['nickname'] ?? '')) !== ''
            ? (string) $row['nickname']
            : (string) $row['username'];

        return [
            'user_id' => $userId,
            'status' => (string) $row['status'],
            'note' => (string) $row['note'],
            'time_ago' => Text::relativeTime((string) $row['updated_at']),
            'user' => [
                'id' => $userId,
                'username' => (string) $row['username'],
                'nickname' => $nickname,
                'initial' => mb_substr($nickname, 0, 1),
                'hue' => Present::avatarHue($userId),
                'url' => '/u/' . (string) $row['username'],
                'avatar_url' => Present::uploadUrl(
                    isset($row['avatar_thumb']) ? (string) $row['avatar_thumb'] : null,
                    isset($row['avatar_path']) ? (string) $row['avatar_path'] : null,
                ),
            ],
        ];
    }

    private function timeText(int $startTs, ?int $endTs): string
    {
        $start = date('n月j日 H:i', $startTs);

        if ($endTs === null) {
            return $start;
        }

        return $start . ' – ' . (date('Y-m-d', $startTs) === date('Y-m-d', $endTs)
            ? date('H:i', $endTs)
            : date('n月j日 H:i', $endTs));
    }

    /** @param array<string, mixed> $row */
    private function deriveState(array $row): string
    {
        if ((string) $row['status'] === 'cancelled') {
            return 'cancelled';
        }

        $startTs = strtotime((string) $row['start_at']) ?: 0;

        if ($startTs > time()) {
            return 'upcoming';
        }

        return time() < $this->effectiveEnd($row) ? 'ongoing' : 'ended';
    }

    /**
     * 结束时间缺省时按「开始日次日 00:00」处理。
     *
     * @param array<string, mixed> $row
     */
    private function effectiveEnd(array $row): int
    {
        if ($row['end_at'] !== null) {
            return strtotime((string) $row['end_at']) ?: 0;
        }

        $startTs = strtotime((string) $row['start_at']) ?: 0;

        return $startTs === 0 ? 0 : strtotime(date('Y-m-d 00:00:00', $startTs) . ' +1 day');
    }

    // ---------------------------------------------------------------- 内部

    /**
     * @param array<string, mixed> $input
     * @return array{title: string, description: string, location: string, start_at: string, end_at: string|null, signup_deadline: string|null, capacity: int, requires_approval: bool}
     */
    private function validate(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));
        $startRaw = trim((string) ($input['start_at'] ?? ''));
        $endRaw = trim((string) ($input['end_at'] ?? ''));
        $deadlineRaw = trim((string) ($input['signup_deadline'] ?? ''));
        $capacityRaw = trim((string) ($input['capacity'] ?? ''));
        $requiresApproval = (string) ($input['requires_approval'] ?? '') === '1';

        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw HttpException::badRequest('标题需为 ' . self::TITLE_MIN . '-' . self::TITLE_MAX . ' 字');
        }

        if ($description === '') {
            throw HttpException::badRequest('请填写活动说明');
        }

        if (mb_strlen($description) > self::DESC_MAX) {
            throw HttpException::badRequest('活动说明最多 ' . self::DESC_MAX . ' 字');
        }

        if (mb_strlen($location) > self::LOCATION_MAX) {
            throw HttpException::badRequest('地点最多 ' . self::LOCATION_MAX . ' 字');
        }

        $startAt = $this->normalizeDateTime($startRaw);

        if ($startAt === null) {
            throw HttpException::badRequest('请填写有效的开始时间');
        }

        if (strtotime($startAt) <= time()) {
            throw HttpException::badRequest('开始时间需晚于当前时间');
        }

        $endAt = null;

        if ($endRaw !== '') {
            $endAt = $this->normalizeDateTime($endRaw);

            if ($endAt === null) {
                throw HttpException::badRequest('结束时间格式不正确');
            }

            if (strtotime($endAt) < strtotime($startAt)) {
                throw HttpException::badRequest('结束时间不能早于开始时间');
            }
        }

        $deadline = null;

        if ($deadlineRaw !== '') {
            $deadline = $this->normalizeDateTime($deadlineRaw);

            if ($deadline === null) {
                throw HttpException::badRequest('报名截止时间格式不正确');
            }

            if (strtotime($deadline) > strtotime($startAt)) {
                throw HttpException::badRequest('报名截止时间不能晚于活动开始时间');
            }
        }

        if ($capacityRaw === '') {
            $capacityRaw = '0';
        }

        if (!preg_match('/^\d{1,5}$/', $capacityRaw) || (int) $capacityRaw > self::CAPACITY_MAX) {
            throw HttpException::badRequest('名额需为 0-' . self::CAPACITY_MAX . ' 的整数（0 表示不限）');
        }

        return [
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'signup_deadline' => $deadline,
            'capacity' => (int) $capacityRaw,
            'requires_approval' => $requiresApproval,
        ];
    }

    /** 兼容 datetime-local（Y-m-d\TH:i）与常见日期时间字符串 */
    private function normalizeDateTime(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        $raw = str_replace('T', ' ', $raw);

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw) === 1) {
            $raw .= ':00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $raw .= ' 00:00:00';
        }

        $ts = strtotime($raw);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }

    /** @param array<string, mixed> $event */
    private function assertSignable(array $event, int $userId): void
    {
        if ((string) $event['status'] === 'cancelled') {
            throw HttpException::badRequest('活动已取消');
        }

        if ((string) $event['status'] !== 'published') {
            throw HttpException::notFound('活动不存在或不可报名');
        }

        if ((int) $event['user_id'] === $userId) {
            throw HttpException::badRequest('你是组织者，无需报名');
        }

        if (strtotime((string) $event['start_at']) <= time()) {
            throw HttpException::badRequest('活动已开始，无法报名');
        }

        if ($event['signup_deadline'] !== null && strtotime((string) $event['signup_deadline']) < time()) {
            throw HttpException::badRequest('报名已截止');
        }
    }

    /** @param array<string, mixed> $event */
    private function assertCapacity(Query $q, array $event): void
    {
        $capacity = (int) $event['capacity'];

        if ($capacity <= 0) {
            return;
        }

        $approved = (int) $q->value(
            "SELECT COUNT(*) FROM event_signups WHERE event_id = :event AND status = 'approved'",
            ['event' => (int) $event['id']],
        );

        if ($approved >= $capacity) {
            throw HttpException::badRequest('名额已满');
        }
    }

    /** @return array<string, mixed>|null */
    private function signupRow(int $eventId, int $userId): ?array
    {
        return $this->query->one(
            'SELECT * FROM event_signups WHERE event_id = :event AND user_id = :user',
            ['event' => $eventId, 'user' => $userId],
        );
    }

    private function storeCover(int $userId, ?UploadedFileInterface $cover): ?int
    {
        if ($cover === null || $cover->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $media = $this->media->storeImage($userId, $cover);

        return (int) $media['id'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function presentRows(array $rows, ?int $viewerId): array
    {
        $states = $this->mySignupStates(
            array_map(static fn (array $row): int => (int) $row['id'], $rows),
            $viewerId,
        );

        return array_map(
            fn (array $row): array => $this->present($row, $viewerId, $states[(int) $row['id']] ?? null),
            $rows,
        );
    }

    private function selectExtras(): string
    {
        return 'u.username, u.status AS organizer_status, pr.nickname,
                cm.thumb_path AS cover_thumb, cm.disk_path AS cover_path,
                am.thumb_path AS avatar_thumb, am.disk_path AS avatar_path';
    }

    private function baseFrom(): string
    {
        return 'FROM events e
                JOIN users u ON u.id = e.user_id
                LEFT JOIN user_profiles pr ON pr.user_id = u.id
                LEFT JOIN media cm ON cm.id = e.cover_media_id
                LEFT JOIN media am ON am.id = pr.avatar_media_id';
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private function inClause(string $prefix, array $ids): array
    {
        $placeholders = [];
        $params = [];

        foreach (array_values($ids) as $i => $id) {
            $placeholders[] = ':' . $prefix . '_' . $i;
            $params[$prefix . '_' . $i] = (int) $id;
        }

        return [implode(', ', $placeholders), $params];
    }

    public static function eventUrl(int $eventId): string
    {
        return '/events/' . $eventId;
    }
}
