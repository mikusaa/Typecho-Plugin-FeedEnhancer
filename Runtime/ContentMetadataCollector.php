<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Runtime;

use Typecho\Config;
use Typecho\Db;
use Widget\Archive;
use Widget\Upload;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Observes final feed content without changing it and hydrates media metadata
 * only after Typecho has finished producing the native XML.
 */
final class ContentMetadataCollector
{
    private const IMAGE_TYPES = [
        'jpg', 'jpeg', 'gif', 'png', 'tiff', 'bmp', 'webp', 'avif'
    ];

    /** @var string[] */
    private array $fieldNames;

    /** @var array<int,array<string,mixed>> */
    private array $items = [];

    private bool $hydrated = false;

    /**
     * @param string[] $fieldNames
     */
    public function __construct(array $fieldNames = [])
    {
        $normalized = [];
        foreach ($fieldNames as $name) {
            $name = trim((string) $name);
            if ('' !== $name && !in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        $this->fieldNames = array_slice($normalized, 0, 10);
    }

    /**
     * Transparent Widget_Abstract_Contents::contentEx hook.
     *
     * @param mixed $content
     * @param mixed $widget
     * @param mixed $lastResult
     * @return mixed
     */
    public static function observe($content, $widget, $lastResult = null)
    {
        $result = null !== $lastResult ? $lastResult : $content;
        $context = RequestContext::current();

        if (null !== $context) {
            $context->metadata()->capture($widget);
        }

        return $result;
    }

    /**
     * Record only identifiers and timestamps in the content hook. Custom fields
     * and attachments are loaded in batches later and never influence content.
     *
     * @param mixed $widget
     */
    public function capture($widget): void
    {
        $context = RequestContext::current();
        if (!$widget instanceof Archive || null === $context || !$widget->is('feed')) {
            return;
        }

        $status = (string) $widget->status;
        if ('publish' !== $status && !('hidden' === $status && $context->isSingleArchive($widget))) {
            return;
        }

        if (!in_array((string) $widget->type, ['post', 'page', 'attachment'], true)) {
            return;
        }

        $cid = (int) $widget->cid;
        $created = (int) $widget->created;
        if (
            $cid <= 0
            || 1 !== (int) $widget->allowFeed
            || '' !== (string) $widget->password
            || $created >= self::siteTime()
        ) {
            return;
        }

        $permalink = (string) $widget->permalink;
        $this->items[$cid] = [
            'id' => $cid,
            'cid' => $cid,
            'link' => $permalink,
            'permalink' => $permalink,
            'created' => $created,
            'modified' => max($created, (int) $widget->modified),
            'fields' => [],
            'attachments' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function items(bool $hydrateMedia = true): array
    {
        if ($hydrateMedia && !$this->hydrated) {
            $this->hydrated = true;
            $this->hydrateMediaInputs();
        }

        return array_values($this->items);
    }

    private function hydrateMediaInputs(): void
    {
        if ([] === $this->items) {
            return;
        }

        try {
            $db = Db::get();
            $cids = array_keys($this->items);

            if ([] !== $this->fieldNames) {
                $fields = $db->fetchAll(
                    $db->select('table.fields.*')
                        ->from('table.fields')
                        ->where('table.fields.cid IN ?', $cids)
                        ->where('table.fields.name IN ?', $this->fieldNames)
                );

                foreach ($fields as $field) {
                    $cid = (int) ($field['cid'] ?? 0);
                    $name = (string) ($field['name'] ?? '');
                    if (!isset($this->items[$cid]) || !in_array($name, $this->fieldNames, true)) {
                        continue;
                    }

                    $this->items[$cid]['fields'][$name] = self::fieldValue($field);
                }
            }

            $attachments = $db->fetchAll(
                $db->select(
                    'table.contents.cid',
                    'table.contents.parent',
                    'table.contents.created',
                    'table.contents.text'
                )
                    ->from('table.contents')
                    ->where('table.contents.parent IN ?', $cids)
                    ->where('table.contents.type = ?', 'attachment')
                    ->where('table.contents.status = ?', 'publish')
                    ->where('table.contents.allowFeed = ?', 1)
                    ->where("table.contents.password IS NULL OR table.contents.password = ''")
                    ->where('table.contents.created < ?', self::siteTime())
                    ->order('table.contents.created', Db::SORT_ASC)
                    ->order('table.contents.cid', Db::SORT_ASC)
            );

            foreach ($attachments as $attachment) {
                $parent = (int) ($attachment['parent'] ?? 0);
                if (
                    !isset($this->items[$parent])
                    || [] !== $this->items[$parent]['attachments']
                ) {
                    continue;
                }

                $data = json_decode((string) ($attachment['text'] ?? ''), true);
                if (!is_array($data) || !self::isImageAttachment($data)) {
                    continue;
                }

                $url = Upload::attachmentHandle(new Config($data));
                $this->items[$parent]['attachments'][] = [
                    'id' => (int) ($attachment['cid'] ?? 0),
                    'url' => $url,
                    'path' => (string) ($data['path'] ?? ''),
                    'created' => (int) ($attachment['created'] ?? 0),
                    'mime' => (string) ($data['mime'] ?? ''),
                    'type' => (string) ($data['type'] ?? ''),
                ];
            }
        } catch (\Throwable $exception) {
            error_log('[FeedEnhancer] Media metadata lookup failed: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $field
     * @return mixed
     */
    private static function fieldValue(array $field)
    {
        $type = (string) ($field['type'] ?? 'str');
        if ('json' === $type) {
            $value = $field['str_value'] ?? null;
            if (!is_string($value)) {
                return null;
            }

            $decoded = json_decode($value, true);
            return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
        }

        return $field[$type . '_value'] ?? null;
    }

    /**
     * @param array<string,mixed> $attachment
     */
    private static function isImageAttachment(array $attachment): bool
    {
        $type = strtolower((string) ($attachment['type'] ?? ''));
        $mime = strtolower((string) ($attachment['mime'] ?? ''));

        return in_array($type, self::IMAGE_TYPES, true) || 0 === strpos($mime, 'image/');
    }

    private static function siteTime(): int
    {
        try {
            return (int) \Widget\Options::alloc()->time;
        } catch (\Throwable $exception) {
            return 0;
        }
    }
}
