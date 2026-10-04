<?php

declare(strict_types=1);

namespace Mofu\Tests\Unit;

use Mofu\Core\Support\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testRenderContentEscapesHtml(): void
    {
        $html = Text::renderContent('<script>alert("xss")</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderContentEscapesAttributes(): void
    {
        $html = Text::renderContent('<img src=x onerror="alert(1)">');

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function testRenderContentLinksHttpUrls(): void
    {
        $html = Text::renderContent('访问 https://example.com/page?a=1 查看');

        self::assertStringContainsString('<a href="https://example.com/page?a=1"', $html);
        self::assertStringContainsString('rel="nofollow noopener"', $html);
    }

    public function testRenderContentDoesNotLinkJavascriptScheme(): void
    {
        $html = Text::renderContent('javascript:alert(1)');

        self::assertStringNotContainsString('<a', $html);
    }

    public function testRenderContentKeepsNewlines(): void
    {
        $html = Text::renderContent("第一行\n第二行");

        self::assertStringContainsString('<br', $html);
    }

    public function testRenderContentSupportsMarkdownInline(): void
    {
        $html = Text::renderContent('**粗** *斜* ~~删~~ `code`');

        self::assertStringContainsString('<strong>粗</strong>', $html);
        self::assertStringContainsString('<em>斜</em>', $html);
        self::assertStringContainsString('<del>删</del>', $html);
        self::assertStringContainsString('<code>code</code>', $html);
    }

    public function testRenderContentSupportsMarkdownBlocks(): void
    {
        $html = Text::renderContent("# 标题\n> 引用\n- 甲\n- 乙\n1. 一\n2. 二");

        self::assertStringContainsString('<h3>标题</h3>', $html);
        self::assertStringContainsString('<blockquote>引用</blockquote>', $html);
        self::assertStringContainsString('<ul><li>甲</li><li>乙</li></ul>', $html);
        self::assertStringContainsString('<ol><li>一</li><li>二</li></ol>', $html);
    }

    public function testRenderContentMarkdownLinkIsSafe(): void
    {
        $html = Text::renderContent('[点我](https://example.com/x?y=1)' . "\n" . '[坏](javascript:alert(1))');

        self::assertStringContainsString('<a href="https://example.com/x?y=1"', $html);
        self::assertStringNotContainsString('href="javascript', $html);
    }

    public function testRenderContentCodeIsNotFormatted(): void
    {
        $html = Text::renderContent('`**not bold**`');

        self::assertStringContainsString('<code>**not bold**</code>', $html);
        self::assertStringNotContainsString('<strong>', $html);
    }

    public function testRelativeTime(): void
    {
        self::assertSame('刚刚', Text::relativeTime(date('Y-m-d H:i:s')));
        self::assertSame('5 分钟前', Text::relativeTime(date('Y-m-d H:i:s', time() - 5 * 60)));
        self::assertSame('2 小时前', Text::relativeTime(date('Y-m-d H:i:s', time() - 2 * 3600)));
        self::assertSame('3 天前', Text::relativeTime(date('Y-m-d H:i:s', time() - 3 * 86400)));
    }

    public function testExcerpt(): void
    {
        self::assertSame('短文本', Text::excerpt('短文本'));
        self::assertSame('abc…', Text::excerpt('abcdef', 3));
    }

    public function testSlugifyTopic(): void
    {
        self::assertSame('社团招新', Text::slugifyTopic('社团招新'));
        self::assertSame('hello-world', Text::slugifyTopic('Hello World'));
        self::assertSame('campus-life', Text::slugifyTopic('campus life!'));
        self::assertNotEmpty(Text::slugifyTopic('！！！'));
    }

    public function testNormalizeTopicName(): void
    {
        self::assertSame('话题', Text::normalizeTopicName('  #话题#  '));
    }
}
