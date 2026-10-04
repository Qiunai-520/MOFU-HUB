<?php

declare(strict_types=1);

namespace Mofu\Tests\Unit;

use Mofu\Core\Security\SessionManager;
use PHPUnit\Framework\TestCase;

final class SessionSecurityTest extends TestCase
{
    public function testSecureCookieOnlyForHttpsRequests(): void
    {
        self::assertFalse(SessionManager::isSecureRequest([]));
        self::assertFalse(SessionManager::isSecureRequest(['SERVER_PORT' => '80']));
        self::assertFalse(SessionManager::isSecureRequest(['HTTPS' => 'off']));

        self::assertTrue(SessionManager::isSecureRequest(['HTTPS' => 'on']));
        self::assertTrue(SessionManager::isSecureRequest(['HTTPS' => '1']));
        self::assertTrue(SessionManager::isSecureRequest(['SERVER_PORT' => 443]));
        self::assertTrue(SessionManager::isSecureRequest(['HTTP_X_FORWARDED_PROTO' => 'https']));
        self::assertTrue(SessionManager::isSecureRequest(['HTTP_X_FORWARDED_PROTO' => 'HTTPS']));
    }
}
