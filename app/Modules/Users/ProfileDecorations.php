<?php

declare(strict_types=1);

namespace Mofu\Modules\Users;

/**
 * 个人资料外观：头像框与昵称挂件。
 *
 * 钥匙同时出现在 shop_products.payload 中（frame / badge），
 * 用户设置页只允许装备免费项或已购买项。
 */
final class ProfileDecorations
{
    public const FRAMES = [
        '' => ['label' => '不使用头像框', 'class' => ''],
        'mint' => ['label' => '薄荷（免费）', 'class' => 'avatar-frame--mint'],
        'gold' => ['label' => '鎏金', 'class' => 'avatar-frame--gold'],
        'sakura' => ['label' => '樱花', 'class' => 'avatar-frame--sakura'],
        'ocean' => ['label' => '海盐', 'class' => 'avatar-frame--ocean'],
        'neon' => ['label' => '霓虹', 'class' => 'avatar-frame--neon'],
    ];

    public const BADGES = [
        '' => ['label' => '不使用挂件', 'emoji' => ''],
        'moon' => ['label' => '月牙（免费）', 'emoji' => '🌙'],
        'spark' => ['label' => '星芒', 'emoji' => '✨'],
        'sakura' => ['label' => '樱花', 'emoji' => '🌸'],
        'cat' => ['label' => '猫猫', 'emoji' => '🐈'],
        'heart' => ['label' => '心动', 'emoji' => '💖'],
        'crown' => ['label' => '小皇冠', 'emoji' => '👑'],
        'early' => ['label' => '早期成员', 'emoji' => '🎖️'],
        'mofu' => ['label' => 'MOFU 徽章', 'emoji' => '', 'image' => '/images/badge-mofu.svg'],
    ];

    public const FREE_FRAMES = ['mint'];
    public const FREE_BADGES = ['moon', 'mofu', 'heart'];

    /**
     * 把 user_profiles 行转换成模板可直接使用的展示字段。
     *
     * @param array<string, mixed> $row
     * @return array{avatar_frame: string, avatar_frame_class: string, name_badge: string, name_badge_emoji: string, name_badge_label: string, name_badge_image: string}
     */
    public static function present(array $row): array
    {
        $frame = (string) ($row['avatar_frame'] ?? '');
        $badge = (string) ($row['name_badge'] ?? '');
        $frame = isset(self::FRAMES[$frame]) ? $frame : '';
        $badge = isset(self::BADGES[$badge]) ? $badge : '';

        return [
            'avatar_frame' => $frame,
            'avatar_frame_class' => (string) (self::FRAMES[$frame]['class'] ?? ''),
            'name_badge' => $badge,
            'name_badge_emoji' => (string) (self::BADGES[$badge]['emoji'] ?? ''),
            'name_badge_label' => (string) (self::BADGES[$badge]['label'] ?? ''),
            'name_badge_image' => (string) (self::BADGES[$badge]['image'] ?? ''),
        ];
    }

    public static function isFreeFrame(string $key): bool
    {
        return $key === '' || in_array($key, self::FREE_FRAMES, true);
    }

    public static function isFreeBadge(string $key): bool
    {
        return $key === '' || in_array($key, self::FREE_BADGES, true);
    }
}
