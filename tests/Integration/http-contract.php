<?php

/**
 * Executes FeedEnhancer's HTTP integration contract against a Typecho site.
 */

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;

final class ContractResponse
{
    public int $status;

    /** @var array<string,string[]> */
    public array $headers;

    public string $body;

    /** @param array<string,string[]> $headers */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }
}

$baseUrl = getenv('FE_HTTP_ROOT');
if (!is_string($baseUrl) || '' === $baseUrl) {
    fwrite(STDERR, "FE_HTTP_ROOT is required.\n");
    exit(1);
}

$baseUrl = rtrim($baseUrl, '/');
$mode = $argv[1] ?? 'full';
$fixtureStateFile = getenv('FE_FIXTURE_STATE');
$probeLog = getenv('FE_PROBE_LOG');
$loginCookie = null;
$fixtureState = [];

if (is_string($fixtureStateFile) && is_file($fixtureStateFile)) {
    $decodedState = json_decode((string) file_get_contents($fixtureStateFile), true);
    if (is_array($decodedState)) {
        $fixtureState = $decodedState;
    }
    if (is_string($fixtureState['cookieHeader'] ?? null)) {
        $loginCookie = $fixtureState['cookieHeader'];
    }
}

function contractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param string[] $headers */
function contractRequest(string $method, string $url, array $headers = []): ContractResponse
{
    $handle = curl_init($url);
    if (false === $handle) {
        throw new RuntimeException('Unable to initialize cURL.');
    }

    $responseHeaders = [];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'FeedEnhancer-CI/1.0',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $position = strpos($line, ':');
            if (false === $position) {
                return $length;
            }

            $name = strtolower(trim(substr($line, 0, $position)));
            $value = trim(substr($line, $position + 1));
            $responseHeaders[$name][] = $value;
            return $length;
        },
    ]);

    if ('HEAD' === $method) {
        curl_setopt($handle, CURLOPT_NOBODY, true);
    }

    $body = curl_exec($handle);
    if (false === $body) {
        $error = curl_error($handle);
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

    return new ContractResponse($status, $responseHeaders, (string) $body);
}

function contractHeader(ContractResponse $response, string $name): ?string
{
    $values = $response->headers[strtolower($name)] ?? [];
    if ([] === $values) {
        return null;
    }

    return $values[count($values) - 1];
}

/** @param string[] $expected */
function assertHeaderValues(
    ContractResponse $response,
    string $name,
    array $expected,
    string $context
): void {
    $actual = $response->headers[strtolower($name)] ?? [];
    contractAssert(
        $expected === $actual,
        sprintf(
            '%s returned unexpected %s header values: %s.',
            $context,
            $name,
            json_encode($actual)
        )
    );
}

function assertManagedCacheHeaders(
    ContractResponse $response,
    string $cacheControl,
    string $context
): void {
    assertHeaderValues($response, 'Cache-Control', [$cacheControl], $context);
    assertHeaderValues($response, 'Vary', ['Cookie'], $context);

    foreach (['Expires', 'Pragma', 'Last-Modified'] as $name) {
        assertHeaderValues($response, $name, [], $context);
    }
}

function assertStatus(ContractResponse $response, int $expected, string $context): void
{
    contractAssert(
        $expected === $response->status,
        sprintf('%s returned HTTP %d instead of %d.', $context, $response->status, $expected)
    );
}

function assertContentType(ContractResponse $response, string $expected, string $context): void
{
    $actual = strtolower((string) contractHeader($response, 'Content-Type'));
    contractAssert(
        0 === strpos($actual, strtolower($expected)),
        sprintf('%s returned unexpected Content-Type %s.', $context, $actual)
    );
}

function parseFeedXml(string $xml, string $context): DOMDocument
{
    contractAssert(false === stripos($xml, '<!DOCTYPE'), $context . ' contains a DOCTYPE.');

    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    contractAssert($loaded, $context . ' is not valid XML.');
    return $document;
}

/** @param string[] $needles */
function assertContainsAll(string $body, array $needles, string $context): void
{
    foreach ($needles as $needle) {
        contractAssert(
            false !== strpos($body, $needle),
            sprintf('%s is missing sentinel %s.', $context, $needle)
        );
    }
}

/** @param string[] $needles */
function assertContainsNone(string $body, array $needles, string $context): void
{
    foreach ($needles as $needle) {
        contractAssert(
            false === strpos($body, $needle),
            sprintf('%s leaked sentinel %s.', $context, $needle)
        );
    }
}

function feedEntryCount(DOMDocument $document, string $protocol): int
{
    if ('atom' === $protocol) {
        return $document->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'entry')->length;
    }

    if ('rss1' === $protocol) {
        return $document->getElementsByTagNameNS('http://purl.org/rss/1.0/', 'item')->length;
    }

    return $document->getElementsByTagName('item')->length;
}

function feedEntryElement(DOMDocument $document, string $protocol, string $linkNeedle): DOMElement
{
    if ('atom' === $protocol) {
        $entries = $document->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'entry');
        $linkNamespace = 'http://www.w3.org/2005/Atom';
    } elseif ('rss1' === $protocol) {
        $entries = $document->getElementsByTagNameNS('http://purl.org/rss/1.0/', 'item');
        $linkNamespace = 'http://purl.org/rss/1.0/';
    } else {
        $entries = $document->getElementsByTagName('item');
        $linkNamespace = null;
    }

    foreach ($entries as $entry) {
        if (!$entry instanceof DOMElement) {
            continue;
        }

        $links = null === $linkNamespace
            ? $entry->getElementsByTagName('link')
            : $entry->getElementsByTagNameNS($linkNamespace, 'link');
        $matched = false;
        foreach ($links as $link) {
            $value = 'atom' === $protocol && $link instanceof DOMElement
                ? $link->getAttribute('href')
                : $link->textContent;
            if (false !== strpos($value, $linkNeedle)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            continue;
        }

        return $entry;
    }

    throw new RuntimeException('Unable to find Feed entry containing link ' . $linkNeedle . '.');
}

function feedEntryContent(DOMDocument $document, string $protocol, string $linkNeedle): string
{
    $entry = feedEntryElement($document, $protocol, $linkNeedle);

    if ('atom' === $protocol) {
        $content = $entry->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'content')->item(0);
    } elseif ('rss1' === $protocol) {
        $content = $entry->getElementsByTagNameNS('http://purl.org/rss/1.0/', 'description')->item(0);
    } else {
        $content = $entry->getElementsByTagNameNS(
            'http://purl.org/rss/1.0/modules/content/',
            'encoded'
        )->item(0);
    }

    contractAssert($content instanceof DOMElement, 'Matched Feed entry is missing content.');
    return trim($content->textContent);
}

function feedEntryExcerpt(DOMDocument $document, string $protocol, string $linkNeedle): ?string
{
    $entry = feedEntryElement($document, $protocol, $linkNeedle);
    if ('atom' === $protocol) {
        $excerpt = $entry->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'summary')->item(0);
    } elseif ('rss2' === $protocol) {
        $excerpt = $entry->getElementsByTagName('description')->item(0);
    } else {
        return null;
    }

    return $excerpt instanceof DOMElement ? trim($excerpt->textContent) : null;
}

function feedEntryMediaUrl(
    DOMDocument $document,
    string $protocol,
    string $linkNeedle,
    string $localName
): ?string {
    $entry = feedEntryElement($document, $protocol, $linkNeedle);
    $media = $entry->getElementsByTagNameNS(
        'http://search.yahoo.com/mrss/',
        $localName
    )->item(0);

    return $media instanceof DOMElement ? $media->getAttribute('url') : null;
}

/** @return string[] */
function aggregateLeakSentinels(): array
{
    return [
        'FE-HIDDEN-TITLE-SENTINEL',
        'FE-HIDDEN-BODY-SENTINEL',
        'FE-PRIVATE-TITLE-SENTINEL',
        'FE-PRIVATE-BODY-SENTINEL',
        'fe-private-slug-sentinel',
        'FE-PASSWORD-TITLE-SENTINEL',
        'FE-PASSWORD-BODY-SENTINEL',
        'FE-NOFEED-TITLE-SENTINEL',
        'FE-NOFEED-BODY-SENTINEL',
        'FE-WAITING-TITLE-SENTINEL',
        'FE-WAITING-BODY-SENTINEL',
        'FE-FUTURE-TITLE-SENTINEL',
        'FE-FUTURE-BODY-SENTINEL',
        'FE-DRAFT-TITLE-SENTINEL',
        'FE-DRAFT-BODY-SENTINEL',
        'FE-REVISION-TITLE-SENTINEL',
        'FE-REVISION-BODY-SENTINEL',
        'FE-SECRET-AUTHOR-SENTINEL',
        'feed-secret-author',
    ];
}

/** @return string[] */
function commentLeakSentinels(): array
{
    return [
        'FE-PRIVATE-COMMENT-',
        'FE-HIDDEN-COMMENT-SENTINEL',
        'FE-PASSWORD-COMMENT-SENTINEL',
        'FE-NOFEED-COMMENT-SENTINEL',
        'FE-FUTURE-COMMENT-SENTINEL',
        'FE-SPAM-COMMENT-SENTINEL',
        'FE-ORPHAN-COMMENT-SENTINEL',
    ];
}

function assertGlobalComments(string $baseUrl, string $path, string $protocol): ContractResponse
{
    $context = 'global comments ' . $protocol;
    $response = contractRequest('GET', $baseUrl . $path);
    assertStatus($response, 200, $context);
    $document = parseFeedXml($response->body, $context);
    contractAssert(10 === feedEntryCount($document, $protocol), $context . ' must contain 10 entries.');

    $public = [];
    for ($index = 0; $index < 10; ++$index) {
        $public[] = sprintf('FE-PUBLIC-COMMENT-%02d', $index);
    }
    assertContainsAll($response->body, $public, $context);
    assertContainsNone($response->body, commentLeakSentinels(), $context);
    return $response;
}

function runCommentContract(string $baseUrl): void
{
    assertGlobalComments($baseUrl, '/feed/comments/', 'rss2');
}

function runSafariContract(string $baseUrl): void
{
    $rss2 = contractRequest('GET', $baseUrl . '/feed/');
    assertStatus($rss2, 200, 'Safari RSS2');
    assertContentType($rss2, 'application/xml', 'Safari RSS2');
    parseFeedXml($rss2->body, 'Safari RSS2');
    contractAssert(
        false !== strpos($rss2->body, 'feed-enhancer-stylesheet=1'),
        'Safari RSS2 is missing its stylesheet instruction.'
    );

    $rss1 = contractRequest('GET', $baseUrl . '/feed/rss/');
    assertStatus($rss1, 200, 'Safari RSS1');
    assertContentType($rss1, 'application/rdf+xml', 'Safari RSS1');

    $atom = contractRequest('GET', $baseUrl . '/feed/atom/');
    assertStatus($atom, 200, 'Safari Atom');
    assertContentType($atom, 'application/atom+xml', 'Safari Atom');
}

function probeEventCount(string $logFile, string $expected): int
{
    if (!is_file($logFile)) {
        return 0;
    }

    $events = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($events)) {
        return 0;
    }

    return count(array_filter(
        $events,
        static fn (string $event): bool => $expected === $event
    ));
}

function assertFeedCacheContract(
    string $baseUrl,
    string $path,
    ContractResponse $initial,
    string $context
): void {
    $etag = contractHeader($initial, 'ETag');
    contractAssert(
        is_string($etag) && 0 === strpos($etag, '"sha256-'),
        $context . ' is missing a strong SHA-256 ETag.'
    );

    $repeat = contractRequest('GET', $baseUrl . $path);
    assertStatus($repeat, 200, $context . ' repeat GET');
    contractAssert($initial->body === $repeat->body, $context . ' bytes changed between GET requests.');
    contractAssert($etag === contractHeader($repeat, 'ETag'), $context . ' ETag changed between GET requests.');

    $head = contractRequest('HEAD', $baseUrl . $path);
    assertStatus($head, 200, $context . ' HEAD');
    contractAssert($etag === contractHeader($head, 'ETag'), $context . ' HEAD ETag differs from GET.');
    contractAssert(
        (string) strlen($initial->body) === contractHeader($head, 'Content-Length'),
        $context . ' HEAD Content-Length differs from GET.'
    );

    $notModified = contractRequest('GET', $baseUrl . $path, ['If-None-Match: ' . $etag]);
    assertStatus($notModified, 304, $context . ' conditional GET');
    contractAssert('' === $notModified->body, $context . ' 304 response contains a body.');
}

/**
 * @param array<string,ContractResponse> $commentResponses
 * @param array<string,ContractResponse> $singleCommentResponses
 */
function storeBaselineRepresentations(
    string $stateFile,
    ContractResponse $response,
    array $commentResponses,
    array $singleCommentResponses
): void {
    $state = [];
    if (is_file($stateFile)) {
        $decoded = json_decode((string) file_get_contents($stateFile), true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

    $etag = contractHeader($response, 'ETag');
    contractAssert(is_string($etag) && '' !== $etag, 'Baseline RSS2 is missing its ETag.');
    $state['baselineRss2BodySha256'] = hash('sha256', $response->body);
    $state['baselineRss2Etag'] = $etag;
    $state['baselineCommentFeeds'] = [];
    foreach ($commentResponses as $protocol => $commentResponse) {
        $commentEtag = contractHeader($commentResponse, 'ETag');
        contractAssert(is_string($commentEtag) && '' !== $commentEtag, $protocol . ' comments are missing an ETag.');
        $state['baselineCommentFeeds'][$protocol] = [
            'bodySha256' => hash('sha256', $commentResponse->body),
            'etag' => $commentEtag,
        ];
    }
    $state['baselineSingleCommentFeeds'] = [];
    foreach ($singleCommentResponses as $protocol => $commentResponse) {
        $commentEtag = contractHeader($commentResponse, 'ETag');
        contractAssert(
            is_string($commentEtag) && '' !== $commentEtag,
            $protocol . ' single comments are missing an ETag.'
        );
        $state['baselineSingleCommentFeeds'][$protocol] = [
            'bodySha256' => hash('sha256', $commentResponse->body),
            'etag' => $commentEtag,
        ];
    }

    $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    contractAssert(
        false !== $encoded && false !== file_put_contents($stateFile, $encoded . "\n"),
        'Unable to persist the baseline RSS2 representation.'
    );
}

/** @return array{0:ContractResponse,1:DOMDocument,2:string} */
function requestArchiveFeed(
    string $baseUrl,
    string $protocol,
    string $feedPath,
    string $contentType,
    string $suffix
): array {
    $path = $feedPath . $suffix;
    $context = $protocol . ' truncated archive ' . $path;
    $response = contractRequest('GET', $baseUrl . $path);
    $effectiveProtocol = $protocol;

    if (301 === $response->status) {
        contractAssert(
            'rss1' === $protocol && 'search/PUBLIC/' === $suffix,
            $context . ' redirected unexpectedly.'
        );
        $location = (string) contractHeader($response, 'Location');
        contractAssert(
            $baseUrl . '/feed/atom/search/PUBLIC/' === $location,
            $context . ' redirected to an unexpected upstream canonical URL: ' . $location
        );
        $response = contractRequest('GET', $location);
        $effectiveProtocol = 'atom';
        assertContentType($response, 'application/atom+xml', $context . ' canonical target');
    } else {
        assertContentType($response, $contentType, $context);
    }

    assertStatus($response, 200, $context);
    return [$response, parseFeedXml($response->body, $context), $effectiveProtocol];
}

function runProbeContract(string $baseUrl, string $probeLog): void
{
    contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
    $feed = contractRequest('GET', $baseUrl . '/feed/');
    assertStatus($feed, 200, 'third-party article probe');
    assertContainsAll($feed->body, [
        'FE-CONTENT-HOOK-100',
        'FE-FEED-ITEM-SUFFIX-100',
        'urn:feed-enhancer:ci:probe',
    ], 'third-party article probe');
    contractAssert(
        1 === probeEventCount($probeLog, 'content:100'),
        'third-party content hook was not called exactly once for cid 100.'
    );
    contractAssert(
        1 === probeEventCount($probeLog, 'feedItem:100'),
        'third-party feedItem hook was not called exactly once for cid 100.'
    );
    contractAssert(
        1 === probeEventCount($probeLog, 'feedItemFeedFullText:100:0'),
        'third-party feedItem hook observed a changed feedFullText value.'
    );

    contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
    $comments = contractRequest('GET', $baseUrl . '/feed/comments/');
    assertStatus($comments, 200, 'third-party comment probe');
    assertContainsAll($comments->body, [
        'FE-COMMENT-ITEM-SUFFIX-209',
        'urn:feed-enhancer:ci:probe',
    ], 'third-party comment probe');
    contractAssert(
        1 === probeEventCount($probeLog, 'commentFeedItem:209'),
        'third-party commentFeedItem hook was not called exactly once for coid 209.'
    );
    contractAssert(
        1 === probeEventCount($probeLog, 'commentFeedItemFeedFullText:209:0'),
        'third-party commentFeedItem hook observed a changed feedFullText value.'
    );
}

/** @param array<string,mixed> $fixtureState */
function runTruncationContract(string $baseUrl, string $probeLog, array $fixtureState): void
{
    $protocols = [
        'rss2' => ['/feed/', 'application/rss+xml'],
        'rss1' => ['/feed/rss/', 'application/rdf+xml'],
        'atom' => ['/feed/atom/', 'application/atom+xml'],
    ];
    $expectedMoreTeaser = 'FE-MORE-LEAD-' . str_repeat('X', 34) . '...';
    $expectedNoMoreTeaser = 'FE-NOMORE-SHORT FE-NOMORE-SECOND-'
        . str_repeat('Y', 14) . '...';
    $fieldMediaUrl = $baseUrl . '/media/field-cover.jpg';
    $attachmentMediaUrl = $baseUrl . '/media/no-more-attachment.jpg';
    $rss2 = null;

    foreach ($protocols as $protocol => [$path, $contentType]) {
        contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
        $response = contractRequest('GET', $baseUrl . $path);
        assertStatus($response, 200, $protocol . ' truncated');
        assertContentType($response, $contentType, $protocol . ' truncated');
        $document = parseFeedXml($response->body, $protocol . ' truncated');
        $moreContent = feedEntryContent($document, $protocol, '/archives/121/');
        $noMoreContent = feedEntryContent($document, $protocol, '/archives/122/');

        assertContainsAll(
            $moreContent,
            [$expectedMoreTeaser, 'FE-CI-READ-MORE'],
            $protocol . ' truncated more content'
        );
        assertContainsNone(
            $moreContent,
            ['FE-MORE-TAIL-SENTINEL', 'FE-CONTENT-HOOK-121', 'FE-EXCERPT-HOOK-121', '[...]'],
            $protocol . ' truncated more content'
        );
        assertContainsAll(
            $noMoreContent,
            [$expectedNoMoreTeaser, 'FE-CI-READ-MORE'],
            $protocol . ' truncated no-more content'
        );
        assertContainsNone(
            $noMoreContent,
            [
                'FE-NOMORE-TAIL-SENTINEL',
                'FE-NOMORE-HEADING',
                'FE-CONTENT-HOOK-122',
                'FE-EXCERPT-HOOK-122',
                '/media/no-more-body.jpg',
                '[...]',
            ],
            $protocol . ' truncated no-more content'
        );
        foreach ([$moreContent, $noMoreContent] as $articleContent) {
            contractAssert(
                1 === substr_count($articleContent, 'FE-CI-READ-MORE'),
                $protocol . ' truncated content did not contain exactly one configured CTA label.'
            );
            if ('rss1' !== $protocol) {
                contractAssert(
                    1 === substr_count($articleContent, 'class="more"'),
                    $protocol . ' truncated content did not contain exactly one CTA element.'
                );
            }
        }
        foreach (['/archives/121/', '/archives/122/'] as $linkNeedle) {
            $excerpt = feedEntryExcerpt($document, $protocol, $linkNeedle);
            if (null !== $excerpt) {
                assertContainsNone(
                    $excerpt,
                    ['FE-CI-READ-MORE', 'class="more"'],
                    $protocol . ' truncated description or summary'
                );
            }
        }
        assertContainsAll(
            $response->body,
            ['FE-FEED-ITEM-SUFFIX-121', 'FE-FEED-ITEM-SUFFIX-122', 'media:content'],
            $protocol . ' truncated extensions'
        );
        assertContainsNone(
            $response->body,
            [
                '/media/body.jpg',
                '/media/more-body.jpg',
                '/media/no-more-body.jpg',
                '/media/field-priority-attachment.jpg',
            ],
            $protocol . ' truncated media candidates'
        );
        foreach (['content', 'thumbnail'] as $mediaElement) {
            contractAssert(
                $fieldMediaUrl === feedEntryMediaUrl(
                    $document,
                    $protocol,
                    '/archives/100/',
                    $mediaElement
                ),
                $protocol . ' did not preserve field media priority for ' . $mediaElement . '.'
            );
            contractAssert(
                $attachmentMediaUrl === feedEntryMediaUrl(
                    $document,
                    $protocol,
                    '/archives/122/',
                    $mediaElement
                ),
                $protocol . ' did not fall back to attachment media for ' . $mediaElement . '.'
            );
        }
        contractAssert(
            1 === probeEventCount($probeLog, 'content:121'),
            $protocol . ' did not call the third-party content hook exactly once.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'feedItem:121'),
            $protocol . ' did not preserve the third-party feedItem hook.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'feedItemFeedFullText:121:1'),
            $protocol . ' article hook did not observe the temporary feedFullText override.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'content:122'),
            $protocol . ' did not call the no-more content hook exactly once.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'feedItem:122'),
            $protocol . ' did not preserve the no-more feedItem hook.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'feedItemFeedFullText:122:1'),
            $protocol . ' no-more article hook did not observe the temporary feedFullText override.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'feedFullText:0'),
            $protocol . ' did not restore feedFullText after rendering.'
        );

        if ('rss2' === $protocol) {
            $rss2 = $response;
        }
    }

    contractAssert($rss2 instanceof ContractResponse, 'Truncated RSS2 response was not captured.');
    $baselineBodyHash = $fixtureState['baselineRss2BodySha256'] ?? null;
    $baselineEtag = $fixtureState['baselineRss2Etag'] ?? null;
    contractAssert(is_string($baselineBodyHash), 'The baseline RSS2 body hash was not captured.');
    contractAssert(is_string($baselineEtag), 'The baseline RSS2 ETag was not captured.');
    contractAssert(
        $baselineBodyHash !== hash('sha256', $rss2->body),
        'Enabling truncation did not change the RSS2 body bytes.'
    );
    contractAssert(
        $baselineEtag !== contractHeader($rss2, 'ETag'),
        'Enabling truncation did not change the RSS2 ETag.'
    );
    assertFeedCacheContract($baseUrl, '/feed/', $rss2, 'truncated RSS2');

    $archiveSuffixes = [
        'category/feed-ci-category/',
        'tag/feed-ci-tag/',
        'author/1/',
        '2020/01/02/',
        'search/PUBLIC/',
    ];
    foreach ($protocols as $protocol => [$feedPath, $contentType]) {
        foreach ($archiveSuffixes as $suffix) {
            contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
            [$response, $document, $effectiveProtocol] = requestArchiveFeed(
                $baseUrl,
                $protocol,
                $feedPath,
                $contentType,
                $suffix
            );
            $context = $protocol . ' truncated archive ' . $suffix;
            $content = feedEntryContent($document, $effectiveProtocol, '/archives/100/');
            assertContainsAll($content, ['FE-PUBLIC-BODY-SENTINEL', 'FE-CI-READ-MORE'], $context);
            assertContainsNone(
                $content,
                ['FE-CONTENT-HOOK-100', 'FE-EXCERPT-HOOK-100', '/media/body.jpg', '[...]'],
                $context
            );
            assertContainsNone($response->body, aggregateLeakSentinels(), $context);
            contractAssert(
                1 === probeEventCount($probeLog, 'feedFullText:0'),
                $context . ' did not restore feedFullText.'
            );
            contractAssert(
                1 === probeEventCount($probeLog, 'feedItemFeedFullText:100:1'),
                $context . ' article hook did not observe the temporary feedFullText override.'
            );
        }
    }

    foreach ($protocols as $protocol => [$path]) {
        contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
        $comments = assertGlobalComments($baseUrl, $path . 'comments/', $protocol);
        assertContainsNone($comments->body, ['FE-CI-READ-MORE'], $protocol . ' truncated global comments');
        $baselineComments = $fixtureState['baselineCommentFeeds'][$protocol] ?? null;
        contractAssert(is_array($baselineComments), $protocol . ' baseline comments were not captured.');
        contractAssert(
            ($baselineComments['bodySha256'] ?? null) === hash('sha256', $comments->body),
            $protocol . ' global comments changed when truncation was enabled.'
        );
        contractAssert(
            ($baselineComments['etag'] ?? null) === contractHeader($comments, 'ETag'),
            $protocol . ' global comments ETag changed when truncation was enabled.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'commentFeedItem:209'),
            $protocol . ' truncated mode changed the commentFeedItem contract.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'commentFeedItemFeedFullText:209:0'),
            $protocol . ' global comment hook observed a changed feedFullText value.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'feedFullText:0'),
            $protocol . ' comments did not restore feedFullText.'
        );

        contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
        $single = contractRequest('GET', $baseUrl . $path . 'archives/120/');
        assertStatus($single, 200, $protocol . ' truncated single comments');
        $singleDocument = parseFeedXml($single->body, $protocol . ' truncated single comments');
        contractAssert(
            10 === feedEntryCount($singleDocument, $protocol),
            $protocol . ' truncated single comments must contain 10 entries.'
        );
        assertContainsAll(
            $single->body,
            [
                'FE-PUBLIC-COMMENT-00',
                'FE-PUBLIC-COMMENT-09',
                'FE-COMMENT-ITEM-SUFFIX-200',
                'FE-COMMENT-ITEM-SUFFIX-209',
            ],
            $protocol . ' truncated single comments'
        );
        assertContainsNone($single->body, ['FE-CI-READ-MORE'], $protocol . ' truncated single comments');
        $baselineSingleComments = $fixtureState['baselineSingleCommentFeeds'][$protocol] ?? null;
        contractAssert(
            is_array($baselineSingleComments),
            $protocol . ' baseline single comments were not captured.'
        );
        contractAssert(
            ($baselineSingleComments['bodySha256'] ?? null) === hash('sha256', $single->body),
            $protocol . ' single comments changed when truncation was enabled.'
        );
        contractAssert(
            ($baselineSingleComments['etag'] ?? null) === contractHeader($single, 'ETag'),
            $protocol . ' single comments ETag changed when truncation was enabled.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'commentFeedItem:200')
                && 1 === probeEventCount($probeLog, 'commentFeedItem:209'),
            $protocol . ' single comments changed the commentFeedItem call count.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'commentFeedItemFeedFullText:209:0'),
            $protocol . ' single comment hook observed a changed feedFullText value.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'feedFullText:0'),
            $protocol . ' single comments changed feedFullText after rendering.'
        );
    }
}

function runFeedFullTextRestoreOneContract(string $baseUrl, string $probeLog): void
{
    contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
    $response = contractRequest('GET', $baseUrl . '/feed/');
    assertStatus($response, 200, 'feedFullText=1 restoration');
    $document = parseFeedXml($response->body, 'feedFullText=1 restoration');
    $content = feedEntryContent($document, 'rss2', '/archives/122/');
    assertContainsAll(
        $content,
        ['FE-NOMORE-SHORT', 'FE-NOMORE-SECOND-', 'FE-CI-READ-MORE'],
        'feedFullText=1 restoration'
    );
    contractAssert(
        1 === probeEventCount($probeLog, 'feedFullText:1'),
        'The original feedFullText=1 value was not restored after rendering.'
    );
    contractAssert(
        0 === probeEventCount($probeLog, 'feedFullText:0'),
        'The feedFullText=1 restoration probe observed the wrong value.'
    );
}

function runFullContract(
    string $baseUrl,
    ?string $loginCookie,
    ?string $probeLog,
    ?string $fixtureStateFile
): void {
    $protocols = [
        'rss2' => ['/feed/', 'application/rss+xml'],
        'rss1' => ['/feed/rss/', 'application/rdf+xml'],
        'atom' => ['/feed/atom/', 'application/atom+xml'],
    ];

    $responses = [];
    foreach ($protocols as $protocol => [$path, $contentType]) {
        $response = contractRequest('GET', $baseUrl . $path);
        assertStatus($response, 200, $protocol);
        assertContentType($response, $contentType, $protocol);
        $document = parseFeedXml($response->body, $protocol);
        assertContainsAll($response->body, ['FE-PUBLIC-SEARCH-TITLE', 'FE-PUBLIC-BODY-SENTINEL'], $protocol);
        assertContainsNone($response->body, aggregateLeakSentinels(), $protocol);
        $nativeMore = feedEntryContent($document, $protocol, '/archives/121/');
        assertContainsAll(
            $nativeMore,
            ['FE-MORE-LEAD-' . str_repeat('X', 60), '[...]'],
            $protocol . ' native more content'
        );
        assertContainsNone(
            $nativeMore,
            ['FE-MORE-TAIL-SENTINEL', 'FE-CI-READ-MORE'],
            $protocol . ' native more content'
        );
        foreach (['content', 'thumbnail'] as $mediaElement) {
            $expectedMediaUrl = 'rss1' === $protocol
                ? $baseUrl . '/media/no-more-attachment.jpg'
                : $baseUrl . '/media/no-more-body.jpg';
            contractAssert(
                $expectedMediaUrl === feedEntryMediaUrl(
                    $document,
                    $protocol,
                    '/archives/122/',
                    $mediaElement
                ),
                $protocol . ' did not use the expected final Feed body or attachment candidate for '
                    . $mediaElement . '.'
            );
        }
        $responses[$protocol] = $response;
    }

    contractAssert(
        is_string($fixtureStateFile) && '' !== $fixtureStateFile,
        'FE_FIXTURE_STATE is required to compare truncation representations.'
    );
    contractAssert(
        false !== strpos($responses['rss2']->body, 'feed-enhancer-stylesheet=1'),
        'RSS2 is missing its stylesheet instruction.'
    );
    contractAssert(
        false !== strpos($responses['rss2']->body, 'v=1.2.0'),
        'RSS2 stylesheet instruction did not use the 1.2.0 cachebuster.'
    );
    contractAssert(
        false === strpos($responses['rss1']->body, 'feed-enhancer-stylesheet=1'),
        'RSS1 must not contain the RSS2 stylesheet instruction.'
    );
    contractAssert(
        false === strpos($responses['atom']->body, 'feed-enhancer-stylesheet=1'),
        'Atom must not contain the RSS2 stylesheet instruction.'
    );
    assertContainsAll(
        $responses['rss2']->body,
        ['media:content', 'media:thumbnail', '/media/field-cover.jpg'],
        'RSS2 Media RSS'
    );

    $atomDocument = parseFeedXml($responses['atom']->body, 'Atom timing');
    $xpath = new DOMXPath($atomDocument);
    $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
    $entry = $xpath->query('//atom:entry[atom:link[contains(@href, "/archives/100/")]]')->item(0);
    contractAssert($entry instanceof DOMElement, 'Atom is missing the public fixture entry.');
    $published = $xpath->query('atom:published', $entry)->item(0);
    $updated = $xpath->query('atom:updated', $entry)->item(0);
    contractAssert($published instanceof DOMElement, 'Atom fixture entry is missing published.');
    contractAssert($updated instanceof DOMElement, 'Atom fixture entry is missing updated.');
    contractAssert(1577966400 === strtotime($published->textContent), 'Atom published changed unexpectedly.');
    contractAssert(1609588800 === strtotime($updated->textContent), 'Atom updated did not use modified.');

    $feedUpdated = $xpath->query('/atom:feed/atom:updated')->item(0);
    contractAssert($feedUpdated instanceof DOMElement, 'Atom feed is missing updated.');
    $maximum = PHP_INT_MIN;
    foreach ($xpath->query('/atom:feed/atom:entry/atom:updated') as $node) {
        $maximum = max($maximum, (int) strtotime($node->textContent));
    }
    contractAssert($maximum === strtotime($feedUpdated->textContent), 'Atom feed updated is not the entry maximum.');

    $archiveSuffixes = [
        'category/feed-ci-category/',
        'tag/feed-ci-tag/',
        'author/1/',
        '2020/01/02/',
        'search/PUBLIC/',
    ];
    foreach ($protocols as $protocol => [$feedPath, $contentType]) {
        foreach ($archiveSuffixes as $suffix) {
            $path = $feedPath . $suffix;
            $context = $protocol . ' archive ' . $path;
            $response = contractRequest('GET', $baseUrl . $path);

            if (301 === $response->status) {
                contractAssert(
                    'rss1' === $protocol && 'search/PUBLIC/' === $suffix,
                    $context . ' redirected unexpectedly.'
                );
                assertContainsNone($response->body, aggregateLeakSentinels(), $context . ' redirect');
                $location = (string) contractHeader($response, 'Location');
                contractAssert(
                    $baseUrl . '/feed/atom/search/PUBLIC/' === $location,
                    $context . ' redirected to an unexpected upstream canonical URL: ' . $location
                );
                $response = contractRequest('GET', $location);
                assertContentType($response, 'application/atom+xml', $context . ' canonical target');
            } else {
                assertContentType($response, $contentType, $context);
            }

            assertStatus($response, 200, $context);
            parseFeedXml($response->body, $context);
            assertContainsAll($response->body, ['FE-PUBLIC-SEARCH-TITLE'], $context);
            assertContainsNone($response->body, aggregateLeakSentinels(), $context);
        }
    }

    $commentResponses = [
        'rss2' => assertGlobalComments($baseUrl, '/feed/comments/', 'rss2'),
        'rss1' => assertGlobalComments($baseUrl, '/feed/rss/comments/', 'rss1'),
        'atom' => assertGlobalComments($baseUrl, '/feed/atom/comments/', 'atom'),
    ];

    contractAssert(
        is_string($probeLog) && '' !== $probeLog,
        'FE_PROBE_LOG is required for the full integration contract.'
    );
    $singleCommentResponses = [];

    foreach ($protocols as $protocol => [$feedPath]) {
        $singleContext = $protocol . ' single public comments';
        contractAssert(false !== file_put_contents($probeLog, ''), 'Unable to clear probe log.');
        $single = contractRequest('GET', $baseUrl . $feedPath . 'archives/120/');
        assertStatus($single, 200, $singleContext);
        $singleDocument = parseFeedXml($single->body, $singleContext);
        contractAssert(
            10 === feedEntryCount($singleDocument, $protocol),
            $singleContext . ' must contain 10 entries.'
        );
        assertContainsAll(
            $single->body,
            [
                'FE-PUBLIC-COMMENT-00',
                'FE-PUBLIC-COMMENT-09',
                'FE-COMMENT-ITEM-SUFFIX-200',
                'FE-COMMENT-ITEM-SUFFIX-209',
            ],
            $singleContext
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'commentFeedItem:200')
                && 1 === probeEventCount($probeLog, 'commentFeedItem:209'),
            $singleContext . ' changed the commentFeedItem call count.'
        );
        contractAssert(
            1 === probeEventCount($probeLog, 'commentFeedItemFeedFullText:209:0'),
            $singleContext . ' hook observed a changed feedFullText value.'
        );
        $singleCommentResponses[$protocol] = $single;

        $hiddenContext = $protocol . ' single hidden comments';
        $hidden = contractRequest('GET', $baseUrl . $feedPath . 'archives/101/');
        assertStatus($hidden, 200, $hiddenContext);
        parseFeedXml($hidden->body, $hiddenContext);
        assertContainsAll(
            $hidden->body,
            ['FE-HIDDEN-TITLE-SENTINEL', 'FE-HIDDEN-COMMENT-SENTINEL'],
            $hiddenContext
        );

        foreach ([102, 103, 104, 105, 106, 107, 108] as $cid) {
            $blockedContext = $protocol . ' blocked single cid ' . $cid;
            $blocked = contractRequest('GET', $baseUrl . $feedPath . 'archives/' . $cid . '/');
            assertStatus($blocked, 404, $blockedContext);
            assertContainsNone(
                $blocked->body,
                array_merge(aggregateLeakSentinels(), commentLeakSentinels()),
                $blockedContext
            );
        }
    }

    storeBaselineRepresentations(
        $fixtureStateFile,
        $responses['rss2'],
        $commentResponses,
        $singleCommentResponses
    );

    $canonicalPaths = [
        '/feed' => '/feed/',
        '/feed/rss' => '/feed/rss/',
        '/feed/atom' => '/feed/atom/',
    ];
    foreach ($canonicalPaths as $requestPath => $targetPath) {
        $canonical = contractRequest('GET', $baseUrl . $requestPath);
        assertStatus($canonical, 301, 'non-canonical feed URL ' . $requestPath);
        $location = (string) contractHeader($canonical, 'Location');
        contractAssert(
            $baseUrl . $targetPath === $location,
            'canonical redirect target is unexpected: ' . $location
        );
        assertContainsNone(
            $canonical->body,
            array_merge(aggregateLeakSentinels(), commentLeakSentinels()),
            'non-canonical feed URL ' . $requestPath
        );
    }

    $rss2 = $responses['rss2'];
    assertManagedCacheHeaders(
        $rss2,
        'private, max-age=0, must-revalidate',
        'RSS2 GET'
    );
    $etag = contractHeader($rss2, 'ETag');
    contractAssert(is_string($etag) && 0 === strpos($etag, '"sha256-'), 'RSS2 is missing a strong SHA-256 ETag.');

    usleep(1100000);
    $repeatGet = contractRequest('GET', $baseUrl . '/feed/');
    assertStatus($repeatGet, 200, 'repeated RSS2 GET');
    contractAssert(
        $rss2->body === $repeatGet->body,
        sprintf(
            'RSS2 bytes changed without content changes: %s then %s.',
            hash('sha256', $rss2->body),
            hash('sha256', $repeatGet->body)
        )
    );
    contractAssert(
        $etag === contractHeader($repeatGet, 'ETag'),
        'RSS2 ETag changed without content changes.'
    );

    $head = contractRequest('HEAD', $baseUrl . '/feed/');
    assertStatus($head, 200, 'RSS2 HEAD');
    $headEtag = contractHeader($head, 'ETag');
    contractAssert(
        $etag === $headEtag,
        sprintf('HEAD ETag %s differs from GET %s.', (string) $headEtag, (string) $etag)
    );
    contractAssert(
        (string) strlen($rss2->body) === contractHeader($head, 'Content-Length'),
        'HEAD Content-Length differs from GET bytes.'
    );
    assertManagedCacheHeaders(
        $head,
        'private, max-age=0, must-revalidate',
        'RSS2 HEAD'
    );

    $notModified = contractRequest('GET', $baseUrl . '/feed/', ['If-None-Match: ' . $etag]);
    assertStatus($notModified, 304, 'RSS2 conditional GET');
    contractAssert('' === $notModified->body, '304 response must not contain a body.');
    assertManagedCacheHeaders(
        $notModified,
        'private, max-age=0, must-revalidate',
        'RSS2 conditional GET'
    );

    $methodNotAllowed = contractRequest('POST', $baseUrl . '/feed/');
    assertStatus($methodNotAllowed, 405, 'RSS2 POST');
    contractAssert(
        'GET, HEAD' === contractHeader($methodNotAllowed, 'Allow'),
        '405 response has an invalid Allow header.'
    );
    assertManagedCacheHeaders(
        $methodNotAllowed,
        'private, max-age=0, must-revalidate',
        'RSS2 POST'
    );

    $stylesheetUrl = $baseUrl . '/feed/?feed-enhancer-stylesheet=1&v=1.2.0';
    $stylesheet = contractRequest('GET', $stylesheetUrl);
    assertStatus($stylesheet, 200, 'XSL endpoint');
    assertContentType($stylesheet, 'application/xslt+xml', 'XSL endpoint');
    assertManagedCacheHeaders(
        $stylesheet,
        'private, max-age=0, must-revalidate',
        'XSL GET'
    );
    contractAssert(
        (string) strlen($stylesheet->body) === contractHeader($stylesheet, 'Content-Length'),
        'XSL GET Content-Length differs from its body bytes.'
    );
    $stylesheetEtag = '"sha256-' . hash('sha256', $stylesheet->body) . '"';
    contractAssert(
        $stylesheetEtag === contractHeader($stylesheet, 'ETag'),
        'XSL GET is missing its representation ETag.'
    );
    $stylesheetDocument = parseFeedXml($stylesheet->body, 'XSL endpoint');
    contractAssert(
        'stylesheet' === $stylesheetDocument->documentElement->localName,
        'XSL endpoint did not return a stylesheet.'
    );
    contractAssert(false === stripos($stylesheet->body, '<script'), 'XSL endpoint contains script.');
    contractAssert(
        false === stripos($stylesheet->body, 'disable-output-escaping'),
        'XSL endpoint contains disable-output-escaping.'
    );

    $stylesheetHead = contractRequest('HEAD', $stylesheetUrl);
    assertStatus($stylesheetHead, 200, 'XSL HEAD');
    assertContentType($stylesheetHead, 'application/xslt+xml', 'XSL HEAD');
    assertManagedCacheHeaders(
        $stylesheetHead,
        'private, max-age=0, must-revalidate',
        'XSL HEAD'
    );
    contractAssert('' === $stylesheetHead->body, 'XSL HEAD must not contain a body.');
    contractAssert(
        $stylesheetEtag === contractHeader($stylesheetHead, 'ETag'),
        'XSL HEAD ETag differs from GET.'
    );
    contractAssert(
        (string) strlen($stylesheet->body) === contractHeader($stylesheetHead, 'Content-Length'),
        'XSL HEAD Content-Length differs from GET bytes.'
    );

    $stylesheetNotModified = contractRequest(
        'GET',
        $stylesheetUrl,
        ['If-None-Match: ' . $stylesheetEtag]
    );
    assertStatus($stylesheetNotModified, 304, 'XSL conditional GET');
    assertContentType($stylesheetNotModified, 'application/xslt+xml', 'XSL conditional GET');
    assertManagedCacheHeaders(
        $stylesheetNotModified,
        'private, max-age=0, must-revalidate',
        'XSL conditional GET'
    );
    contractAssert('' === $stylesheetNotModified->body, 'XSL 304 must not contain a body.');
    contractAssert(
        $stylesheetEtag === contractHeader($stylesheetNotModified, 'ETag'),
        'XSL 304 ETag differs from GET.'
    );
    assertHeaderValues($stylesheetNotModified, 'Content-Length', [], 'XSL conditional GET');

    $stylesheetMethodNotAllowed = contractRequest('POST', $stylesheetUrl);
    assertStatus($stylesheetMethodNotAllowed, 405, 'XSL POST');
    assertContentType($stylesheetMethodNotAllowed, 'application/xslt+xml', 'XSL POST');
    assertManagedCacheHeaders(
        $stylesheetMethodNotAllowed,
        'private, max-age=0, must-revalidate',
        'XSL POST'
    );
    contractAssert('' === $stylesheetMethodNotAllowed->body, 'XSL 405 must not contain a body.');
    contractAssert(
        'GET, HEAD' === contractHeader($stylesheetMethodNotAllowed, 'Allow'),
        'XSL 405 response has an invalid Allow header.'
    );
    assertHeaderValues($stylesheetMethodNotAllowed, 'Content-Length', ['0'], 'XSL POST');
    assertHeaderValues($stylesheetMethodNotAllowed, 'ETag', [], 'XSL POST');

    contractAssert(null !== $loginCookie, 'The fixture did not provide an editor login cookie.');

    $loggedInStylesheet = contractRequest(
        'GET',
        $stylesheetUrl,
        ['Cookie: ' . $loginCookie]
    );
    assertStatus($loggedInStylesheet, 200, 'logged-in XSL GET');
    assertContentType($loggedInStylesheet, 'application/xslt+xml', 'logged-in XSL GET');
    assertManagedCacheHeaders($loggedInStylesheet, 'private, no-store', 'logged-in XSL GET');
    contractAssert(
        $stylesheet->body === $loggedInStylesheet->body,
        'Logged-in XSL bytes differ from anonymous GET.'
    );
    contractAssert(
        $stylesheetEtag === contractHeader($loggedInStylesheet, 'ETag'),
        'Logged-in XSL ETag differs from anonymous GET.'
    );
    contractAssert(
        (string) strlen($stylesheet->body) === contractHeader($loggedInStylesheet, 'Content-Length'),
        'Logged-in XSL Content-Length differs from anonymous GET bytes.'
    );

    $loggedInStylesheetNotModified = contractRequest(
        'GET',
        $stylesheetUrl,
        ['Cookie: ' . $loginCookie, 'If-None-Match: ' . $stylesheetEtag]
    );
    assertStatus($loggedInStylesheetNotModified, 304, 'logged-in XSL conditional GET');
    assertManagedCacheHeaders(
        $loggedInStylesheetNotModified,
        'private, no-store',
        'logged-in XSL conditional GET'
    );
    contractAssert(
        '' === $loggedInStylesheetNotModified->body,
        'Logged-in XSL 304 must not contain a body.'
    );
    contractAssert(
        $stylesheetEtag === contractHeader($loggedInStylesheetNotModified, 'ETag'),
        'Logged-in XSL 304 ETag differs from GET.'
    );

    $loggedIn = contractRequest('GET', $baseUrl . '/feed/', ['Cookie: ' . $loginCookie]);
    assertStatus($loggedIn, 200, 'logged-in editor RSS2');
    assertContainsAll(
        $loggedIn->body,
        ['FE-PUBLIC-SEARCH-TITLE', 'FE-PUBLIC-BODY-SENTINEL'],
        'logged-in editor RSS2'
    );
    assertContainsNone($loggedIn->body, aggregateLeakSentinels(), 'logged-in editor RSS2');
    assertManagedCacheHeaders($loggedIn, 'private, no-store', 'logged-in editor RSS2');

    runProbeContract($baseUrl, $probeLog);
}

try {
    if ('comments' === $mode) {
        runCommentContract($baseUrl);
    } elseif ('safari' === $mode) {
        runSafariContract($baseUrl);
    } elseif ('full' === $mode) {
        runFullContract(
            $baseUrl,
            $loginCookie,
            is_string($probeLog) ? $probeLog : null,
            is_string($fixtureStateFile) ? $fixtureStateFile : null
        );
    } elseif ('truncation' === $mode) {
        contractAssert(
            is_string($probeLog) && '' !== $probeLog,
            'FE_PROBE_LOG is required for the truncation contract.'
        );
        runTruncationContract($baseUrl, $probeLog, $fixtureState);
    } elseif ('truncation-restore-one' === $mode) {
        contractAssert(
            is_string($probeLog) && '' !== $probeLog,
            'FE_PROBE_LOG is required for the feedFullText restoration contract.'
        );
        runFeedFullTextRestoreOneContract($baseUrl, $probeLog);
    } else {
        throw new RuntimeException('Unknown HTTP contract mode: ' . $mode);
    }

    fwrite(STDOUT, sprintf("HTTP integration contract passed (%s).\n", $mode));
} catch (Throwable $exception) {
    fwrite(STDERR, 'HTTP integration failure: ' . $exception->getMessage() . "\n");
    exit(1);
}
