<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Feed;

use DOMDocument;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Selects one image without probing remote URLs or rebuilding feed content.
 */
final class MediaResolver
{
    /** @var string[] */
    private array $fieldNames;
    private UrlPolicy $urlPolicy;

    /** @param string[] $fieldNames */
    public function __construct(
        array $fieldNames = ['banner', 'cover', 'thumbnail'],
        ?UrlPolicy $urlPolicy = null
    ) {
        $this->fieldNames = $this->normalizeFieldNames($fieldNames);
        $this->urlPolicy = $urlPolicy ?? new UrlPolicy();
    }

    /** @param array<string,mixed> $item */
    public function resolve(
        array $item,
        string $html,
        string $baseUrl,
        ?string $htmlBaseUrl = null
    ): ?string {
        $fields = isset($item['fields']) && is_array($item['fields']) ? $item['fields'] : [];
        foreach ($this->fieldNames as $fieldName) {
            foreach ($this->fieldValues($fields, $fieldName) as $value) {
                $url = $this->resolveUrl($value, $baseUrl);
                if (null !== $url) {
                    return $url;
                }
            }
        }

        $source = $this->firstImageSource($html);
        if (null !== $source) {
            $url = $this->resolveUrl($source, $htmlBaseUrl ?? $baseUrl);
            if (null !== $url) {
                return $url;
            }
        }

        $attachments = isset($item['attachments']) && is_array($item['attachments'])
            ? array_values($item['attachments'])
            : [];
        usort($attachments, static function ($left, $right): int {
            $dateOrder = self::attachmentDate($left) <=> self::attachmentDate($right);
            return 0 !== $dateOrder
                ? $dateOrder
                : self::attachmentId($left) <=> self::attachmentId($right);
        });

        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || !$this->isImageAttachment($attachment)) {
                continue;
            }

            $candidate = $attachment['url']
                ?? $attachment['permalink']
                ?? $attachment['path']
                ?? null;
            if (!is_scalar($candidate)) {
                continue;
            }

            $url = $this->resolveUrl((string) $candidate, $baseUrl);
            if (null !== $url) {
                return $url;
            }
        }

        return null;
    }

    public function resolveUrl(string $candidate, string $baseUrl): ?string
    {
        return $this->urlPolicy->resolve($candidate, $baseUrl);
    }

    /** @param mixed[] $fields @return string[] */
    private function fieldValues(array $fields, string $fieldName): array
    {
        $value = $fields[$fieldName] ?? null;
        if (null === $value) {
            foreach ($fields as $field) {
                if (is_array($field) && (string) ($field['name'] ?? '') === $fieldName) {
                    $value = $field['value'] ?? $field['str_value'] ?? null;
                    break;
                }
            }
        }

        $values = is_array($value) ? $value : [$value];
        $result = [];
        foreach ($values as $entry) {
            if (is_scalar($entry)) {
                $result[] = (string) $entry;
            }
        }

        return $result;
    }

    private function firstImageSource(string $html): ?string
    {
        if ('' === $html || !class_exists(DOMDocument::class)) {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<html><head><meta charset="UTF-8"></head><body>'
                    . '<div id="feed-enhancer-root">' . $html . '</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return null;
        }

        $images = $document->getElementsByTagName('img');
        if (0 === $images->length) {
            return null;
        }

        $image = $images->item(0);
        if (null === $image) {
            return null;
        }

        $source = trim($image->getAttribute('src'));
        return '' === $source ? null : $source;
    }

    /** @param mixed $attachment */
    private static function attachmentDate($attachment): int
    {
        if (!is_array($attachment)) {
            return PHP_INT_MAX;
        }

        $value = $attachment['created'] ?? $attachment['date'] ?? PHP_INT_MAX;
        if (is_int($value) || ctype_digit((string) $value)) {
            return (int) $value;
        }

        $stamp = is_string($value) ? strtotime($value) : false;
        return false === $stamp ? PHP_INT_MAX : $stamp;
    }

    /** @param mixed $attachment */
    private static function attachmentId($attachment): int
    {
        if (!is_array($attachment)) {
            return PHP_INT_MAX;
        }

        $value = $attachment['id'] ?? $attachment['cid'] ?? PHP_INT_MAX;
        return is_int($value) || ctype_digit((string) $value)
            ? (int) $value
            : PHP_INT_MAX;
    }

    /** @param array<string,mixed> $attachment */
    private function isImageAttachment(array $attachment): bool
    {
        $mime = strtolower((string) ($attachment['mime'] ?? $attachment['mimeType'] ?? ''));
        if (0 === strpos($mime, 'image/')) {
            return true;
        }

        $type = strtolower((string) ($attachment['type'] ?? ''));
        if (in_array($type, ['avif', 'bmp', 'gif', 'jpeg', 'jpg', 'png', 'svg', 'tiff', 'webp'], true)) {
            return true;
        }

        $candidate = (string) ($attachment['url']
            ?? $attachment['permalink']
            ?? $attachment['path']
            ?? '');
        $path = parse_url($candidate, PHP_URL_PATH);

        return is_string($path)
            && 1 === preg_match('/\\.(?:avif|bmp|gif|jpe?g|png|svg|tiff|webp)$/iD', $path);
    }

    /** @param mixed[] $fieldNames @return string[] */
    private function normalizeFieldNames(array $fieldNames): array
    {
        $result = [];
        foreach ($fieldNames as $name) {
            if (!is_scalar($name)) {
                continue;
            }

            $name = trim((string) $name);
            if ('' !== $name && !in_array($name, $result, true)) {
                $result[] = $name;
            }

            if (10 === count($result)) {
                break;
            }
        }

        return $result;
    }
}
