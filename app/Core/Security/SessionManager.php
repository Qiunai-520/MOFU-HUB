<?php

declare(strict_types=1);

namespace Mofu\Core\Security;

use Mofu\Bootstrap\Config;
use Mofu\Core\Database\Query;

/**
 * 数据库会话：创建、解析、轮换与销毁。
 * cookie 中只保存不可猜测的随机 id；CSRF token 保存在服务端。
 */
final class SessionManager
{
    public function __construct(
        private readonly Query $query,
        private readonly Config $config,
    ) {
    }

    public function cookieName(): string
    {
        return (string) $this->config->get('session.cookie', 'mofu_session');
    }

    /**
     * 解析 cookie 中的会话 id。
     *
     * @return array<string, mixed>|null
     */
    public function resolve(?string $id): ?array
    {
        $this->maybeGc();

        if ($id === null || !preg_match('/^[a-f0-9]{64}$/', $id)) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $row = $this->query->one(
            'SELECT * FROM user_sessions WHERE id = :id AND expires_at > :now',
            ['id' => $id, 'now' => $now],
        );

        if ($row === null) {
            return null;
        }

        if (strtotime((string) $row['last_seen_at']) < time() - 60) {
            $this->query->run(
                'UPDATE user_sessions SET last_seen_at = :now WHERE id = :id',
                ['id' => $id, 'now' => $now],
            );
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function create(?int $userId = null): array
    {
        $this->maybeGc();

        $id = bin2hex(random_bytes(32));
        $lifetime = (int) $this->config->get('session.lifetime', 2592000);
        $now = date('Y-m-d H:i:s');

        $this->query->insert('user_sessions', [
            'id' => $id,
            'user_id' => $userId,
            'csrf_token' => bin2hex(random_bytes(32)),
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'created_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => date('Y-m-d H:i:s', time() + $lifetime),
        ]);

        $created = $this->query->one('SELECT * FROM user_sessions WHERE id = :id', ['id' => $id]);

        return $created ?? [];
    }

    /**
     * 登录后轮换会话（防会话固定攻击），并刷新 CSRF token。
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed> 新会话
     */
    public function rotate(array $session, ?int $userId): array
    {
        $this->destroy((string) $session['id']);

        return $this->create($userId);
    }

    /**
     * 是否 HTTPS 请求。
     *
     * 不能用 APP_ENV 判断：纯 HTTP 站点若给 cookie 加 Secure，浏览器会直接丢弃，
     * 导致每次都新建会话、CSRF 令牌永远对不上（表现为登录/发帖 403）。
     *
     * @param array<string, mixed> $server
     */
    public static function isSecureRequest(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }

        if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443;
    }

    public function destroy(string $id): void
    {
        $this->query->delete('user_sessions', 'id = :id', ['id' => $id]);
    }

    /** 概率性清理过期会话，避免会话表无限增长 */
    private function maybeGc(): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $this->query->run(
            'DELETE FROM user_sessions WHERE expires_at < :now',
            ['now' => date('Y-m-d H:i:s')],
        );
    }

    /** cookie 值 = 会话 id（cookie 本身为 HttpOnly，JS 不可读） */
    public function cookieHeader(string $sessionId, bool $clear = false): string
    {
        $name = $this->cookieName();
        $secure = self::isSecureRequest($_SERVER ?? []) ? '; Secure' : '';

        if ($clear) {
            return sprintf('%s=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0%s', $name, $secure);
        }

        $lifetime = (int) $this->config->get('session.lifetime', 2592000);

        return sprintf('%s=%s; Path=/; HttpOnly; SameSite=Lax; Max-Age=%d%s', $name, $sessionId, $lifetime, $secure);
    }
}
