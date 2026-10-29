<?php

declare(strict_types=1);

namespace Mofu\Tests\Support;

use Mofu\Core\Database\Query;

/**
 * 测试用：把用户提升为正式会员，以便通过私信（Lv.1）、评论（Lv.2）等等级门槛。
 *
 * 独立于 HttpTestCase，因为部分测试类直接继承 PHPUnit\Framework\TestCase。
 */
final class MemberFixture
{
    public static function promoteByUsername(Query $query, string $username, int $level = 2): void
    {
        $xp = (int) $query->value('SELECT xp_required FROM level_configs WHERE level = :level', ['level' => $level]);

        $query->run('UPDATE users SET is_formal = 1 WHERE username = :u', ['u' => $username]);
        $query->run(
            'INSERT INTO user_levels (user_id, xp, level, updated_at)
             VALUES ((SELECT id FROM users WHERE username = :u), :xp, :level, :now)
             ON DUPLICATE KEY UPDATE xp = VALUES(xp), level = VALUES(level), updated_at = VALUES(updated_at)',
            ['u' => $username, 'xp' => $xp, 'level' => $level, 'now' => date('Y-m-d H:i:s')],
        );
    }
}
