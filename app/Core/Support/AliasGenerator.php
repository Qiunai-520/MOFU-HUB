<?php

declare(strict_types=1);

namespace Mofu\Core\Support;

/**
 * 匿名化名生成器：为表白墙/树洞的匿名内容生成友好化名。
 */
final class AliasGenerator
{
    private const ADJECTIVES = [
        '温柔的', '爱做梦的', '安静的', '好奇的', '晚睡的', '会发光的', '爱笑的', '认真的',
        '迷路的', '抱着书的', '看星星的', '听风的', '晒太阳的', '慢热的', '喜欢猫的', '跑得快的',
        '写诗的', '捡树叶的', '喝热茶的', '发着呆的', '听歌的', '赶早课的', '在操场散步的', '等风的',
    ];

    private const CREATURES = [
        '小猫', '小鹿', '海豚', '企鹅', '水獭', '狐狸', '兔子', '猫头鹰',
        '鲸鱼', '柴犬', '刺猬', '海獭', '鹦鹉', '萤火虫', '小熊猫', '考拉',
        '仙鹤', '河马', '雪豹', '羊驼', '旅鸫', '松鼠', '熊猫', '章鱼',
    ];

    public static function generate(): string
    {
        return self::ADJECTIVES[random_int(0, count(self::ADJECTIVES) - 1)]
            . self::CREATURES[random_int(0, count(self::CREATURES) - 1)];
    }
}
