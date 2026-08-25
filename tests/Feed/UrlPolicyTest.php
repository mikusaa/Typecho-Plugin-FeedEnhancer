<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Feed;

use PHPUnit\Framework\TestCase;
use TypechoPlugin\FeedEnhancer\Feed\UrlPolicy;

final class UrlPolicyTest extends TestCase
{
    /**
     * @dataProvider dangerousUrlProvider
     */
    public function testDangerousReferencesAreRejected(string $candidate): void
    {
        self::assertNull((new UrlPolicy())->resolve($candidate, 'https://example.test/posts/entry'));
    }

    /** @return array<string,array{string}> */
    public function dangerousUrlProvider(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:image/png;base64,AAAA'],
            'embedded credentials' => ['https://user:secret@example.test/image.jpg'],
            'raw control character' => ["https://example.test/image.jpg\r\nX-Test: injected"],
            'leading control character' => ["\nhttps://example.test/image.jpg"],
            'trailing entity control character' => ['https://example.test/image.jpg&NewLine;'],
            'encoded control character' => ['https://example.test/image.jpg%0d%0aX-Test:injected'],
            'encoded backslash ambiguity' => ['https://example.test/image.jpg%5cignored'],
            'encoded host credential delimiter' => ['https://example.test%40evil.test/image.jpg'],
            'encoded host path delimiter' => ['https://example.test%2fevil.test/image.jpg'],
            'backslash ambiguity' => ['https:\\evil.test\\image.jpg'],
            'malformed port suffix' => ['https://example.test:80x/image.jpg'],
            'invalid bracketed host' => ['https://[not-an-ip]/image.jpg'],
            'unclosed bracketed host' => ['https://[::1/image.jpg'],
            'malformed percent escape' => ['https://example.test/image%zz.jpg'],
        ];
    }

    public function testRelativeReferenceUsesTheDocumentUrlAsItsBase(): void
    {
        $policy = new UrlPolicy();

        self::assertSame(
            'https://example.test:8443/blog/images/cover.jpg',
            $policy->resolve('../images/cover.jpg', 'https://example.test:8443/blog/posts/entry?preview=1')
        );
        self::assertSame(
            'https://example.test/assets/cover.jpg?width=1200&format=webp',
            $policy->resolve(
                '/assets/cover.jpg?width=1200&format=webp',
                'https://example.test/blog/posts/entry'
            )
        );
    }

    public function testEntityLikeTextIsInspectedButNotDecodedIntoAnotherUrl(): void
    {
        self::assertSame(
            'https://example.test/image.jpg?label=a&quest;b',
            (new UrlPolicy())->resolve(
                '/image.jpg?label=a&quest;b',
                'https://example.test/posts/entry'
            )
        );
    }

    public function testProtocolRelativeReferenceInheritsOnlyTheSafeBaseScheme(): void
    {
        self::assertSame(
            'https://cdn.example.test/image.jpg',
            (new UrlPolicy())->resolve('//cdn.example.test/image.jpg', 'https://example.test/posts/entry')
        );
    }

    public function testInvalidBaseCannotAuthorizeARelativeReference(): void
    {
        self::assertNull((new UrlPolicy())->resolve('/image.jpg', 'javascript://example.test/entry'));
    }

    public function testDotSegmentResolutionPreservesEmptyPathSegments(): void
    {
        self::assertSame(
            'https://example.test/blog/assets//cover.jpg',
            (new UrlPolicy())->resolve(
                '../assets//cover.jpg',
                'https://example.test/blog/posts/entry'
            )
        );
    }

    public function testUnicodeUrlComponentsSurviveParsing(): void
    {
        $policy = new UrlPolicy();

        self::assertSame(
            'https://example.test/blog/图片/封面.jpg?label=中文#原图',
            $policy->resolve(
                '../图片/封面.jpg?label=中文#原图',
                'https://example.test/blog/posts/文章'
            )
        );
        self::assertTrue($policy->isSafeAbsolute('https://example.test/文章/一/'));
    }
}
