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
        $text = self::extractLeadText($html);
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

    private static function extractLeadText(string $html): string
    {
        if ('' === $html || !class_exists(DOMDocument::class)) {
            return '';
        }

        $root = self::parseFragment($html);
        if (null === $root) {
            return '';
        }

        $candidate = self::firstCandidate($root);
        return null === $candidate ? self::visibleText($root) : $candidate;
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

    private static function firstCandidate(DOMNode $parent): ?string
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement || self::ignoredElement($child)) {
                continue;
            }

            $name = strtolower($child->localName);
            if (in_array($name, ['p', 'blockquote', 'ul', 'ol'], true)) {
                $text = in_array($name, ['ul', 'ol'], true)
                    ? self::listText($child)
                    : self::visibleText($child);
                if ('' !== $text) {
                    return $text;
                }

                continue;
            }

            $candidate = self::firstCandidate($child);
            if (null !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    private static function listText(DOMElement $list): string
    {
        $items = [];
        self::collectListItems($list, $items);
        $items = array_values(array_filter($items, static function (string $item): bool {
            return '' !== $item;
        }));

        return [] === $items ? self::visibleText($list) : implode("\xEF\xBC\x9B", $items);
    }

    /** @param string[] $items */
    private static function collectListItems(DOMNode $parent, array &$items): void
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement || self::ignoredElement($child)) {
                continue;
            }

            if ('li' === strtolower($child->localName)) {
                $items[] = self::listItemText($child);
            }

            self::collectListItems($child, $items);
        }
    }

    private static function listItemText(DOMElement $item): string
    {
        $text = '';
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->localName), ['ul', 'ol'], true)) {
                continue;
            }

            self::appendVisibleText($child, $text);
        }

        return self::normalizeText($text);
    }

    private static function visibleText(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            self::appendVisibleText($child, $text);
        }

        return self::normalizeText($text);
    }

    private static function appendVisibleText(DOMNode $node, string &$text): void
    {
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
            self::appendVisibleText($child, $text);
        }

        if ($separator) {
            $text .= ' ';
        }
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
