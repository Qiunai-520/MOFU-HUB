<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

/**
 * 账号与安全：更换邮箱（发送验证码 → 校验 → 改绑）。
 */
final class EmailChangeTest extends HttpTestCase
{
    public function testChangeEmailFlow(): void
    {
        [$cookie, $csrf, $username] = $this->registerUser('mail_user');
        $userId = $this->userId($username);
        $newEmail = 'changed_' . bin2hex(random_bytes(4)) . '@example.com';

        // 第一步：向新邮箱发送验证码
        $sent = $this->send('POST', '/settings/security/email', [
            'form' => ['new_email' => $newEmail, '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $sent->getStatusCode(), (string) $sent->getBody());

        $code = $this->mailCode($newEmail);
        self::assertNotNull($code, '未收到换邮箱验证码');

        // 第二步：校验验证码并改绑
        $verify = $this->send('POST', '/settings/security/email/verify', [
            'form' => ['new_email' => $newEmail, 'code' => $code, '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);
        self::assertSame(302, $verify->getStatusCode(), (string) $verify->getBody());

        self::assertSame(
            $newEmail,
            (string) $this->query()->value('SELECT email FROM users WHERE id = :id', ['id' => $userId]),
        );
    }

    public function testChangeEmailRejectsAddressInUse(): void
    {
        [$cookieA, $csrfA] = $this->registerUser('mail_a');
        [, , $userB] = $this->registerUser('mail_b');

        $emailB = (string) $this->query()->value('SELECT email FROM users WHERE username = :u', ['u' => $userB]);

        $response = $this->send('POST', '/settings/security/email', [
            'form' => ['new_email' => $emailB, '_csrf' => $csrfA],
            'cookie' => $cookieA,
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('已被其他账号使用', (string) $response->getBody());
    }

    public function testChangeEmailRejectsInvalidAddress(): void
    {
        [$cookie, $csrf] = $this->registerUser('mail_bad');

        $response = $this->send('POST', '/settings/security/email', [
            'form' => ['new_email' => 'not-an-email', '_csrf' => $csrf],
            'cookie' => $cookie,
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('邮箱格式不正确', (string) $response->getBody());
    }
}
