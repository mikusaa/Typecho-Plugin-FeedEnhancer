<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Feed;

use Typecho\Db;
use Typecho\Db\Exception;
use Typecho\Db\Query;
use TypechoPlugin\FeedEnhancer\Runtime\RequestContext;
use Widget\Comments\Recent;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Recent comments widget whose global-feed query filters parent visibility
 * before applying LIMIT. Per-content comment feeds retain Typecho semantics.
 */
final class SecureRecentComments extends Recent
{
    /**
     * @throws Exception
     */
    public function execute()
    {
        $context = RequestContext::current();
        $isGlobalFeed = null !== $context && $context->isGlobalComments();

        if (!$isGlobalFeed || (int) $this->parameter->parentId > 0) {
            parent::execute();
            return;
        }

        $select = self::globalFeedSelect(
            $this->db,
            (int) $this->options->time,
            (int) $this->parameter->pageSize,
            (bool) $this->options->commentsShowCommentOnly,
            (bool) $this->parameter->ignoreAuthor
        );

        $this->db->fetchAll($select, [$this, 'push']);
    }

    private static function globalFeedSelect(
        Db $db,
        int $siteTime,
        int $pageSize,
        bool $commentOnly,
        bool $ignoreAuthor
    ): Query {
        $select = $db->select('table.comments.*')
            ->from('table.comments')
            ->join(
                'table.contents',
                'table.comments.cid = table.contents.cid',
                Db::INNER_JOIN
            )
            ->where('table.contents.type IN ?', ['post', 'page', 'attachment'])
            ->where('table.contents.status = ?', 'publish')
            ->where('table.contents.created < ?', $siteTime)
            ->where("table.contents.password IS NULL OR table.contents.password = ''")
            ->where('table.contents.allowFeed = ?', 1)
            ->where('table.comments.status = ?', 'approved');

        if ($commentOnly) {
            $select->where('table.comments.type = ?', 'comment');
        }

        if ($ignoreAuthor) {
            $select->where('table.comments.ownerId <> table.comments.authorId');
        }

        return $select
            ->order('table.comments.coid', Db::SORT_DESC)
            ->limit($pageSize);
    }
}
