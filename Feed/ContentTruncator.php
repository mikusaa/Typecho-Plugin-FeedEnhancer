<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Feed;

use DOMDocument;
use DOMElement;
use DOMNode;
use TypechoPlugin\FeedEnhancer\Runtime\RequestContext;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Replaces article feed content with a short, plain-text lead and permalink.
 */
final class ContentTruncator
{
    /**
     * Transparent Widget_Abstract_Contents::contentEx hook.
     *
     * @param mixed $content
     * @param mixed $widget
     * @param mixed $lastResult
     * @return mixed
     */
    public static function content($content, $widget, $lastResult = null)
    {
        $result = null !== $lastResult ? $lastResult : $content;
        $context = self::applicableContext($widget);
        if (null === $context) {
            return $result;
        }

        $html = is_string($result) ? $result : '';
        return self::renderTeaser($html, $context->feedContentLength())
            . self::renderMoreLink($widget, $context->feedReadMoreText());
    }

    /**
     * Transparent Widget_Abstract_Contents::excerptEx hook.
     *
     * @param mixed $content
     * @param mixed $widget
     * @param mixed $lastResult
     * @return mixed
     */
    public static function excerpt($content, $widget, $lastResult = null)
    {
        $result = null !== $lastResult ? $lastResult : $content;
        $context = self::applicableContext($widget);
        if (null === $context) {
            return $result;
        }

        return self::renderTeaser(
            is_string($result) ? $result : '',
            $context->feedContentLength()
        );
    }

    /** @param mixed $widget */
    private static function applicableContext($widget): ?RequestContext
    {
        $context = RequestContext::current();
        if (
            null === $context
            || !$context->contentTruncationEnabled()
            || $context->isGlobalComments()
            || !is_object($widget)
            || !method_exists($widget, 'is')
        ) {
            return null;
        }

        try {
            if (!$widget->is('feed') || $widget->is('single') || $context->isSingleArchive($widget)) {
                return null;
            }
        } catch (\Throwable $exception) {
            return null;
        }

        return $context;
    }

    private static function renderTeaser(string $html, int $maximumLength): string
    {
        $text = self::extractLeadText($html, $maximumLength);
        if ('' === $text) {
            return '';
        }

        return '<p>' . self::escape(self::truncate($text, $maximumLength)) . '</p>';
    }

    /** @param mixed $widget */
    private static function renderMoreLink($widget, string $label): string
    {
        try {
            $url = $widget->permalink;
        } catch (\Throwable $exception) {
            return '';
        }

        if (!is_string($url) || !(new UrlPolicy())->isSafeAbsolute($url)) {
            return '';
        }

        return '<p class="more"><a href="' . self::escape($url) . '">'
            . self::escape($label) . '</a></p>';
    }

    private static function extractLeadText(string $html, int $maximumLength): string
    {
        if ('' === $html || !class_exists(DOMDocument::class)) {
            return '';
        }

        $root = self::parseFragment($html);
        if (null === $root) {
            return '';
        }

        $blocks = [];
        $length = 0;
        $moreBoundaryReached = false;
        self::collectLeadBlocks(
            $root,
            $blocks,
            $length,
            $maximumLength,
            $moreBoundaryReached
        );

        return [] === $blocks ? self::visibleText($root) : implode(' ', $blocks);
    }

    private static function parseFragment(string $html): ?DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
                    . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } catch (\Throwable $exception) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            return null;
        }

        $body = $document->getElementsByTagName('body')->item(0);
        return $body instanceof DOMElement ? $body : null;
    }

    /**
     * @param string[] $blocks
     */
    private static function collectLeadBlocks(
        DOMNode $parent,
        array &$blocks,
        int &$length,
        int $maximumLength,
        bool &$moreBoundaryReached
    ): bool {
        foreach ($parent->childNodes as $child) {
            if (self::isMoreMarker($child)) {
                $moreBoundaryReached = true;
                return false;
            }

            if (!$child instanceof DOMElement || self::ignoredElement($child)) {
                continue;
            }

            $name = strtolower($child->localName);
            if (in_array($name, ['p', 'blockquote', 'ul', 'ol'], true)) {
                $text = in_array($name, ['ul', 'ol'], true)
                    ? self::listText($child, $moreBoundaryReached)
                    : self::visibleTextWithBoundary($child, $moreBoundaryReached);
                if ('' !== $text) {
                    if ([] !== $blocks) {
                        ++$length;
                    }

                    $blocks[] = $text;
                    $length += self::unicodeLength($text);
                }

                if ($moreBoundaryReached || $length > $maximumLength) {
                    return false;
                }

                continue;
            }

            if (
                !self::collectLeadBlocks(
                    $child,
                    $blocks,
                    $length,
                    $maximumLength,
                    $moreBoundaryReached
                )
            ) {
                return false;
            }
        }

        return true;
    }

    private static function listText(DOMElement $list, bool &$moreBoundaryReached): string
    {
        $items = [];
        self::collectListItems($list, $items, $moreBoundaryReached);
        $items = array_values(array_filter($items, static function (string $item): bool {
            return '' !== $item;
        }));

        return [] === $items
            ? self::visibleTextWithBoundary($list, $moreBoundaryReached)
            : implode("\xEF\xBC\x9B", $items);
    }

    /** @param string[] $items */
    private static function collectListItems(
        DOMNode $parent,
        array &$items,
        bool &$moreBoundaryReached
    ): void {
        foreach ($parent->childNodes as $child) {
            if (self::isMoreMarker($child)) {
                $moreBoundaryReached = true;
                return;
            }

            if (!$child instanceof DOMElement || self::ignoredElement($child)) {
                continue;
            }

            if ('li' === strtolower($child->localName)) {
                $items[] = self::listItemText($child, $moreBoundaryReached);
                if ($moreBoundaryReached) {
                    return;
                }
            }

            self::collectListItems($child, $items, $moreBoundaryReached);
            if ($moreBoundaryReached) {
                return;
            }
        }
    }

    private static function listItemText(DOMElement $item, bool &$moreBoundaryReached): string
    {
        $text = '';
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->localName), ['ul', 'ol'], true)) {
                continue;
            }

            self::appendVisibleText($child, $text, $moreBoundaryReached);
            if ($moreBoundaryReached) {
                break;
            }
        }

        return self::normalizeText($text);
    }

    private static function visibleText(DOMNode $node): string
    {
        $moreBoundaryReached = false;
        return self::visibleTextWithBoundary($node, $moreBoundaryReached);
    }

    private static function visibleTextWithBoundary(
        DOMNode $node,
        bool &$moreBoundaryReached
    ): string {
        $text = '';
        foreach ($node->childNodes as $child) {
            self::appendVisibleText($child, $text, $moreBoundaryReached);
            if ($moreBoundaryReached) {
                break;
            }
        }

        return self::normalizeText($text);
    }

    private static function appendVisibleText(
        DOMNode $node,
        string &$text,
        bool &$moreBoundaryReached
    ): void {
        if ($moreBoundaryReached) {
            return;
        }

        if (self::isMoreMarker($node)) {
            $moreBoundaryReached = true;
            return;
        }

        if (XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType) {
            $text .= $node->nodeValue ?? '';
            return;
        }

        if (!$node instanceof DOMElement || self::ignoredElement($node)) {
            return;
        }

        $separator = self::isTextSeparator(strtolower($node->localName));
        if ($separator) {
            $text .= ' ';
        }

        foreach ($node->childNodes as $child) {
            self::appendVisibleText($child, $text, $moreBoundaryReached);
            if ($moreBoundaryReached) {
                break;
            }
        }

        if ($separator) {
            $text .= ' ';
        }
    }

    private static function isMoreMarker(DOMNode $node): bool
    {
        return XML_COMMENT_NODE === $node->nodeType
            && 0 === strcasecmp(trim($node->nodeValue ?? ''), 'more');
    }

    private static function ignoredElement(DOMElement $element): bool
    {
        $name = strtolower($element->localName);
        if (
            in_array($name, [
                'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                'figure', 'picture', 'video', 'audio', 'svg', 'canvas', 'object', 'embed', 'map',
                'pre', 'code', 'script', 'style', 'template', 'iframe', 'form', 'noscript',
                'textarea', 'select', 'button',
            ], true)
            || $element->hasAttribute('hidden')
            || ('details' === $name && !$element->hasAttribute('open'))
        ) {
            return true;
        }

        $classes = preg_split('/\s+/u', trim($element->getAttribute('class')));
        return is_array($classes) && in_array('more', $classes, true);
    }

    private static function isTextSeparator(string $name): bool
    {
        return in_array($name, [
            'br', 'p', 'blockquote', 'ul', 'ol', 'li', 'div', 'section', 'article',
            'header', 'footer', 'aside', 'tr', 'td', 'th', 'dt', 'dd', 'hr',
        ], true);
    }

    private static function normalizeText(string $text): string
    {
        $normalized = preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        return trim(null === $normalized ? $text : $normalized);
    }

    private static function unicodeLength(string $text): int
    {
        $count = preg_match_all('/./us', $text, $matches);
        return false === $count ? 0 : $count;
    }

    private static function truncate(string $text, int $maximumLength): string
    {
        preg_match_all('/./us', $text, $matches);
        $characters = $matches[0] ?? [];
        if (count($characters) <= $maximumLength) {
            return $text;
        }

        if ($maximumLength <= 3) {
            return substr('...', 0, max(0, $maximumLength));
        }

        return implode('', array_slice($characters, 0, $maximumLength - 3)) . '...';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
