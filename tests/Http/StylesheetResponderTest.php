<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Http;

use PHPUnit\Framework\TestCase;
use TypechoPlugin\FeedEnhancer\Http\StylesheetResponder;

final class StylesheetResponderTest extends TestCase
{
    private string $assetPath;

    protected function setUp(): void
    {
        $this->assetPath = dirname(__DIR__, 2) . '/assets/feed-preview.xsl';
    }

    public function testOnlyTheExplicitStylesheetFlagSelectsTheEndpoint(): void
    {
        $responder = new StylesheetResponder($this->assetPath);

        self::assertTrue($responder->isRequested(['feed-enhancer-stylesheet' => '1']));
        self::assertFalse($responder->isRequested(['feed-enhancer-stylesheet' => '0']));
        self::assertFalse($responder->isRequested([]));
        self::assertFalse($responder->isRequested(['feed-enhancer-stylesheet' => ['1']]));
    }

    public function testBuildUrlPreservesExistingQueryAndAddsVersion(): void
    {
        self::assertSame(
            'https://example.test/index.php/feed/?lang=zh-CN&feed-enhancer-stylesheet=1&v=1.0.0',
            StylesheetResponder::buildUrl(
                'https://example.test/index.php/feed/?lang=zh-CN',
                '1.0.0'
            )
        );
    }

    public function testGetHeadAndConditionalGetUseTheSameStylesheetRepresentation(): void
    {
        $responder = new StylesheetResponder($this->assetPath);
        $get = $responder->serve('GET', null, false);

        self::assertSame(200, $get['status']);
        self::assertStringContainsString('<xsl:stylesheet', $get['body']);
        self::assertSame('application/xslt+xml; charset=UTF-8', $get['headers']['Content-Type']);

        $head = $responder->serve('HEAD', null, false);
        self::assertSame(200, $head['status']);
        self::assertSame('', $head['body']);
        self::assertSame($get['headers']['ETag'], $head['headers']['ETag']);
        self::assertSame($get['headers']['Content-Length'], $head['headers']['Content-Length']);

        $notModified = $responder->serve('GET', $get['headers']['ETag'], false);
        self::assertSame(304, $notModified['status']);
        self::assertSame('', $notModified['body']);
    }

    public function testUnsupportedMethodIsRejectedBeforeReadingTheAsset(): void
    {
        $response = (new StylesheetResponder('/path/that/does/not/exist'))->serve(
            'POST',
            null,
            true
        );

        self::assertSame(405, $response['status']);
        self::assertSame('GET, HEAD', $response['headers']['Allow']);
        self::assertSame('private, no-store', $response['headers']['Cache-Control']);
        self::assertSame('', $response['body']);
    }
}
