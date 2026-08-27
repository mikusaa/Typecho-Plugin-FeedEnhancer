<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Typecho\Plugin;
use TypechoPlugin\FeedEnhancer\Feed\ContentTruncator;
use TypechoPlugin\FeedEnhancer\Runtime\Bootstrap;
use TypechoPlugin\FeedEnhancer\Runtime\RequestContext;

require_once dirname(__DIR__) . '/Stubs/Typecho/PluginFactoryStub.php';
require_once dirname(__DIR__) . '/Stubs/Typecho/Plugin.php';

final class BootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        Plugin::$registrations = [];
        Plugin::$callbacks = [];
    }

    public function testContentObserversUseTheStableTypechoCompatibilityHandle(): void
    {
        Bootstrap::register();

        self::assertContains(
            ['Widget_Abstract_Contents', 'contentEx_9999'],
            Plugin::$registrations
        );
        self::assertContains(
            ['Widget_Abstract_Contents', 'excerptEx_9999'],
            Plugin::$registrations
        );
        self::assertNotContains(
            ['Widget\\Base\\Contents', 'contentEx_9999'],
            Plugin::$registrations
        );
        self::assertContains(
            ['Widget_Abstract_Contents', 'contentEx_9998'],
            Plugin::$registrations
        );
        self::assertContains(
            ['Widget_Abstract_Contents', 'excerptEx_9998'],
            Plugin::$registrations
        );
        self::assertNotContains(
            ['Widget\\Base\\Contents', 'excerptEx_9998'],
            Plugin::$registrations
        );
        self::assertSame(
            [ContentTruncator::class, 'content'],
            Plugin::$callbacks['Widget_Abstract_Contents']['contentEx_9998']
        );
        self::assertSame(
            [ContentTruncator::class, 'excerpt'],
            Plugin::$callbacks['Widget_Abstract_Contents']['excerptEx_9998']
        );
    }

    public function testRequestContextKeepsBackwardCompatibleContentPolicyDefaults(): void
    {
        $context = RequestContext::enter('/atom/tag/example/', ['cover']);

        try {
            self::assertFalse($context->contentTruncationEnabled());
            self::assertSame(100, $context->feedContentLength());
            self::assertSame('阅读全文', $context->feedReadMoreText());
        } finally {
            $context->leave();
        }
    }

    public function testRequestContextSnapshotsConfiguredContentPolicy(): void
    {
        $context = RequestContext::enter('/rss/', [], true, 180, '继续阅读');

        try {
            self::assertTrue($context->contentTruncationEnabled());
            self::assertSame(180, $context->feedContentLength());
            self::assertSame('继续阅读', $context->feedReadMoreText());
        } finally {
            $context->leave();
        }
    }
}
