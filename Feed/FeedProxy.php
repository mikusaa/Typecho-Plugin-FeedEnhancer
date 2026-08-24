<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Feed;

use Typecho\Widget;
use TypechoPlugin\FeedEnhancer\Http\ConditionalResponse;
use TypechoPlugin\FeedEnhancer\Http\StylesheetResponder;
use TypechoPlugin\FeedEnhancer\Plugin;
use TypechoPlugin\FeedEnhancer\Runtime\RequestContext;
use TypechoPlugin\FeedEnhancer\Runtime\Settings;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Request widget installed as a runtime alias for Typecho's native Feed.
 */
final class FeedProxy extends Widget
{
    private const MANAGED_RESPONSE_HEADERS = [
        'allow',
        'cache-control',
        'content-length',
        'content-type',
        'etag',
        'expires',
        'last-modified',
        'pragma',
        'vary',
    ];

    private string $body = '';

    /**
     * @throws \Throwable
     */
    public function execute()
    {
        $settings = Settings::load();
        $method = strtoupper((string) $this->request->getServer('REQUEST_METHOD', 'GET'));
        $ifNoneMatch = $this->request->getHeader('If-None-Match');
        $loggedIn = (bool) \Widget\User::alloc()->hasLogin();
        $feedPath = (string) $this->request->get('feed', '/');
        $context = RequestContext::enter($feedPath, $settings->mediaFieldNames());
        $conditional = new ConditionalResponse();

        if ($this->isStylesheetRequest($context, $settings)) {
            $context->leave();
            $stylesheet = new StylesheetResponder(null, $conditional);
            $prepared = $stylesheet->serve($method, $ifNoneMatch, $loggedIn);
            $this->applyPreparedResponse($prepared);
            return;
        }

        $contentType = $context->contentType(
            $settings->stylesheetEnabled() && $settings->safariXmlMime()
        );

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $context->leave();
            $prepared = $conditional->prepare(
                $method,
                '',
                $ifNoneMatch,
                $loggedIn,
                $contentType
            );
            $this->applyPreparedResponse($prepared);
            return;
        }

        try {
            $renderer = new CoreFeedRenderer($this->request, $this->response, $this->parameter);
            $xml = $renderer->render($context);
        } catch (\Throwable $exception) {
            $context->leave();
            throw $exception;
        }

        $stylesheetUrl = null;
        if (
            $settings->stylesheetEnabled()
            && RequestContext::FORMAT_RSS2 === $context->format()
        ) {
            $stylesheetUrl = StylesheetResponder::buildUrl(
                (string) \Widget\Options::alloc()->feedUrl,
                Plugin::VERSION
            );
        }

        $pipeline = new XmlPipeline(new MediaResolver($settings->mediaFieldNames()));
        $body = $pipeline->enhance(
            $xml,
            $context->metadata()->items($settings->mediaEnabled()),
            $settings->mediaEnabled(),
            $stylesheetUrl
        );

        $prepared = $conditional->prepare(
            $method,
            $body,
            $ifNoneMatch,
            $loggedIn,
            $contentType
        );
        $this->applyPreparedResponse($prepared);
    }

    public function render(): void
    {
        echo $this->body;
    }

    private function isStylesheetRequest(RequestContext $context, Settings $settings): bool
    {
        if (
            !$settings->stylesheetEnabled()
            || RequestContext::FORMAT_RSS2 !== $context->format()
            || '/' !== $context->contentPath()
        ) {
            return false;
        }

        $responder = new StylesheetResponder();
        return $responder->isRequested([
            'feed-enhancer-stylesheet' => $this->request->get('feed-enhancer-stylesheet'),
            'v' => $this->request->get('v'),
        ]);
    }

    /**
     * @param array<string,mixed> $prepared
     */
    private function applyPreparedResponse(array $prepared): void
    {
        $this->removeQueuedResponseHeaders();

        if (function_exists('header_remove') && !headers_sent()) {
            // PHP's session cache limiter runs before index.php:begin and can
            // otherwise suppress the explicit feed cache policy at send time.
            foreach (self::MANAGED_RESPONSE_HEADERS as $name) {
                header_remove($name);
            }
        }

        $this->response->setStatus((int) ($prepared['status'] ?? 500));

        $headers = $prepared['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (is_string($name) && (is_scalar($value) || null === $value)) {
                    $this->response->setHeader($name, (string) $value);
                }
            }
        }

        $body = $prepared['body'] ?? '';
        $this->body = is_string($body) ? $body : (string) $body;
    }

    private function removeQueuedResponseHeaders(): void
    {
        $response = \Typecho\Response::getInstance();
        $class = new \ReflectionClass($response);

        if (!$class->hasProperty('headers')) {
            throw new \RuntimeException('Unable to access Typecho response headers.');
        }

        $property = $class->getProperty('headers');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }

        $headers = $property->getValue($response);
        if (!is_array($headers)) {
            throw new \RuntimeException('Typecho response headers have an unexpected format.');
        }

        $managed = array_fill_keys(self::MANAGED_RESPONSE_HEADERS, true);

        foreach (array_keys($headers) as $name) {
            if (is_string($name) && isset($managed[strtolower($name)])) {
                unset($headers[$name]);
            }
        }

        $property->setValue($response, $headers);
    }
}
