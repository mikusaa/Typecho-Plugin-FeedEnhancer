<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Feed;

use DOMDocument;
use DOMElement;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Applies protocol enhancements to Typecho's already rendered feed XML.
 */
final class XmlPipeline
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const MEDIA_NS = 'http://search.yahoo.com/mrss/';
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const RSS1_NS = 'http://purl.org/rss/1.0/';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
    private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

    private MediaResolver $mediaResolver;

    public function __construct(?MediaResolver $mediaResolver = null)
    {
        $this->mediaResolver = $mediaResolver ?? new MediaResolver();
    }

    /**
     * @param array<int,array<string,mixed>> $metadataItems
     */
    public function enhance(
        string $xml,
        array $metadataItems = [],
        bool $mediaEnabled = true,
        ?string $stylesheetUrl = null
    ): string {
        if (!class_exists(DOMDocument::class)) {
            $this->diagnose('DOM is unavailable');
            return $xml;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);

        try {
            libxml_clear_errors();
            $loaded = $document->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } catch (\Throwable $exception) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            $this->diagnose('malformed XML');
            return $xml;
        }

        if (null !== $document->doctype) {
            $this->diagnose('DOCTYPE is not allowed');
            return $xml;
        }

        $protocol = $this->protocol($document->documentElement);
        if (null === $protocol) {
            return $xml;
        }

        try {
            $metadata = $this->metadataIndex($metadataItems);
            $changed = false;

            if ('atom' === $protocol) {
                $changed = $this->updateAtomTimes($document, $metadata) || $changed;
            }

            if (
                $mediaEnabled
                || ('rss2' === $protocol && null !== $stylesheetUrl && '' !== trim($stylesheetUrl))
            ) {
                $changed = $this->processMedia(
                    $document,
                    $protocol,
                    $metadata,
                    $mediaEnabled
                ) || $changed;
            }

            if ('rss2' === $protocol && null !== $stylesheetUrl && '' !== trim($stylesheetUrl)) {
                $changed = $this->addStylesheet($document, trim($stylesheetUrl)) || $changed;
            }

            if (!$changed) {
                return $xml;
            }

            $result = $document->saveXML();
            if (false === $result) {
                throw new \RuntimeException('DOM serialization failed');
            }

            return $result;
        } catch (\Throwable $exception) {
            $this->diagnose('DOM enhancement failed');
            return $xml;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array<string,mixed>>
     */
    private function metadataIndex(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $link = $item['permalink'] ?? $item['link'] ?? null;
            if (!is_scalar($link) || '' === trim((string) $link)) {
                continue;
            }

            $link = trim((string) $link);
            if (!isset($index[$link])) {
                $index[$link] = $item;
            }
        }

        return $index;
    }

    /**
     * @param array<string,array<string,mixed>> $metadata
     */
    private function updateAtomTimes(DOMDocument $document, array $metadata): bool
    {
        $root = $document->documentElement;
        if (!$root instanceof DOMElement) {
            return false;
        }

        $changed = false;
        $entries = $this->directChildren($root, 'entry', self::ATOM_NS);

        foreach ($entries as $entry) {
            $link = $this->entryLink($entry, 'atom');
            if (null === $link || !isset($metadata[$link])) {
                continue;
            }

            $item = $metadata[$link];
            $created = $this->timestamp($item['created'] ?? null);
            $modified = $this->timestamp($item['modified'] ?? null);
            if (null === $created) {
                $published = $this->firstDirectChild($entry, 'published', self::ATOM_NS);
                $created = null === $published ? null : $this->timestamp(trim($published->textContent));
            }

            if (null === $created && null === $modified) {
                continue;
            }

            $updatedStamp = max($created ?? PHP_INT_MIN, $modified ?? PHP_INT_MIN);
            $updatedValue = date(DATE_ATOM, $updatedStamp);
            $updated = $this->firstDirectChild($entry, 'updated', self::ATOM_NS);

            if (null === $updated) {
                $updated = $document->createElementNS(self::ATOM_NS, 'updated', $updatedValue);
                $published = $this->firstDirectChild($entry, 'published', self::ATOM_NS);
                $entry->insertBefore($updated, $published);
                $changed = true;
            } elseif (trim($updated->textContent) !== $updatedValue) {
                $this->replaceText($updated, $updatedValue);
                $changed = true;
            }
        }

        $maximumStamp = null;
        $maximumValue = null;
        foreach ($entries as $entry) {
            $updated = $this->firstDirectChild($entry, 'updated', self::ATOM_NS);
            if (null === $updated) {
                continue;
            }

            $value = trim($updated->textContent);
            $stamp = $this->timestamp($value);
            if (null !== $stamp && (null === $maximumStamp || $stamp > $maximumStamp)) {
                $maximumStamp = $stamp;
                $maximumValue = $value;
            }
        }

        if (null !== $maximumValue) {
            $feedUpdated = $this->firstDirectChild($root, 'updated', self::ATOM_NS);
            if (null === $feedUpdated) {
                $feedUpdated = $document->createElementNS(self::ATOM_NS, 'updated', $maximumValue);
                $firstEntry = 0 < count($entries) ? $entries[0] : null;
                $root->insertBefore($feedUpdated, $firstEntry);
                $changed = true;
            } elseif (trim($feedUpdated->textContent) !== $maximumValue) {
                $this->replaceText($feedUpdated, $maximumValue);
                $changed = true;
            }
        }

        return $changed;
    }

    /** @param mixed $value */
    private function timestamp($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && 1 === preg_match('/^-?[0-9]+$/D', $value))) {
            return (int) $value;
        }

        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $stamp = strtotime($value);
        return false === $stamp ? null : $stamp;
    }

    private function replaceText(DOMElement $element, string $value): void
    {
        while (null !== $element->firstChild) {
            $element->removeChild($element->firstChild);
        }

        $element->appendChild($element->ownerDocument->createTextNode($value));
    }

    /**
     * @param array<string,array<string,mixed>> $metadata
     */
    private function processMedia(
        DOMDocument $document,
        string $protocol,
        array $metadata,
        bool $addMissing
    ): bool {
        $root = $document->documentElement;
        if (!$root instanceof DOMElement) {
            return false;
        }

        $changed = false;
        foreach ($this->feedEntries($root, $protocol) as $entry) {
            $link = $this->entryLink($entry, $protocol);
            if (null === $link) {
                continue;
            }

            [$hasExisting, $sanitized] = $this->sanitizeExistingMedia($entry, $link);
            $changed = $sanitized || $changed;
            if ($hasExisting || !$addMissing || !isset($metadata[$link])) {
                continue;
            }

            $content = $this->contentElement($entry, $protocol);
            $html = null === $content ? '' : $this->elementContent($document, $content);
            $htmlBaseUrl = null === $content ? $link : $this->elementBaseUrl($content, $link);

            $url = $this->mediaResolver->resolve(
                $metadata[$link],
                $html,
                $link,
                $htmlBaseUrl
            );
            if (null === $url) {
                continue;
            }

            if (!$root->hasAttributeNS(self::XMLNS_NS, 'media')) {
                $root->setAttributeNS(self::XMLNS_NS, 'xmlns:media', self::MEDIA_NS);
            }

            $media = $document->createElementNS(self::MEDIA_NS, 'media:content');
            $media->setAttribute('url', $url);
            $media->setAttribute('medium', 'image');
            $thumbnail = $document->createElementNS(self::MEDIA_NS, 'media:thumbnail');
            $thumbnail->setAttribute('url', $url);
            $entry->appendChild($media);
            $entry->appendChild($thumbnail);
            $changed = true;
        }

        return $changed;
    }

    /** @return bool[] */
    private function sanitizeExistingMedia(DOMElement $entry, string $baseUrl): array
    {
        $elements = [];
        foreach (['content', 'thumbnail'] as $localName) {
            foreach ($entry->getElementsByTagNameNS(self::MEDIA_NS, $localName) as $element) {
                if ($element instanceof DOMElement) {
                    $elements[] = $element;
                }
            }
        }

        $hasExisting = false;
        $changed = false;
        foreach ($elements as $element) {
            $elementBase = $this->elementBaseUrl($element, $baseUrl);
            $candidate = trim($element->getAttribute('url'));
            $resolved = null === $elementBase
                ? null
                : $this->mediaResolver->resolveUrl($candidate, $elementBase);

            if (null === $resolved) {
                $element->parentNode->removeChild($element);
                $changed = true;
                continue;
            }

            if ($candidate !== $resolved) {
                $element->setAttribute('url', $resolved);
                $changed = true;
            }
            $hasExisting = true;
        }

        return [$hasExisting, $changed];
    }

    private function elementBaseUrl(DOMElement $element, string $fallback): ?string
    {
        $ancestors = [];
        $current = $element;
        while ($current instanceof DOMElement) {
            $ancestors[] = $current;
            $current = $current->parentNode;
        }

        $baseUrl = $fallback;
        foreach (array_reverse($ancestors) as $ancestor) {
            $xmlBase = trim($ancestor->getAttributeNS(self::XML_NS, 'base'));
            if ('' === $xmlBase) {
                continue;
            }

            $baseUrl = $this->mediaResolver->resolveUrl($xmlBase, $baseUrl);
            if (null === $baseUrl) {
                return null;
            }
        }

        return $baseUrl;
    }

    /** @return DOMElement[] */
    private function feedEntries(DOMElement $root, string $protocol): array
    {
        if ('atom' === $protocol) {
            return $this->directChildren($root, 'entry', self::ATOM_NS);
        }

        if ('rss1' === $protocol) {
            return $this->directChildren($root, 'item', self::RSS1_NS);
        }

        $channel = $this->firstDirectChild($root, 'channel', null);
        return null === $channel ? [] : $this->directChildren($channel, 'item', null);
    }

    private function entryLink(DOMElement $entry, string $protocol): ?string
    {
        if ('atom' === $protocol) {
            foreach ($this->directChildren($entry, 'link', self::ATOM_NS) as $link) {
                $relation = trim($link->getAttribute('rel'));
                $href = trim($link->getAttribute('href'));
                if ('' !== $href && ('' === $relation || 'alternate' === $relation)) {
                    return $href;
                }
            }

            $id = $this->firstDirectChild($entry, 'id', self::ATOM_NS);
            return null === $id || '' === trim($id->textContent) ? null : trim($id->textContent);
        }

        $namespace = 'rss1' === $protocol ? self::RSS1_NS : null;
        $link = $this->firstDirectChild($entry, 'link', $namespace);
        if (null !== $link && '' !== trim($link->textContent)) {
            return trim($link->textContent);
        }

        if ('rss1' === $protocol) {
            $about = trim($entry->getAttributeNS(self::RDF_NS, 'about'));
            return '' === $about ? null : $about;
        }

        $guid = $this->firstDirectChild($entry, 'guid', null);
        return null === $guid || '' === trim($guid->textContent) ? null : trim($guid->textContent);
    }

    private function contentElement(DOMElement $entry, string $protocol): ?DOMElement
    {
        if ('atom' === $protocol) {
            return $this->firstDirectChild($entry, 'content', self::ATOM_NS)
                ?? $this->firstDirectChild($entry, 'summary', self::ATOM_NS);
        }

        if ('rss1' === $protocol) {
            return $this->firstDirectChild($entry, 'description', self::RSS1_NS);
        }

        return $this->firstDirectChild($entry, 'encoded', self::CONTENT_NS)
            ?? $this->firstDirectChild($entry, 'description', null);
    }

    private function elementContent(DOMDocument $document, DOMElement $element): string
    {
        if ('xhtml' !== strtolower(trim($element->getAttribute('type')))) {
            return $element->textContent;
        }

        $content = '';
        foreach ($element->childNodes as $child) {
            $serialized = $document->saveXML($child);
            if (false !== $serialized) {
                $content .= $serialized;
            }
        }

        return $content;
    }

    private function addStylesheet(DOMDocument $document, string $url): bool
    {
        if (
            false !== strpos($url, '?>')
            || 1 === preg_match('/[\\x00-\\x1F\\x7F]/', $url)
        ) {
            return false;
        }

        foreach ($document->childNodes as $node) {
            if (XML_PI_NODE !== $node->nodeType || 'xml-stylesheet' !== $node->nodeName) {
                continue;
            }

            $data = html_entity_decode((string) $node->nodeValue, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (false !== strpos($data, $url) || false !== strpos($data, 'feed-enhancer-stylesheet=1')) {
                return false;
            }
        }

        $escaped = htmlspecialchars(
            $url,
            ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $instruction = $document->createProcessingInstruction(
            'xml-stylesheet',
            'type="text/xsl" href="' . $escaped . '"'
        );
        $document->insertBefore($instruction, $document->documentElement);

        return true;
    }

    /** @return DOMElement[] */
    private function directChildren(DOMElement $parent, string $localName, ?string $namespace): array
    {
        $elements = [];
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement || $localName !== $child->localName) {
                continue;
            }

            $childNamespace = $child->namespaceURI;
            if (
                (null === $namespace && (null === $childNamespace || '' === $childNamespace))
                || (null !== $namespace && $namespace === $childNamespace)
            ) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    private function firstDirectChild(
        DOMElement $parent,
        string $localName,
        ?string $namespace
    ): ?DOMElement {
        $children = $this->directChildren($parent, $localName, $namespace);
        return $children[0] ?? null;
    }

    private function protocol(DOMElement $root): ?string
    {
        if ('feed' === $root->localName && self::ATOM_NS === $root->namespaceURI) {
            return 'atom';
        }

        if ('RDF' === $root->localName && self::RDF_NS === $root->namespaceURI) {
            return 'rss1';
        }

        if ('rss' === $root->localName && (null === $root->namespaceURI || '' === $root->namespaceURI)) {
            return 'rss2';
        }

        return null;
    }

    private function diagnose(string $reason): void
    {
        error_log('[FeedEnhancer] XML enhancement skipped: ' . $reason . '.');
    }
}
