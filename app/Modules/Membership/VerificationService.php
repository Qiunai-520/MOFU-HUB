<?php

declare(strict_types=1);

namespace Mofu\Modules\Membership;

use Mofu\Core\Database\Query;
use Mofu\Core\Security\RateLimiter;

/**
 * 邮箱验证码（注册 / 重置密码）。验证码只存哈希，限时且限制尝试次数。
 */
final class VerificationService
{
    public const TTL_MINUTES = 15;
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Query $query,
        private readonly MailService $mail,
        private readonly RateLimiter $limiter,
        private readonly string $secret,
        private readonly string $appName,
    ) {
    }

    public function mailConfigured(): bool
    {
        return $this->mail->enabled();
    }

    /**
     * 发送验证码。
     *
     * @return array{ok: bool, error: string}
     */
    public function send(string $email, string $purpose): array
    {
        if (!$this->limiter->attempt('verify-send:' . $email . ':' . $purpose, 5, 600)) {
            return ['ok' => false, 'error' => '发送过于频繁，请稍后再试'];
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = date('Y-m-d H:i:s');

        $this->query->delete('email_verifications', 'email = :email AND purpose = :purpose', [
            'email' => $email,
            'purpose' => $purpose,
        ]);

        $this->query->insert('email_verifications', [
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => $this->hash($code),
            'attempts' => 0,
            'expires_at' => date('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
            'created_at' => $now,
        ]);

        $subject = $this->appName . ' 验证码';
        $body = sprintf(
            "你的验证码是：%s\n\n%d 分钟内有效，请勿转发给他人。\n如果不是你本人操作，请忽略本邮件。",
            $code,
            self::TTL_MINUTES,
        );

        if (!$this->mail->send($email, $subject, $body)) {
            return ['ok' => false, 'error' => '验证码发送失败，请稍后重试'];
        }

        return ['ok' => true, 'error' => ''];
    }

    public function verify(string $email, string $purpose, string $code): bool
    {
        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $row = $this->query->one(
            'SELECT * FROM email_verifications WHERE email = :email AND purpose = :purpose ORDER BY id DESC LIMIT 1',
            ['email' => $email, 'purpose' => $purpose],
        );

        if ($row === null) {
            return false;
        }

        if ((int) $row['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }

        if ((string) $row['expires_at'] < date('Y-m-d H:i:s')) {
            return false;
        }

        if (!hash_equals((string) $row['code_hash'], $this->hash($code))) {
            $this->query->run('UPDATE email_verifications SET attempts = attempts + 1 WHERE id = :id', ['id' => (int) $row['id']]);

            return false;
        }

        $this->query->delete('email_verifications', 'id = :id', ['id' => (int) $row['id']]);

        return true;
    }

    /** 验证码哈希密钥：优先 APP_KEY，否则由数据库口令派生（仅服务端可见） */
    public static function secretFrom(\Mofu\Bootstrap\Config $config): string
    {
        $key = (string) $config->get('app.key', '');

        if ($key === '') {
            $key = hash('sha256', (string) $config->get('db.password', '') . '|' . (string) $config->get('app.url', ''));
        }

        return hash('sha256', 'mofu.verify|' . $key);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret);
    }
}
