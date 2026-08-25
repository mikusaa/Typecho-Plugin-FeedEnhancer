<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Runtime;

use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class Settings
{
    private const DEFAULT_FIELD_NAMES = ['banner', 'cover', 'thumbnail'];
    private const DEFAULT_FEED_CONTENT_LENGTH = 300;
    private const DEFAULT_FEED_READ_MORE_TEXT = '阅读全文';

    private bool $contentTruncationEnabled;
    private int $feedContentLength;
    private string $feedReadMoreText;
    private bool $stylesheetEnabled;
    private bool $safariXmlMime;
    private bool $mediaEnabled;

    /** @var string[] */
    private array $mediaFieldNames;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(array $values = [])
    {
        $this->contentTruncationEnabled = self::booleanValue($values, 'feedContentMode', false);
        $this->feedContentLength = array_key_exists('feedContentLength', $values)
            ? self::normalizeFeedContentLength($values['feedContentLength'])
            : self::DEFAULT_FEED_CONTENT_LENGTH;
        $this->feedReadMoreText = array_key_exists('feedReadMoreText', $values)
            ? self::normalizeFeedReadMoreText($values['feedReadMoreText'])
            : self::DEFAULT_FEED_READ_MORE_TEXT;
        $this->stylesheetEnabled = self::booleanValue($values, 'stylesheetEnabled', true);
        $this->safariXmlMime = self::booleanValue($values, 'safariXmlMime', false);
        $this->mediaEnabled = self::booleanValue($values, 'mediaEnabled', true);
        $this->mediaFieldNames = array_key_exists('mediaFieldNames', $values)
            ? self::normalizeFieldNames($values['mediaFieldNames'])
            : self::DEFAULT_FIELD_NAMES;
    }

    public static function load(): self
    {
        try {
            $config = Options::alloc()->plugin('FeedEnhancer');
            $values = [];
            foreach (
                [
                    'feedContentMode',
                    'feedContentLength',
                    'feedReadMoreText',
                    'stylesheetEnabled',
                    'safariXmlMime',
                    'mediaEnabled',
                    'mediaFieldNames',
                ] as $key
            ) {
                $value = $config->{$key};
                if (null !== $value) {
                    $values[$key] = $value;
                }
            }

            return new self($values);
        } catch (\Throwable $exception) {
            return new self();
        }
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

    public function stylesheetEnabled(): bool
    {
        return $this->stylesheetEnabled;
    }

    public function safariXmlMime(): bool
    {
        return $this->safariXmlMime;
    }

    public function mediaEnabled(): bool
    {
        return $this->mediaEnabled;
    }

    /** @return string[] */
    public function mediaFieldNames(): array
    {
        return $this->mediaFieldNames;
    }

    /** @param mixed $value */
    public static function isValidFeedContentLength($value): bool
    {
        if (is_int($value)) {
            $length = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]{1,4}$/D', $value) === 1) {
            $length = (int) $value;
        } else {
            return false;
        }

        return $length >= 50 && $length <= 1000;
    }

    /** @param mixed $value */
    public static function normalizeFeedContentLength($value): int
    {
        return self::isValidFeedContentLength($value)
            ? (int) $value
            : self::DEFAULT_FEED_CONTENT_LENGTH;
    }

    /** @param mixed $value */
    public static function isValidFeedReadMoreText($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        if ('' === $value || 1 !== preg_match('//u', $value)) {
            return false;
        }

        if (1 === preg_match('/\p{Cc}/u', $value)) {
            return false;
        }

        $text = self::trimUnicodeWhitespace($value);
        if ('' === $text || 1 === preg_match('/[<>]/u', $text)) {
            return false;
        }

        return self::unicodeLength($text) <= 100;
    }

    /** @param mixed $value */
    public static function normalizeFeedReadMoreText($value): string
    {
        return self::isValidFeedReadMoreText($value)
            ? self::trimUnicodeWhitespace($value)
            : self::DEFAULT_FEED_READ_MORE_TEXT;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    public static function normalizeFieldNames($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_scalar($value)) {
            $parts = explode(',', (string) $value);
        } else {
            return [];
        }

        $names = [];
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }

            $name = trim((string) $part);
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
                continue;
            }

            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }

            if (count($names) === 10) {
                break;
            }
        }

        return $names;
    }

    /** @param array<string, mixed> $values */
    private static function booleanValue(array $values, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $values)) {
            return $default;
        }

        return $values[$key] === true || $values[$key] === 1 || $values[$key] === '1';
    }

    private static function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);
        return false === $count ? 0 : $count;
    }

    private static function trimUnicodeWhitespace(string $value): string
    {
        $trimmed = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $value);
        return null === $trimmed ? $value : $trimmed;
    }
}
