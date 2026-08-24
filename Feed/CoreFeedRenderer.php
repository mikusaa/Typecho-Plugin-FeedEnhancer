<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Feed;

use Typecho\Common;
use Typecho\Widget;
use Typecho\Widget\Request;
use Typecho\Widget\Response;
use TypechoPlugin\FeedEnhancer\Runtime\RequestContext;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Invokes the real Typecho feed widget by composition and captures its XML.
 */
final class CoreFeedRenderer
{
    private Request $request;
    private Response $response;

    /** @var mixed */
    private $params;

    /**
     * @param mixed $params
     */
    public function __construct(Request $request, Response $response, $params = null)
    {
        $this->request = $request;
        $this->response = $response;
        $this->params = $params;
    }

    /**
     * @throws \Throwable
     */
    public function render(RequestContext $context): string
    {
        Widget::destroy(Common::nativeClassName(\Widget\Comments\Recent::class));
        Widget::alias(\Widget\Comments\Recent::class, SecureRecentComments::class);

        $level = ob_get_level();
        ob_start();

        try {
            // Deliberately bypass Widget::widget(): the alias applies only to
            // factory allocation, so direct construction reaches core Typecho.
            $feed = new \Widget\Feed($this->request, $this->response, $this->params);
            $feed->execute();
            $feed->render();

            $xml = ob_get_clean();
            return false === $xml ? '' : $xml;
        } catch (\Throwable $exception) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $exception;
        } finally {
            $context->leave();
        }
    }
}
