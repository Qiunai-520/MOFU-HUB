<?php

declare(strict_types=1);

namespace Mofu\Modules\Appeals;

use Mofu\Core\Database\Query;
use Mofu\Core\Http\HttpException;
use Mofu\Modules\Notifications\NotificationService;

/**
 * 封禁/停用账号申诉：用户以「用户名 + 密码」验证身份后提交，
 * 管理员通过/驳回；通过后账号恢复为 active 并通知申诉人。
 */
final class AppealService
{
    public const REASON_MAX = 1000;
    public const REPLY_MAX = 500;

    public function __construct(
        private readonly Query $query,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * 提交申诉。
     *
     * @return array{id: int}
     */
    public function submit(string $username, string $password, string $reason): array
    {
        $username = strtolower(trim($username));
        $reason = trim($reason);

        $user = $this->query->one(
            'SELECT id, password_hash, status FROM users WHERE username = :username',
            ['username' => $username],
        );

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw HttpException::badRequest('账号或密码不正确');
        }

        $status = (string) $user['status'];

        if (!in_array($status, ['banned', 'suspended'], true)) {
            throw HttpException::badRequest('当前账号状态正常，无需申诉');
        }

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw HttpException::badRequest('请填写申诉理由（最多 ' . self::REASON_MAX . ' 字）');
        }

        $pending = $this->query->value(
            "SELECT id FROM user_appeals WHERE user_id = :id AND status = 'pending'",
            ['id' => (int) $user['id']],
        );

        if ($pending !== null) {
            throw HttpException::badRequest('已有一条申诉在处理中，请耐心等待');
        }

        $now = date('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(32));

        $id = $this->query->insert('user_appeals', [
            'user_id' => (int) $user['id'],
            'access_token' => $token,
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /**
     * 凭查看令牌取申诉状态（令牌在提交时随 Cookie 下发）。
     *
     * @return array<string, mixed>|null
     */
    public function latestForToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return $this->query->one(
            'SELECT status, admin_reply, created_at, handled_at
             FROM user_appeals
             WHERE access_token = :token
             ORDER BY id DESC
             LIMIT 1',
            ['token' => $token],
        );
    }

    /**
     * 后台列表。
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function listForAdmin(string $filter, int $page, int $perPage = 20): array
    {
        $where = $filter === 'pending' ? "a.status = 'pending'" : '1 = 1';

        $sql = 'SELECT a.*, u.username, u.status AS user_status, p.nickname,
                       hb.username AS handler_username
                FROM user_appeals a
                JOIN users u ON u.id = a.user_id
                LEFT JOIN user_profiles p ON p.user_id = u.id
                LEFT JOIN users hb ON hb.id = a.handled_by
                WHERE ' . $where . "
                ORDER BY (a.status = 'pending') DESC, a.id DESC";
        $countSql = 'SELECT COUNT(*) FROM user_appeals a WHERE ' . $where;

        return $this->query->paginate($sql, $countSql, [], $page, $perPage);
    }

    /**
     * 处理申诉。
     */
    public function resolve(int $appealId, int $adminId, bool $accept, string $reply): void
    {
        $appeal = $this->query->one('SELECT * FROM user_appeals WHERE id = :id', ['id' => $appealId]);

        if ($appeal === null) {
            throw HttpException::notFound('申诉不存在');
        }

        if ((string) $appeal['status'] !== 'pending') {
            throw HttpException::badRequest('该申诉已处理');
        }

        $reply = trim($reply);

        if (mb_strlen($reply) > self::REPLY_MAX) {
            throw HttpException::badRequest('处理说明最多 ' . self::REPLY_MAX . ' 字');
        }

        $now = date('Y-m-d H:i:s');
        $userId = (int) $appeal['user_id'];

        $this->query->transaction(function (Query $q) use ($appealId, $adminId, $accept, $reply, $now, $userId): void {
            $q->update('user_appeals', [
                'status' => $accept ? 'accepted' : 'rejected',
                'admin_reply' => $reply,
                'handled_by' => $adminId,
                'handled_at' => $now,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $appealId]);

            if ($accept) {
                $q->run(
                    "UPDATE users SET status = 'active', updated_at = :now
                     WHERE id = :id AND status IN ('banned', 'suspended')",
                    ['now' => $now, 'id' => $userId],
                );
            }
        });

        $message = $accept
            ? '你的账号申诉已通过，账号已恢复。' . ($reply !== '' ? '说明：' . $reply : '')
            : '你的账号申诉未通过。' . ($reply !== '' ? '说明：' . $reply : '');

        $this->notifications->push($userId, null, 'system', '', null, ['message' => $message]);
    }
}
