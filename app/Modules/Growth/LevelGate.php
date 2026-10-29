<?php

declare(strict_types=1);

namespace Mofu\Modules\Growth;

use Mofu\Core\Http\HttpException;

/**
 * 等级门槛：把「多少级能做什么」集中到一处，避免各模块各写一遍。
 *
 * 等级曲线见 level_configs：Lv0 未转正 / Lv1 正式会员 / Lv2 常驻 / Lv3 活跃 /
 * Lv4 资深 / Lv5 核心 / Lv6 荣誉（另有闪电 Lv6 硬核会员）。
 */
final class LevelGate
{
    /** 发帖、私信 */
    public const POST = 1;
    /** 评论（含回复）、每日领币 */
    public const COMMENT = 2;
    /** 为帖子添加标签 */
    public const TAG = 3;
    /** 申请风纪委员会 */
    public const JURY = 4;
    /** 购买邀请码 */
    public const INVITE = 5;

    public function __construct(
        private readonly LevelService $levels,
    ) {
    }

    public function levelOf(int $userId): int
    {
        return (int) ($this->levels->stats($userId)['level'] ?? 0);
    }

    public function allows(int $userId, int $required): bool
    {
        return $this->levelOf($userId) >= $required;
    }

    /** 不满足则抛 403，提示语形如「达到 Lv.2 才能发表评论」 */
    public function require(int $userId, int $required, string $action): void
    {
        if (!$this->allows($userId, $required)) {
            throw HttpException::forbidden(sprintf('达到 Lv.%d 才能%s', $required, $action));
        }
    }
}
