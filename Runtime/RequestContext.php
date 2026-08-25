<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Runtime;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Request-scoped state shared by the feed proxy and transparent Typecho hooks.
 */
final class RequestContext
{
    public const FORMAT_RSS2 = 'rss2';
    public const FORMAT_RSS1 = 'rss1';
    public const FORMAT_ATOM = 'atom';

    /** @var self|null */
    private static ?self $current = null;

    private string $feedPath;
    private string $contentPath;
    private string $format;
    private bool $globalComments;
    private ContentMetadataCollector $metadata;
    private bool $contentTruncationEnabled;
    private int $feedContentLength;
    private string $feedReadMoreText;
    private bool $feedFullTextOverrideApplied = false;

    /**
     * @param string[] $mediaFieldNames
     */
    private function __construct(
        string $feedPath,
        array $mediaFieldNames,
        bool $contentTruncationEnabled,
        int $feedContentLength,
        string $feedReadMoreText
    ) {
        $this->feedPath = self::normalizePath($feedPath);
        [$this->format, $this->contentPath] = self::splitProtocol($this->feedPath);
        $this->globalComments = (bool) preg_match('#^/comments/?$#', $this->contentPath);
        $this->metadata = new ContentMetadataCollector($mediaFieldNames);
        $this->contentTruncationEnabled = $contentTruncationEnabled;
        $this->feedContentLength = $feedContentLength;
        $this->feedReadMoreText = $feedReadMoreText;
    }

    /**
     * Enter the short-lived scope in which feed-only hooks are allowed to act.
     *
     * @param string[] $mediaFieldNames
     */
    public static function enter(
        string $feedPath,
        array $mediaFieldNames = [],
        bool $contentTruncationEnabled = false,
        int $feedContentLength = 300,
        string $feedReadMoreText = '阅读全文'
    ): self {
        if (null !== self::$current) {
            throw new \LogicException('A FeedEnhancer request context is already active.');
        }

        self::$current = new self(
            $feedPath,
            $mediaFieldNames,
            $contentTruncationEnabled,
            $feedContentLength,
            $feedReadMoreText
        );
        return self::$current;
    }

    public function leave(): void
    {
        if (self::$current === $this) {
            self::$current = null;
        }
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public function feedPath(): string
    {
        return $this->feedPath;
    }

    public function contentPath(): string
    {
        return $this->contentPath;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function isGlobalComments(): bool
    {
        return $this->globalComments;
    }

    public function metadata(): ContentMetadataCollector
    {
        return $this->metadata;
    }

    public function contentTruncationEnabled(): bool
    {
        return $this->contentTruncationEnabled;
    }

    public function feedContentLength(): int
    {
        return $this->feedContentLength;
    }

    public function feedReadMoreText(): string
    {
        return $this->feedReadMoreText;
    }

    public function markFeedFullTextOverrideApplied(): void
    {
        $this->feedFullTextOverrideApplied = true;
    }

    public function feedFullTextOverrideApplied(): bool
    {
        return $this->feedFullTextOverrideApplied;
    }

    /**
     * Typecho has not yet set Archive::$archiveSingle when handleInit runs, so
     * use the already resolved route type stored in the archive parameters.
     *
     * @param mixed $archive
     */
    public function isSingleArchive($archive): bool
    {
        try {
            $parameter = $archive->parameter;
            $type = is_object($parameter) ? (string) $parameter->type : '';
        } catch (\Throwable $exception) {
            return false;
        }

        return in_array($type, ['post', 'page', 'attachment', 'single'], true);
    }

    public function contentType(bool $safariXmlMime = false): string
    {
        if (self::FORMAT_ATOM === $this->format) {
            return 'application/atom+xml';
        }

        if (self::FORMAT_RSS1 === $this->format) {
            return 'application/rdf+xml';
        }

        return $safariXmlMime ? 'application/xml' : 'application/rss+xml';
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ('' === $path) {
            return '/';
        }

        return '/' === $path[0] ? $path : '/' . $path;
    }

    /**
     * @return string[]
     */
    private static function splitProtocol(string $path): array
    {
        if (preg_match('#^/rss(?:/|$)#', $path)) {
            return [self::FORMAT_RSS1, self::normalizeContentPath(substr($path, 4))];
        }

        if (preg_match('#^/atom(?:/|$)#', $path)) {
            return [self::FORMAT_ATOM, self::normalizeContentPath(substr($path, 5))];
        }

        return [self::FORMAT_RSS2, self::normalizeContentPath($path)];
    }

    private static function normalizeContentPath(string $path): string
    {
        return '' === $path ? '/' : self::normalizePath($path);
    }
}
