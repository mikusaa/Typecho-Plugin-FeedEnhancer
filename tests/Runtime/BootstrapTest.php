<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Typecho\Plugin;
use TypechoPlugin\FeedEnhancer\Runtime\Bootstrap;

require_once dirname(__DIR__) . '/Stubs/Typecho/PluginFactoryStub.php';
require_once dirname(__DIR__) . '/Stubs/Typecho/Plugin.php';

final class BootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        Plugin::$registrations = [];
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
    }
}
