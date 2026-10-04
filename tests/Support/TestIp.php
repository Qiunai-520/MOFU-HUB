<?php

declare(strict_types=1);

namespace Mofu\Tests\Support;

/**
 * 测试用来源 IP 生成器。
 *
 * 限流器按 IP 计数（登录 20 次/15 分钟、注册 10 次/小时等），
 * 测试进程会把大量请求集中到同一 IP 上；为每个模拟请求注入独立的
 * 随机 IP，可避免跑测时误触发 429 限流。
 */
final class TestIp
{
    public static function next(): string
    {
        return sprintf(
            '10.%d.%d.%d',
            random_int(0, 255),
            random_int(0, 255),
            random_int(1, 254),
        );
    }
}
