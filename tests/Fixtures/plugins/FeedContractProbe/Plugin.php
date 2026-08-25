<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedContractProbe;

use Typecho\Plugin as TypechoCorePlugin;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * CI-only probe for Typecho content and feed extension contracts.
 *
 * @package FeedContractProbe
 * @version 1.0.0
 * @since 1.3.0
 */
final class Plugin implements PluginInterface
{
    public static function activate(): void
    {
        TypechoCorePlugin::factory('index.php')->begin_1 = [
            self::class,
            'poisonResponseHeaders',
        ];
        TypechoCorePlugin::factory('index.php')->end_9999 = [
            self::class,
            'recordFeedFullText',
        ];
        TypechoCorePlugin::factory(\Widget\Base\Contents::class)->contentEx_100 = [
            self::class,
            'content',
        ];
        TypechoCorePlugin::factory(\Widget\Base\Contents::class)->excerptEx_100 = [
            self::class,
            'excerpt',
        ];
        TypechoCorePlugin::factory(\Widget\Feed::class)->feedItem = [
            self::class,
            'feedItem',
        ];
        TypechoCorePlugin::factory(\Widget\Feed::class)->commentFeedItem = [
            self::class,
            'commentFeedItem',
        ];
    }

    public static function deactivate(): void
    {
    }

    public static function config(Form $form): void
    {
    }

    public static function personalConfig(Form $form): void
    {
    }

    public static function poisonResponseHeaders(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if ('/feed/' !== parse_url((string) $requestUri, PHP_URL_PATH)) {
            return;
        }

        $headers = [
            'Allow' => 'DELETE',
            'Cache-Control' => 'public, max-age=86400',
            'Content-Length' => '999999',
            'Content-Type' => 'text/plain; charset=us-ascii',
            'ETag' => '"stale-probe-etag"',
            'Expires' => 'Thu, 01 Jan 2099 00:00:00 GMT',
            'Pragma' => 'public',
            'Last-Modified' => 'Thu, 01 Jan 1970 00:00:00 GMT',
            'Vary' => 'Accept-Encoding',
        ];

        $response = \Typecho\Response::getInstance();
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
            if (!headers_sent()) {
                header($name . ': ' . $value);
            }
        }
    }

    public static function recordFeedFullText(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url((string) $requestUri, PHP_URL_PATH);
        if (!is_string($path) || 0 !== strpos($path, '/feed')) {
            return;
        }

        self::record('feedFullText:' . (int) (bool) \Widget\Options::alloc()->feedFullText);
    }

    /** @param mixed $content @param mixed $widget @param mixed $lastResult */
    public static function content($content, $widget, $lastResult = null): string
    {
        $cid = (int) $widget->cid;
        self::record('content:' . $cid);
        $result = null !== $lastResult ? (string) $lastResult : (string) $content;

        return $result . '<p>FE-CONTENT-HOOK-' . $cid . '</p>';
    }

    /** @param mixed $content @param mixed $widget @param mixed $lastResult */
    public static function excerpt($content, $widget, $lastResult = null): string
    {
        $cid = (int) $widget->cid;
        self::record('excerpt:' . $cid);
        $result = null !== $lastResult ? (string) $lastResult : (string) $content;

        return $result . ' FE-EXCERPT-HOOK-' . $cid;
    }

    /** @param mixed $feedType @param mixed $archive */
    public static function feedItem($feedType, $archive): string
    {
        $cid = (int) $archive->cid;
        self::record('feedItem:' . $cid);
        self::record(
            'feedItemFeedFullText:' . $cid . ':'
            . (int) (bool) \Widget\Options::alloc()->feedFullText
        );

        return '<probe:feed-item xmlns:probe="urn:feed-enhancer:ci:probe">'
            . 'FE-FEED-ITEM-SUFFIX-' . $cid
            . '</probe:feed-item>';
    }

    /** @param mixed $feedType @param mixed $comment */
    public static function commentFeedItem($feedType, $comment): string
    {
        $coid = (int) $comment->coid;
        self::record('commentFeedItem:' . $coid);
        self::record(
            'commentFeedItemFeedFullText:' . $coid . ':'
            . (int) (bool) \Widget\Options::alloc()->feedFullText
        );

        return '<probe:comment-item xmlns:probe="urn:feed-enhancer:ci:probe">'
            . 'FE-COMMENT-ITEM-SUFFIX-' . $coid
            . '</probe:comment-item>';
    }

    private static function record(string $event): void
    {
        $logFile = getenv('FE_PROBE_LOG');
        if (!is_string($logFile) || '' === $logFile) {
            return;
        }

        file_put_contents($logFile, $event . "\n", FILE_APPEND | LOCK_EX);
    }
}
