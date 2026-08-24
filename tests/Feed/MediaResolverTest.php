<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Feed;

use PHPUnit\Framework\TestCase;
use TypechoPlugin\FeedEnhancer\Feed\MediaResolver;

final class MediaResolverTest extends TestCase
{
    private const BASE_URL = 'https://example.test/posts/entry';

    public function testConfiguredFieldOrderWinsOverHtmlAndAttachments(): void
    {
        $resolver = new MediaResolver(['cover', 'banner']);
        $item = [
            'fields' => [
                'banner' => '/images/banner.jpg',
                'cover' => '/images/cover.jpg',
            ],
            'attachments' => [
                ['url' => '/uploads/attachment.jpg', 'mime' => 'image/jpeg', 'created' => 1],
            ],
        ];

        self::assertSame(
            'https://example.test/images/cover.jpg',
            $resolver->resolve($item, '<img src="/content/image.jpg">', self::BASE_URL)
        );
    }

    public function testHtmlImageWinsOverAttachmentsWhenNoFieldResolves(): void
    {
        $resolver = new MediaResolver(['cover']);
        $item = [
            'fields' => ['cover' => 'javascript:alert(1)'],
            'attachments' => [
                ['url' => '/uploads/attachment.jpg', 'mime' => 'image/jpeg', 'created' => 1],
            ],
        ];

        self::assertSame(
            'https://example.test/content/image.jpg',
            $resolver->resolve($item, '<p>Intro</p><img src="/content/image.jpg">', self::BASE_URL)
        );
    }

    public function testEarliestValidImageAttachmentIsTheFinalFallback(): void
    {
        $resolver = new MediaResolver([]);
        $item = [
            'attachments' => [
                ['url' => '/uploads/later.jpg', 'mime' => 'image/jpeg', 'created' => 20],
                ['url' => '/uploads/not-image.txt', 'mime' => 'text/plain', 'created' => 1],
                ['url' => '/uploads/earlier.webp', 'mime' => 'image/webp', 'created' => 10],
            ],
        ];

        self::assertSame(
            'https://example.test/uploads/earlier.webp',
            $resolver->resolve($item, '<p>No image</p>', self::BASE_URL)
        );
    }

    public function testHtmlParserPreservesUtf8ImagePath(): void
    {
        $resolver = new MediaResolver([]);

        self::assertSame(
            'https://example.test/图片.jpg',
            $resolver->resolve([], '<img src="/图片.jpg">', self::BASE_URL)
        );
    }

    public function testDomDecodedImageAttributeIsNotDecodedTwice(): void
    {
        $resolver = new MediaResolver([]);

        self::assertSame(
            'https://example.test/image.jpg?label=a&quest;b',
            $resolver->resolve(
                [],
                '<img src="/image.jpg?label=a&amp;quest;b">',
                self::BASE_URL
            )
        );
    }

    public function testAttachmentIdBreaksEqualCreationTimeTies(): void
    {
        $resolver = new MediaResolver([]);
        $item = [
            'attachments' => [
                ['id' => 9, 'url' => '/uploads/nine.jpg', 'mime' => 'image/jpeg', 'created' => 10],
                ['id' => 4, 'url' => '/uploads/four.jpg', 'mime' => 'image/jpeg', 'created' => 10],
            ],
        ];

        self::assertSame(
            'https://example.test/uploads/four.jpg',
            $resolver->resolve($item, '', self::BASE_URL)
        );
    }
}
