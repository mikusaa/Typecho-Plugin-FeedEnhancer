<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Feed;

use PHPUnit\Framework\TestCase;
use TypechoPlugin\FeedEnhancer\Feed\ContentTruncator;
use TypechoPlugin\FeedEnhancer\Runtime\RequestContext;

final class ContentTruncatorTest extends TestCase
{
    protected function tearDown(): void
    {
        $context = RequestContext::current();
        if (null !== $context) {
            $context->leave();
        }
    }

    public function testDisabledOrMissingContextPreservesTheFilterChainResult(): void
    {
        $widget = $this->widget();
        self::assertSame(
            '<p>previous</p>',
            ContentTruncator::content('<p>source</p>', $widget, '<p>previous</p>')
        );

        $this->enter(false);
        self::assertSame(
            '<p>previous</p>',
            ContentTruncator::excerpt('<p>source</p>', $widget, '<p>previous</p>')
        );
    }

    public function testOnlyAggregateArticleFeedsAreTruncated(): void
    {
        $source = '<p>first</p><p>second</p>';
        $this->enter(true);

        self::assertSame($source, ContentTruncator::content($source, $this->widget(false)));
        self::assertSame($source, ContentTruncator::content($source, $this->widget(true, true)));

        RequestContext::current()->leave();
        $this->enter(true, 300, 'Read', '/comments/');
        self::assertSame($source, ContentTruncator::content($source, $this->widget()));
    }

    public function testContentAddsOneEscapedCallToActionAndExcerptContainsOnlyTheTeaser(): void
    {
        $this->enter(true, 300, 'Read & "continue"');
        $widget = $this->widget(
            true,
            false,
            'https://example.test/posts/one?a=1&b=2'
        );
        $source = '<p>First <strong>paragraph</strong></p><p>Second paragraph</p>';

        $content = ContentTruncator::content($source, $widget);
        self::assertSame(
            '<p>First paragraph Second paragraph</p>'
                . '<p class="more"><a href="https://example.test/posts/one?a=1&amp;b=2">'
                . 'Read &amp; &quot;continue&quot;</a></p>',
            $content
        );
        self::assertSame(
            '<p>First paragraph Second paragraph</p>',
            ContentTruncator::excerpt($content, $widget)
        );
    }

    public function testEnabledFilteringUsesThePreviousFiltersFinalResult(): void
    {
        $this->enter(true);
        $output = ContentTruncator::content(
            '<p>Original</p>',
            $this->widget(),
            '<p>Filtered result</p><p>Discarded</p>'
        );

        self::assertStringStartsWith('<p>Filtered result Discarded</p>', $output);
        self::assertStringNotContainsString('Original', $output);
        self::assertStringContainsString('Discarded', $output);
    }

    public function testCoreMoreMarkerDoesNotLeakTheRemainderOrSurvive(): void
    {
        $this->enter(true);
        $output = ContentTruncator::content(
            '<div><p>Before marker</p><!--more--><p>After marker</p></div><p>Outside</p>',
            $this->widget()
        );

        self::assertStringStartsWith('<p>Before marker</p>', $output);
        self::assertStringNotContainsString('After marker', $output);
        self::assertStringNotContainsString('<!--more-->', $output);
        self::assertSame(1, substr_count($output, 'class="more"'));
    }

    public function testRepeatedContentFilteringIsIdempotent(): void
    {
        $this->enter(true);
        $widget = $this->widget();

        $once = ContentTruncator::content('<p>Lead</p><p>Rest</p>', $widget);
        $twice = ContentTruncator::content($once, $widget);

        self::assertSame($once, $twice);
        self::assertSame(1, substr_count($twice, 'class="more"'));
    }

    public function testAnExistingMoreParagraphIsReplacedInsteadOfBecomingTheTeaser(): void
    {
        $this->enter(true, 300, 'New label');
        $widget = $this->widget();
        $output = ContentTruncator::content(
            '<p class="intro more legacy">Old label</p><p>Actual lead</p><p>Remainder</p>',
            $widget
        );

        self::assertSame(
            '<p>Actual lead Remainder</p>'
                . '<p class="more"><a href="https://example.test/posts/example">New label</a></p>',
            $output
        );
    }

    public function testExistingMoreClassIsIgnoredOnAnyElement(): void
    {
        $this->enter(true);
        $output = ContentTruncator::content(
            '<div class="more"><p>Old call to action</p></div>'
                . '<p>Actual lead <a class="more" href="/old">Old link</a></p>',
            $this->widget()
        );

        self::assertStringStartsWith('<p>Actual lead</p>', $output);
        self::assertStringNotContainsString('Old call to action', $output);
        self::assertStringNotContainsString('Old link', $output);
        self::assertSame(1, substr_count($output, 'class="more"'));
    }

    public function testLeadSelectionSkipsExcludedAndEmptyBlocks(): void
    {
        $this->enter(true);
        $source = '<h2>Heading</h2>'
            . '<p>&nbsp;</p>'
            . '<figure><img src="/cover.jpg" alt="Image text"><figcaption>Caption</figcaption></figure>'
            . '<pre>Preformatted</pre><code>Code</code><script>Script</script><style>Style</style>'
            . '<template><p>Template</p></template><iframe>Frame</iframe><form><p>Form</p></form>'
            . "<p title=\"1 > 0\"> First&nbsp; line\n\t<strong>bold &amp; safe</strong> text </p>"
            . '<p>Secret remainder</p>';

        $output = ContentTruncator::content($source, $this->widget());

        self::assertStringStartsWith(
            '<p>First line bold &amp; safe text Secret remainder</p>',
            $output
        );
        foreach (
            [
                'Heading', 'Image text', 'Caption', 'Preformatted', 'Code', 'Script', 'Style',
                'Template', 'Frame', 'Form', '<strong>',
            ] as $unexpected
        ) {
            self::assertStringNotContainsString($unexpected, $output);
        }
    }

    public function testRawTextElementsDoNotCreateFalseParagraphBoundaries(): void
    {
        $this->enter(true);
        $output = ContentTruncator::content(
            '<p>Lead<script>const sample = "<p>";</script> ending</p><p>Secret</p>',
            $this->widget()
        );

        self::assertStringStartsWith('<p>Lead ending Secret</p>', $output);
    }

    public function testHiddenAndClosedDetailsAreSkippedButOpenDetailsRemainVisible(): void
    {
        $this->enter(true);
        $widget = $this->widget();

        $hidden = ContentTruncator::content(
            '<p hidden>Hidden</p><details><summary>Summary</summary><p>Folded</p></details><p>Public</p>',
            $widget
        );
        self::assertStringStartsWith('<p>Public</p>', $hidden);
        self::assertStringNotContainsString('Hidden', $hidden);
        self::assertStringNotContainsString('Folded', $hidden);

        $open = ContentTruncator::content(
            '<details open><summary>Summary</summary><p>Expanded</p></details><p>Later</p>',
            $widget
        );
        self::assertStringStartsWith('<p>Expanded Later</p>', $open);
    }

    /** @dataProvider blockProvider */
    public function testParagraphQuoteAndListsAreValidLeadBlocks(string $html, string $expected): void
    {
        $this->enter(true);
        $widget = $this->widget();
        $content = ContentTruncator::content($html, $widget);

        self::assertStringStartsWith($expected, $content);
        self::assertSame($expected, ContentTruncator::excerpt($content, $widget));
    }

    /** @return array<string,array{string,string}> */
    public function blockProvider(): array
    {
        return [
            'paragraph' => ['<p>Paragraph</p><p>Later</p>', '<p>Paragraph Later</p>'],
            'quote' => [
                '<blockquote> Quoted <em>text</em> </blockquote><p>Later</p>',
                '<p>Quoted text Later</p>',
            ],
            'unordered list' => [
                '<ul><li>First <strong>item</strong></li><li>Second&nbsp;item &amp; more</li>'
                    . '<li><img src="/empty.jpg"></li></ul><p>Later</p>',
                '<p>First item' . "\xEF\xBC\x9B" . 'Second item &amp; more Later</p>',
            ],
            'ordered list' => [
                '<ol><li>One</li><li>Two</li></ol><p>Later</p>',
                '<p>One' . "\xEF\xBC\x9B" . 'Two Later</p>',
            ],
        ];
    }

    public function testMultipleBlocksShareOneConfiguredCharacterBudget(): void
    {
        $this->enter(true, 20);
        $output = ContentTruncator::content(
            '<p>abc</p><figure><img src="/cover.jpg" alt="Cover"></figure>'
                . '<h2>Heading</h2><p>defghijklmnopqrstuvwxyz</p><p>tail</p>',
            $this->widget()
        );

        self::assertStringStartsWith('<p>abc defghijklmnop...</p>', $output);
        self::assertStringNotContainsString('Cover', $output);
        self::assertStringNotContainsString('Heading', $output);
        self::assertStringNotContainsString('tail', $output);
    }

    public function testAllEligibleBlocksAreJoinedWithoutEllipsisWhenTheyFit(): void
    {
        $this->enter(true, 100);
        $output = ContentTruncator::content(
            '<p>One</p><blockquote><p>Two</p></blockquote>'
                . '<ol><li>Three</li><li>Four</li></ol>',
            $this->widget()
        );

        self::assertStringStartsWith(
            '<p>One Two Three' . "\xEF\xBC\x9B" . 'Four</p>',
            $output
        );
        self::assertSame(1, substr_count($output, 'Two'));
        self::assertStringNotContainsString('...', $output);
    }

    public function testPlainTextAndMalformedHtmlUseVisibleTextFallback(): void
    {
        $this->enter(true);
        $widget = $this->widget();

        self::assertStringStartsWith(
            '<p>Plain fallback &amp; safe</p>',
            ContentTruncator::content('Plain&nbsp;fallback &amp; safe', $widget)
        );
        self::assertStringStartsWith(
            '<p>Unclosed still visible</p>',
            ContentTruncator::content('<p>Unclosed <strong>still visible', $widget)
        );
        self::assertStringStartsWith(
            '<p>Plain before marker</p>',
            ContentTruncator::content('Plain before marker<!--more-->Secret', $widget)
        );
    }

    public function testNestedListsKeepDocumentOrderWithoutRepeatingNestedTextInTheParent(): void
    {
        $this->enter(true);
        $content = ContentTruncator::content(
            '<ul><li>Outer before<ul><li>Inner</li></ul> outer after</li><li>Second</li></ul>',
            $this->widget()
        );
        $separator = "\xEF\xBC\x9B";

        self::assertStringStartsWith(
            '<p>Outer before outer after' . $separator . 'Inner' . $separator . 'Second</p>',
            $content
        );
        self::assertSame(1, substr_count($content, 'Inner'));
    }

    public function testImageOnlyContentProducesNoTeaser(): void
    {
        $this->enter(true);
        $widget = $this->widget();
        $source = '<h2>Heading</h2><figure><img src="/only.jpg" alt="Alternative"></figure>'
            . '<pre>Code</pre><script>Script</script>';
        $cta = '<p class="more"><a href="https://example.test/posts/example">Read</a></p>';

        self::assertSame($cta, ContentTruncator::content($source, $widget));
        self::assertSame('', ContentTruncator::excerpt($source, $widget));
    }

    public function testConfiguredLengthCountsUnicodeCharactersAndIncludesTheEllipsis(): void
    {
        $this->enter(true, 300);
        $widget = $this->widget();

        $exact = str_repeat("\xE7\x95\x8C", 300);
        self::assertStringStartsWith(
            '<p>' . $exact . '</p>',
            ContentTruncator::content('<p>' . $exact . '</p>', $widget)
        );

        $long = str_repeat("\xF0\x9F\x98\x80", 301);
        self::assertStringStartsWith(
            '<p>' . str_repeat("\xF0\x9F\x98\x80", 297) . '...</p>',
            ContentTruncator::content('<p>' . $long . '</p>', $widget)
        );
    }

    public function testConfiguredNonDefaultLengthControlsTheTeaser(): void
    {
        $this->enter(true, 8);

        self::assertStringStartsWith(
            '<p>abcde...</p>',
            ContentTruncator::content('<p>abcdefghij</p>', $this->widget())
        );
    }

    /** @dataProvider unsafeUrlProvider */
    public function testUnsafePermalinksDoNotProduceLinks(string $url): void
    {
        $this->enter(true);
        $output = ContentTruncator::content(
            '<p>Teaser</p>',
            $this->widget(true, false, $url)
        );

        self::assertSame('<p>Teaser</p>', $output);
    }

    public function testSafePermalinkIsEscapedInTheAttributeContext(): void
    {
        $this->enter(true);
        $url = 'https://example.test/read?a=1&b="two"<three>';
        $output = ContentTruncator::content('<p>Teaser</p>', $this->widget(true, false, $url));

        self::assertSame(
            '<p>Teaser</p><p class="more"><a href="https://example.test/read?'
                . 'a=1&amp;b=&quot;two&quot;&lt;three&gt;">Read</a></p>',
            $output
        );
    }

    public function testUnicodePermalinkProducesTheConfiguredCallToAction(): void
    {
        $this->enter(true, 300, '继续阅读');
        $output = ContentTruncator::content(
            '<p>Teaser</p>',
            $this->widget(true, false, 'https://example.test/文章/一/')
        );

        self::assertSame(
            '<p>Teaser</p><p class="more"><a href="https://example.test/文章/一/">'
                . '继续阅读</a></p>',
            $output
        );
    }

    /** @return array<string,array{string}> */
    public function unsafeUrlProvider(): array
    {
        return [
            'empty' => [''],
            'relative' => ['/posts/relative'],
            'ftp' => ['ftp://example.test/post'],
            'javascript' => ['javascript:alert(1)'],
            'userinfo' => ['https://user:password@example.test/post'],
            'control character' => ["https://example.test/post\nInjected"],
        ];
    }

    public function testEmptyOrNonStringContentProducesOnlyTheSafeCallToAction(): void
    {
        $this->enter(true);
        $widget = $this->widget();
        $cta = '<p class="more"><a href="https://example.test/posts/example">Read</a></p>';

        self::assertSame($cta, ContentTruncator::content('', $widget));
        self::assertSame($cta, ContentTruncator::content(null, $widget));
        self::assertSame('', ContentTruncator::excerpt('', $widget));
        self::assertSame('', ContentTruncator::excerpt(null, $widget));
    }

    private function enter(
        bool $enabled,
        int $length = 100,
        string $label = 'Read',
        string $path = '/'
    ): void {
        RequestContext::enter($path, [], $enabled, $length, $label);
    }

    private function widget(
        bool $feed = true,
        bool $single = false,
        string $permalink = 'https://example.test/posts/example'
    ): object {
        return new class ($feed, $single, $permalink) {
            /** @var object */
            public $parameter;
            public string $permalink;
            private bool $feed;
            private bool $single;

            public function __construct(bool $feed, bool $single, string $permalink)
            {
                $this->feed = $feed;
                $this->single = $single;
                $this->permalink = $permalink;
                $this->parameter = (object) ['type' => $single ? 'post' : 'index'];
            }

            public function is(string $type): bool
            {
                if ('feed' === $type) {
                    return $this->feed;
                }

                return 'single' === $type && $this->single;
            }
        };
    }
}
