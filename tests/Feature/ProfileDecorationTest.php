<?php

declare(strict_types=1);

namespace Mofu\Tests\Feature;

use Mofu\Tests\Support\HttpTestCase;

final class ProfileDecorationTest extends HttpTestCase
{
    public function testUserCanEquipFreeFrameAndBadge(): void
    {
        [$cookie, $csrf] = $this->registerUser('decor');

        $page = $this->get('/settings/decorations', 'text/html', $cookie);
        $body = (string) $page->getBody();

        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('头像框主题', $body);
        self::assertStringContainsString('昵称挂件', $body);

        $saved = $this->send('POST', '/settings/decorations', [
            'form' => [
                '_csrf' => $csrf,
                'avatar_frame' => 'mint',
                'name_badge' => 'moon',
            ],
            'cookie' => $cookie,
        ]);

        self::assertSame(302, $saved->getStatusCode());
        self::assertSame('/settings/decorations?ok=1', $saved->getHeaderLine('Location'));

        $me = $this->get('/me', 'text/html', $cookie);
        $meBody = (string) $me->getBody();

        self::assertStringContainsString('avatar-frame--mint', $meBody);
        self::assertStringContainsString('name-badge', $meBody);
        self::assertStringContainsString('🌙', $meBody);
    }

    public function testLockedDecorationCannotBeEquipped(): void
    {
        [$cookie, $csrf] = $this->registerUser('decor_locked');

        $response = $this->send('POST', '/settings/decorations', [
            'form' => [
                '_csrf' => $csrf,
                'avatar_frame' => 'neon',
                'name_badge' => '',
            ],
            'cookie' => $cookie,
        ]);

        self::assertSame(422, $response->getStatusCode());
    }
}
