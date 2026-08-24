<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Feed;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use TypechoPlugin\FeedEnhancer\Feed\MediaResolver;
use TypechoPlugin\FeedEnhancer\Feed\XmlPipeline;

final class XmlPipelineTest extends TestCase
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const MEDIA_NS = 'http://search.yahoo.com/mrss/';
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const RSS1_NS = 'http://purl.org/rss/1.0/';
    private const VENDOR_NS = 'urn:feed-enhancer:test:vendor';

    public function testAtomArticleTimeChangesWithoutChangingPublishedOrUnmatchedComment(): void
    {
        $modified = strtotime('2025-03-04T05:06:07+00:00');
        $output = $this->pipeline()->enhance(
            $this->fixture('atom-feed.xml'),
            [[
                'permalink' => 'https://example.test/posts/article-one/',
                'created' => strtotime('2024-01-02T00:00:00+00:00'),
                'modified' => $modified,
            ]],
            false
        );

        $xpath = $this->xpath($output);
        self::assertSame(
            $modified,
            strtotime($this->value($xpath, '/a:feed/a:entry[1]/a:updated'))
        );
        self::assertSame(
            '2024-01-02T00:00:00+00:00',
            $this->value($xpath, '/a:feed/a:entry[1]/a:published')
        );
        self::assertSame(
            '2030-05-06T07:08:09+00:00',
            $this->value($xpath, '/a:feed/a:entry[2]/a:updated')
        );
        self::assertSame(
            '2030-05-06T07:08:09+00:00',
            $this->value($xpath, '/a:feed/a:updated')
        );
    }

    public function testAtomFeedUpdatedUsesArticleWhenItIsTheNewestEntry(): void
    {
        $modified = strtotime('2035-06-07T08:09:10+00:00');
        $output = $this->pipeline()->enhance(
            $this->fixture('atom-feed.xml'),
            [[
                'permalink' => 'https://example.test/posts/article-one/',
                'created' => strtotime('2024-01-02T00:00:00+00:00'),
                'modified' => $modified,
            ]],
            false
        );

        $xpath = $this->xpath($output);
        self::assertSame($modified, strtotime($this->value($xpath, '/a:feed/a:updated')));
        self::assertSame(
            '2030-05-06T07:08:09+00:00',
            $this->value($xpath, '/a:feed/a:entry[2]/a:updated')
        );
    }

    /** @dataProvider unsafeXmlProvider */
    public function testUnsafeOrMalformedXmlFallsBackToTheOriginalBytes(string $fixture): void
    {
        $input = $this->fixture($fixture);
        $output = $this->pipeline()->enhance(
            $input,
            [['permalink' => 'https://example.test/posts/article-one/']],
            true,
            'https://example.test/feed/?feed-enhancer-stylesheet=1'
        );

        self::assertSame($input, $output);
    }

    /** @return array<string,array{string}> */
    public function unsafeXmlProvider(): array
    {
        return [
            'DOCTYPE' => ['doctype-feed.xml'],
            'malformed XML' => ['malformed-feed.xml'],
        ];
    }

    public function testEnhancementPreservesUnknownNamespaceAndSuffix(): void
    {
        $output = $this->pipeline()->enhance(
            $this->fixture('rss2-feed.xml'),
            [],
            false,
            'https://example.test/feed/?feed-enhancer-stylesheet=1&v=1.0.0'
        );
        $xpath = $this->xpath($output);
        $suffix = $xpath->query('/rss/channel/item[1]/vendor:suffix')->item(0);

        self::assertInstanceOf(DOMElement::class, $suffix);
        self::assertSame('preserve me', trim($suffix->textContent));
        self::assertSame('rss2', $suffix->getAttributeNS(self::VENDOR_NS, 'key'));
    }

    public function testDoctypeTextInsideCdataDoesNotDisableEnhancements(): void
    {
        $input = str_replace(
            '<description>Fixture</description>',
            '<description><![CDATA[Literal <!DOCTYPE html> text]]></description>',
            $this->fixture('rss2-feed.xml')
        );
        $output = $this->pipeline()->enhance(
            $input,
            [],
            false,
            'https://example.test/feed/?feed-enhancer-stylesheet=1'
        );

        self::assertSame(1, $this->stylesheetInstructionCount($this->document($output)));
        self::assertStringContainsString('Literal <!DOCTYPE html> text', $output);
    }

    public function testUtf16DoctypeFallsBackToOriginalBytes(): void
    {
        $ascii = str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $this->fixture('doctype-feed.xml'));
        $input = "\xFF\xFE";
        for ($offset = 0, $length = strlen($ascii); $offset < $length; ++$offset) {
            $input .= $ascii[$offset] . "\x00";
        }

        self::assertSame(
            $input,
            $this->pipeline()->enhance(
                $input,
                [],
                true,
                'https://example.test/feed/?feed-enhancer-stylesheet=1'
            )
        );
    }

    public function testRss2AddsMediaAndDoesNotDuplicateAnExistingThumbnail(): void
    {
        $output = $this->pipeline(['cover'])->enhance(
            $this->fixture('rss2-feed.xml'),
            [
                [
                    'permalink' => 'https://example.test/posts/rss2-article/',
                    'fields' => ['cover' => '/images/rss2-cover.jpg'],
                ],
                [
                    'permalink' => 'https://example.test/posts/rss2-existing/',
                    'fields' => ['cover' => '/images/should-not-be-added.jpg'],
                ],
            ]
        );
        $xpath = $this->xpath($output);
        $article = '/rss/channel/item[link="https://example.test/posts/rss2-article/"]';
        $existing = '/rss/channel/item[link="https://example.test/posts/rss2-existing/"]';

        self::assertSame(1, $this->nodeCount($xpath, $article . '/media:content'));
        self::assertSame(1, $this->nodeCount($xpath, $article . '/media:thumbnail'));
        self::assertSame(
            'https://example.test/images/rss2-cover.jpg',
            $this->value($xpath, $article . '/media:content/@url')
        );
        self::assertSame(0, $this->nodeCount($xpath, $existing . '/media:content'));
        self::assertSame(1, $this->nodeCount($xpath, $existing . '/media:thumbnail'));
    }

    public function testRss1AddsMediaAndDoesNotDuplicateAnExistingContentElement(): void
    {
        $output = $this->pipeline([])->enhance(
            $this->fixture('rss1-feed.xml'),
            [
                [
                    'permalink' => 'https://example.test/posts/rss1-article/',
                    'attachments' => [[
                        'url' => '/uploads/rss1-attachment.webp',
                        'mime' => 'image/webp',
                        'created' => 10,
                    ]],
                ],
                [
                    'permalink' => 'https://example.test/posts/rss1-existing/',
                    'attachments' => [[
                        'url' => '/uploads/should-not-be-added.jpg',
                        'mime' => 'image/jpeg',
                        'created' => 1,
                    ]],
                ],
            ]
        );
        $xpath = $this->xpath($output);
        $article = '/rdf:RDF/r:item[r:link="https://example.test/posts/rss1-article/"]';
        $existing = '/rdf:RDF/r:item[r:link="https://example.test/posts/rss1-existing/"]';

        self::assertSame(1, $this->nodeCount($xpath, $article . '/media:content'));
        self::assertSame(1, $this->nodeCount($xpath, $article . '/media:thumbnail'));
        self::assertSame(
            'https://example.test/uploads/rss1-attachment.webp',
            $this->value($xpath, $article . '/media:thumbnail/@url')
        );
        self::assertSame(1, $this->nodeCount($xpath, $existing . '/media:content'));
        self::assertSame(0, $this->nodeCount($xpath, $existing . '/media:thumbnail'));
    }

    public function testExistingRelativeMediaUrlIsResolvedAndKeptWithoutDuplication(): void
    {
        $input = str_replace(
            'https://cdn.example.test/existing-thumb.jpg',
            '../shared/thumb.jpg',
            $this->fixture('rss2-feed.xml')
        );
        $output = $this->pipeline()->enhance($input, [[
            'permalink' => 'https://example.test/posts/rss2-existing/',
        ]]);
        $xpath = $this->xpath($output);
        $existing = '/rss/channel/item[link="https://example.test/posts/rss2-existing/"]';

        self::assertSame(0, $this->nodeCount($xpath, $existing . '/media:content'));
        self::assertSame(1, $this->nodeCount($xpath, $existing . '/media:thumbnail'));
        self::assertSame(
            'https://example.test/posts/shared/thumb.jpg',
            $this->value($xpath, $existing . '/media:thumbnail/@url')
        );
    }

    public function testUnsafeExistingMediaIsRemovedBeforeSafeFallbackIsAdded(): void
    {
        $input = str_replace(
            'https://cdn.example.test/existing-thumb.jpg',
            'javascript:alert(1)',
            $this->fixture('rss2-feed.xml')
        );
        $output = $this->pipeline(['cover'])->enhance($input, [[
            'permalink' => 'https://example.test/posts/rss2-existing/',
            'fields' => ['cover' => '/images/safe-fallback.jpg'],
        ]]);
        $xpath = $this->xpath($output);
        $existing = '/rss/channel/item[link="https://example.test/posts/rss2-existing/"]';

        self::assertStringNotContainsString('javascript:', $output);
        self::assertSame(1, $this->nodeCount($xpath, $existing . '/media:content'));
        self::assertSame(1, $this->nodeCount($xpath, $existing . '/media:thumbnail'));
        self::assertSame(
            'https://example.test/images/safe-fallback.jpg',
            $this->value($xpath, $existing . '/media:thumbnail/@url')
        );
    }

    public function testAtomAddsMediaOnlyToThePermalinkMatchedArticle(): void
    {
        $output = $this->pipeline([])->enhance(
            $this->fixture('atom-feed.xml'),
            [[
                'permalink' => 'https://example.test/posts/article-one/',
                'created' => strtotime('2024-01-02T00:00:00+00:00'),
                'modified' => strtotime('2024-01-02T00:00:00+00:00'),
            ]]
        );
        $xpath = $this->xpath($output);

        self::assertSame(1, $this->nodeCount($xpath, '/a:feed/a:entry[1]/media:content'));
        self::assertSame(1, $this->nodeCount($xpath, '/a:feed/a:entry[1]/media:thumbnail'));
        self::assertSame(
            'https://example.test/posts/images/from-content.jpg',
            $this->value($xpath, '/a:feed/a:entry[1]/media:content/@url')
        );
        self::assertSame(0, $this->nodeCount($xpath, '/a:feed/a:entry[2]/media:content'));
        self::assertSame(0, $this->nodeCount($xpath, '/a:feed/a:entry[2]/media:thumbnail'));
    }

    public function testRss2StylesheetInstructionIsAddedOnceAndAtomIgnoresIt(): void
    {
        $url = 'https://example.test/feed/?feed-enhancer-stylesheet=1&v=1.0.0';
        $once = $this->pipeline()->enhance($this->fixture('rss2-feed.xml'), [], false, $url);
        $twice = $this->pipeline()->enhance($once, [], false, $url);
        $rssDocument = $this->document($twice);

        self::assertSame(1, $this->stylesheetInstructionCount($rssDocument));
        self::assertStringContainsString('href="' . $url . '"', html_entity_decode($twice, ENT_QUOTES | ENT_XML1));

        $atom = $this->pipeline()->enhance($this->fixture('atom-feed.xml'), [], false, $url);
        self::assertSame(0, $this->stylesheetInstructionCount($this->document($atom)));
    }

    /** @param string[] $fieldNames */
    private function pipeline(array $fieldNames = ['banner', 'cover', 'thumbnail']): XmlPipeline
    {
        return new XmlPipeline(new MediaResolver($fieldNames));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixtures/' . $name);
        self::assertIsString($contents, 'Fixture could not be read: ' . $name);
        return $contents;
    }

    private function document(string $xml): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        self::assertTrue($document->loadXML($xml, LIBXML_NONET));
        return $document;
    }

    private function xpath(string $xml): DOMXPath
    {
        $xpath = new DOMXPath($this->document($xml));
        $xpath->registerNamespace('a', self::ATOM_NS);
        $xpath->registerNamespace('media', self::MEDIA_NS);
        $xpath->registerNamespace('rdf', self::RDF_NS);
        $xpath->registerNamespace('r', self::RSS1_NS);
        $xpath->registerNamespace('vendor', self::VENDOR_NS);
        return $xpath;
    }

    private function value(DOMXPath $xpath, string $expression): string
    {
        $nodes = $xpath->query($expression);
        self::assertNotFalse($nodes, 'Invalid XPath: ' . $expression);
        self::assertSame(1, $nodes->length, 'Expected one XPath result: ' . $expression);
        $node = $nodes->item(0);
        self::assertNotNull($node);
        return trim($node->nodeValue ?? '');
    }

    private function nodeCount(DOMXPath $xpath, string $expression): int
    {
        $nodes = $xpath->query($expression);
        self::assertNotFalse($nodes, 'Invalid XPath: ' . $expression);
        return $nodes->length;
    }

    private function stylesheetInstructionCount(DOMDocument $document): int
    {
        $count = 0;
        foreach ($document->childNodes as $node) {
            if (XML_PI_NODE === $node->nodeType && 'xml-stylesheet' === $node->nodeName) {
                ++$count;
            }
        }

        return $count;
    }
}
