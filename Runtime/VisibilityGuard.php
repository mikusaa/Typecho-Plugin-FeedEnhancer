<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Runtime;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Narrows Typecho's Archive query to an anonymous public feed view.
 */
final class VisibilityGuard
{
    /**
     * Typecho Widget\Archive::handleInit hook.
     *
     * @param mixed $archive
     * @param mixed $select
     */
    public static function narrowArchive($archive, $select): void
    {
        $context = RequestContext::current();
        if (null === $context || !is_object($archive) || !method_exists($archive, 'is')) {
            return;
        }

        if (!$archive->is('feed')) {
            return;
        }

        if (!is_object($select) || !method_exists($select, 'where')) {
            throw new \RuntimeException('FeedEnhancer cannot apply the mandatory feed visibility policy.');
        }

        $select
            ->where('table.contents.allowFeed = ?', 1)
            ->where("table.contents.password IS NULL OR table.contents.password = ''")
            ->where('table.contents.created < ?', self::siteTime());

        if ($context->isSingleArchive($archive)) {
            $select->where(
                'table.contents.status = ? OR table.contents.status = ?',
                'publish',
                'hidden'
            );
        } else {
            $select->where('table.contents.status = ?', 'publish');
        }
    }

    private static function siteTime(): int
    {
        try {
            $time = (int) \Widget\Options::alloc()->time;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'FeedEnhancer cannot resolve the mandatory feed visibility time.',
                0,
                $exception
            );
        }

        if ($time <= 0) {
            throw new \RuntimeException(
                'FeedEnhancer received an invalid mandatory feed visibility time.'
            );
        }

        return $time;
    }
}
