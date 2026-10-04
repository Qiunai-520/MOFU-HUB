<?php

declare(strict_types=1);

namespace Mofu\Core\Security;

/**
 * CSRF token 生成与校验。token 保存在服务端会话中。
 */
final class Csrf
{
    public static function token(SessionState $state): string
    {
        return $state->csrfToken();
    }

    public static function validate(SessionState $state, mixed $token): bool
    {
        $expected = $state->csrfToken();

        return $expected !== ''
            && is_string($token)
            && $token !== ''
            && hash_equals($expected, $token);
    }
}
