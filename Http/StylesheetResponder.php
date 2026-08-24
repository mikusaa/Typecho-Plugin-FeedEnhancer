<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Http;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Serves the bundled RSS2 preview stylesheet through the existing feed route.
 */
final class StylesheetResponder
{
    private string $assetPath;
    private ConditionalResponse $conditional;

    public function __construct(
        ?string $assetPath = null,
        ?ConditionalResponse $conditional = null
    ) {
        $this->assetPath = $assetPath ?? dirname(__DIR__) . '/assets/feed-preview.xsl';
        $this->conditional = $conditional ?? new ConditionalResponse();
    }

    /**
     * @param array<string,mixed> $query
     */
    public function isRequested(array $query): bool
    {
        $value = $query['feed-enhancer-stylesheet'] ?? null;

        return is_scalar($value) && '1' === (string) $value;
    }

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function serve(
        string $method,
        ?string $ifNoneMatch,
        bool $loggedIn
    ): array {
        $method = strtoupper($method);

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $this->conditional->prepare(
                $method,
                '',
                $ifNoneMatch,
                $loggedIn,
                'application/xslt+xml'
            );
        }

        $stylesheet = file_get_contents($this->assetPath);
        if (false === $stylesheet) {
            throw new \RuntimeException('Unable to read the FeedEnhancer stylesheet.');
        }

        return $this->conditional->prepare(
            $method,
            $stylesheet,
            $ifNoneMatch,
            $loggedIn,
            'application/xslt+xml'
        );
    }

    public static function buildUrl(string $feedUrl, string $version): string
    {
        $parts = parse_url($feedUrl);
        if (false === $parts) {
            throw new \InvalidArgumentException('The configured feed URL is invalid.');
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query['feed-enhancer-stylesheet'] = '1';
        $query['v'] = $version;
        $parts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return self::buildFromParts($parts);
    }

    /**
     * @param array<string,mixed> $parts
     */
    private static function buildFromParts(array $parts): string
    {
        $url = isset($parts['scheme']) ? $parts['scheme'] . ':' : '';

        if (isset($parts['host'])) {
            $url .= '//';
            if (isset($parts['user'])) {
                $url .= rawurlencode((string) $parts['user']);
                if (isset($parts['pass'])) {
                    $url .= ':' . rawurlencode((string) $parts['pass']);
                }
                $url .= '@';
            }

            $host = (string) $parts['host'];
            if (false !== strpos($host, ':') && '[' !== substr($host, 0, 1)) {
                $host = '[' . $host . ']';
            }
            $url .= $host;

            if (isset($parts['port'])) {
                $url .= ':' . (string) $parts['port'];
            }
        }

        $url .= isset($parts['path']) ? (string) $parts['path'] : '';
        if (isset($parts['query']) && '' !== $parts['query']) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}
