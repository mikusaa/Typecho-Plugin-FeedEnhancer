<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Feed;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Resolves media references without fetching them and accepts HTTP(S) only.
 */
final class UrlPolicy
{
    public function resolve(string $candidate, string $baseUrl): ?string
    {
        if (!$this->hasSafeCharacters($candidate) || !$this->hasSafeCharacters($baseUrl)) {
            return null;
        }

        $base = $this->parseAbsolute($baseUrl);
        if (null === $base || !$this->hasValidReferenceAuthority($candidate, $base['scheme'])) {
            return null;
        }

        $reference = $this->parseUrl($candidate);

        if (false === $reference || isset($reference['user']) || isset($reference['pass'])) {
            return null;
        }

        if (isset($reference['scheme'])) {
            return $this->build($reference);
        }

        if (isset($reference['host'])) {
            $reference['scheme'] = $base['scheme'];
            return $this->build($reference);
        }

        $target = [
            'scheme' => $base['scheme'],
            'host' => $base['host'],
        ];

        if (isset($base['port'])) {
            $target['port'] = $base['port'];
        }

        $path = isset($reference['path']) ? (string) $reference['path'] : '';
        if ('' === $path) {
            $target['path'] = isset($base['path']) ? (string) $base['path'] : '/';
            if (array_key_exists('query', $reference)) {
                $target['query'] = $reference['query'];
            } elseif (array_key_exists('query', $base)) {
                $target['query'] = $base['query'];
            }
        } else {
            if ('/' === $path[0]) {
                $target['path'] = $this->removeDotSegments($path);
            } else {
                $basePath = isset($base['path']) ? (string) $base['path'] : '/';
                $slash = strrpos($basePath, '/');
                $directory = false === $slash ? '/' : substr($basePath, 0, $slash + 1);
                $target['path'] = $this->removeDotSegments($directory . $path);
            }

            if (array_key_exists('query', $reference)) {
                $target['query'] = $reference['query'];
            }
        }

        if (array_key_exists('fragment', $reference)) {
            $target['fragment'] = $reference['fragment'];
        }

        return $this->build($target);
    }

    public function isSafeAbsolute(string $url): bool
    {
        return null !== $this->parseAbsolute($url);
    }

    /** @return array<string,mixed>|null */
    private function parseAbsolute(string $url): ?array
    {
        if (!$this->hasSafeCharacters($url) || !$this->hasValidAbsoluteAuthority($url)) {
            return null;
        }

        $parts = $this->parseUrl($url);
        if (false === $parts || null === $this->build($parts)) {
            return null;
        }

        return $parts;
    }

    /**
     * PHP 8.5's URL parser replaces non-ASCII bytes in URL components. Shield
     * them with collision-free ASCII tokens, then restore the parsed values.
     *
     * @return array<string,mixed>|false
     */
    private function parseUrl(string $url)
    {
        $prefix = '__FE_UTF8_';
        while (false !== strpos($url, $prefix)) {
            $prefix = '_' . $prefix;
        }

        $replacements = [];
        $index = 0;
        $protected = preg_replace_callback(
            '/[\x80-\xFF]/',
            static function (array $matches) use ($prefix, &$replacements, &$index): string {
                $token = $prefix . $index++ . '__';
                $replacements[$token] = $matches[0];
                return $token;
            },
            $url
        );

        if (null === $protected) {
            return false;
        }

        $parts = @parse_url($protected);
        if (false === $parts || [] === $replacements) {
            return $parts;
        }

        foreach ($parts as $name => $value) {
            if (is_string($value)) {
                $parts[$name] = strtr($value, $replacements);
            }
        }

        return $parts;
    }

    /**
     * @param array<string,mixed> $parts
     */
    private function build(array $parts): ?string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (
            !in_array($scheme, ['http', 'https'], true)
            || '' === $host
            || isset($parts['user'])
            || isset($parts['pass'])
            || !$this->hasValidHost($host)
        ) {
            return null;
        }

        if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            return null;
        }

        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . (int) $parts['port'];
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $url .= '' === $path ? '/' : $path;

        if (array_key_exists('query', $parts)) {
            $url .= '?' . (string) $parts['query'];
        }

        if (array_key_exists('fragment', $parts)) {
            $url .= '#' . (string) $parts['fragment'];
        }

        return $this->hasSafeCharacters($url) ? $url : null;
    }

    private function hasSafeCharacters(string $url): bool
    {
        if (
            '' === $url
            || false !== strpos($url, '\\')
            || 1 !== preg_match('//u', $url)
            || 1 === preg_match('/%(?![0-9A-Fa-f]{2})/', $url)
            || 1 === preg_match('/[\\x00-\\x20\\x7F\\p{Cc}\\p{Cf}]/u', $url)
        ) {
            return false;
        }

        $entityDecoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach ([$entityDecoded, rawurldecode($entityDecoded)] as $decoded) {
            if (
                false !== strpos($decoded, '\\')
                || 1 !== preg_match('//u', $decoded)
                || 1 === preg_match('/[\\x00-\\x20\\x7F\\p{Cc}\\p{Cf}]/u', $decoded)
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasValidReferenceAuthority(string $reference, string $baseScheme): bool
    {
        if (0 === strpos($reference, '//')) {
            return $this->hasValidAbsoluteAuthority($baseScheme . ':' . $reference);
        }

        if (1 === preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//D', $reference)) {
            return $this->hasValidAbsoluteAuthority($reference);
        }

        return true;
    }

    private function hasValidAbsoluteAuthority(string $url): bool
    {
        if (1 !== preg_match('~^https?://([^/?#]*)~iD', $url, $matches)) {
            return false;
        }

        $authority = $matches[1];
        if ('' === $authority || false !== strpos($authority, '@')) {
            return false;
        }

        $host = $authority;
        $port = null;
        if ('[' === $authority[0]) {
            $closing = strpos($authority, ']');
            if (false === $closing) {
                return false;
            }

            $host = substr($authority, 0, $closing + 1);
            $tail = substr($authority, $closing + 1);
            if ('' !== $tail) {
                if (1 !== preg_match('/^:([0-9]+)$/D', $tail, $portMatch)) {
                    return false;
                }
                $port = $portMatch[1];
            }
        } else {
            if (false !== strpos($authority, '[') || false !== strpos($authority, ']')) {
                return false;
            }

            $colon = strrpos($authority, ':');
            if (false !== $colon) {
                if (false !== strpos(substr($authority, 0, $colon), ':')) {
                    return false;
                }

                $host = substr($authority, 0, $colon);
                $port = substr($authority, $colon + 1);
                if ('' === $port || 1 !== preg_match('/^[0-9]+$/D', $port)) {
                    return false;
                }
            }
        }

        if (!$this->hasValidHost($host)) {
            return false;
        }

        return null === $port || ((int) $port >= 1 && (int) $port <= 65535);
    }

    private function hasValidHost(string $host): bool
    {
        if ('[' === substr($host, 0, 1)) {
            if (']' !== substr($host, -1)) {
                return false;
            }

            $address = substr($host, 1, -1);
            return false !== filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                || 1 === preg_match('/^v[0-9A-F]+\\.[A-Za-z0-9._~!$&\'()*+,;=:-]+$/iD', $address);
        }

        $decodedHost = rawurldecode($host);
        return '' !== $decodedHost
            && 1 === preg_match('//u', $decodedHost)
            && false === strpbrk($decodedHost, '\\\\/?#@:[]');
    }

    private function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';

        while ('' !== $input) {
            if (0 === strpos($input, '../')) {
                $input = substr($input, 3);
                continue;
            }
            if (0 === strpos($input, './')) {
                $input = substr($input, 2);
                continue;
            }
            if (0 === strpos($input, '/./')) {
                $input = substr($input, 2);
                continue;
            }
            if ('/.' === $input) {
                $input = '/';
                continue;
            }
            if (0 === strpos($input, '/../')) {
                $input = substr($input, 3);
                $output = $this->removeLastPathSegment($output);
                continue;
            }
            if ('/..' === $input) {
                $input = '/';
                $output = $this->removeLastPathSegment($output);
                continue;
            }
            if ('.' === $input || '..' === $input) {
                $input = '';
                continue;
            }

            $nextSlash = '/' === $input[0]
                ? strpos($input, '/', 1)
                : strpos($input, '/');
            if (false === $nextSlash) {
                $output .= $input;
                $input = '';
                continue;
            }

            $output .= substr($input, 0, $nextSlash);
            $input = substr($input, $nextSlash);
        }

        return $output;
    }

    private function removeLastPathSegment(string $path): string
    {
        $slash = strrpos($path, '/');
        return false === $slash ? '' : substr($path, 0, $slash);
    }
}
