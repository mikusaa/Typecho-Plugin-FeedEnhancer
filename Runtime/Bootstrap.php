<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Runtime;

use Typecho\Plugin;
use Typecho\Widget;
use TypechoPlugin\FeedEnhancer\Feed\FeedProxy;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Registers request-local aliases and transparent Typecho hooks.
 */
final class Bootstrap
{
    private const CONTENTS_PLUGIN_HANDLE = 'Widget_Abstract_Contents';

    public static function register(): void
    {
        Plugin::factory('index.php')->begin = [self::class, 'boot'];
        Plugin::factory(\Widget\Archive::class)->handleInit_9999 = [
            VisibilityGuard::class,
            'narrowArchive',
        ];
        Plugin::factory(self::CONTENTS_PLUGIN_HANDLE)->contentEx_9999 = [
            ContentMetadataCollector::class,
            'observe',
        ];
        Plugin::factory(self::CONTENTS_PLUGIN_HANDLE)->excerptEx_9999 = [
            ContentMetadataCollector::class,
            'observe',
        ];
    }

    public static function boot(): void
    {
        Widget::alias(\Widget\Feed::class, FeedProxy::class);
        Widget::alias('\Widget\Feed', FeedProxy::class);
    }
}
