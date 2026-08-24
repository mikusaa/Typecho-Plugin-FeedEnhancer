<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Http;

use PHPUnit\Framework\TestCase;
use TypechoPlugin\FeedEnhancer\Http\ConditionalResponse;

final class ConditionalResponseTest extends TestCase
{
    private const CONTENT_TYPE = 'application/rss+xml';
    private const REPRESENTATION = '<rss>你好</rss>';

    public function testGetReturnsDeterministicStrongEtagAndByteLength(): void
    {
        $response = $this->prepare('GET');

        self::assertSame(200, $response['status']);
        self::assertSame(self::REPRESENTATION, $response['body']);
        self::assertSame($this->etag(), $response['headers']['ETag']);
        self::assertSame((string) strlen(self::REPRESENTATION), $response['headers']['Content-Length']);
        self::assertSame(self::CONTENT_TYPE . '; charset=UTF-8', $response['headers']['Content-Type']);
    }

    public function testMatchingTagLaterInAListReturnsNotModified(): void
    {
        $response = $this->prepare('GET', '"other", ' . $this->etag() . ', "unused"');

        $this->assertNotModified($response);
    }

    public function testWeakMatchingTagReturnsNotModified(): void
    {
        $response = $this->prepare('GET', 'W/' . $this->etag());

        $this->assertNotModified($response);
    }

    public function testWildcardReturnsNotModified(): void
    {
        $response = $this->prepare('GET', '*');

        $this->assertNotModified($response);
    }

    /** @dataProvider malformedValidatorProvider */
    public function testMalformedValidatorDoesNotReturnNotModified(string $validator): void
    {
        $response = $this->prepare('GET', str_replace('{etag}', $this->etag(), $validator));

        self::assertSame(200, $response['status']);
        self::assertSame(self::REPRESENTATION, $response['body']);
    }

    /** @return array<string,array{string}> */
    public function malformedValidatorProvider(): array
    {
        return [
            'suffix after matching tag' => ['{etag}garbage'],
            'suffix after wildcard' => ['*garbage'],
            'trailing comma' => ['{etag},'],
            'wildcard in a list' => ['{etag}, *'],
            'lowercase weak prefix' => ['w/{etag}'],
        ];
    }

    public function testHeadReturnsHeadersForTheGetRepresentationWithoutABody(): void
    {
        $response = $this->prepare('HEAD');

        self::assertSame(200, $response['status']);
        self::assertSame('', $response['body']);
        self::assertSame($this->etag(), $response['headers']['ETag']);
        self::assertSame((string) strlen(self::REPRESENTATION), $response['headers']['Content-Length']);
    }

    public function testConditionalHeadCanReturnNotModified(): void
    {
        $response = $this->prepare('HEAD', $this->etag());

        $this->assertNotModified($response);
    }

    public function testUnsupportedMethodReturnsMethodNotAllowedWithoutARepresentation(): void
    {
        $response = $this->prepare('POST', $this->etag());

        self::assertSame(405, $response['status']);
        self::assertSame('', $response['body']);
        self::assertSame('GET, HEAD', $response['headers']['Allow']);
        self::assertSame('0', $response['headers']['Content-Length']);
        self::assertArrayNotHasKey('ETag', $response['headers']);
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function prepare(string $method, ?string $ifNoneMatch = null): array
    {
        return (new ConditionalResponse())->prepare(
            $method,
            self::REPRESENTATION,
            $ifNoneMatch,
            false,
            self::CONTENT_TYPE
        );
    }

    private function etag(): string
    {
        return '"sha256-' . hash('sha256', self::REPRESENTATION) . '"';
    }

    /** @param array{status:int,headers:array<string,string>,body:string} $response */
    private function assertNotModified(array $response): void
    {
        self::assertSame(304, $response['status']);
        self::assertSame('', $response['body']);
        self::assertSame($this->etag(), $response['headers']['ETag']);
        self::assertArrayNotHasKey('Content-Length', $response['headers']);
    }
}
