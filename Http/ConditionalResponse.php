<?php

declare(strict_types=1);

namespace TypechoPlugin\FeedEnhancer\Http;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Builds deterministic GET/HEAD responses for feed and stylesheet bytes.
 */
final class ConditionalResponse
{
    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function prepare(
        string $method,
        string $representation,
        ?string $ifNoneMatch,
        bool $loggedIn,
        string $contentType
    ): array {
        $method = strtoupper($method);
        $headers = [
            'Cache-Control' => $loggedIn
                ? 'private, no-store'
                : 'private, max-age=0, must-revalidate',
            'Vary' => 'Cookie',
            'Content-Type' => $contentType . '; charset=UTF-8',
        ];

        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $headers['Allow'] = 'GET, HEAD';
            $headers['Content-Length'] = '0';

            return [
                'status' => 405,
                'headers' => $headers,
                'body' => '',
            ];
        }

        $etag = '"sha256-' . hash('sha256', $representation) . '"';
        $headers['ETag'] = $etag;

        if (null !== $ifNoneMatch && $this->matches($ifNoneMatch, $etag)) {
            return [
                'status' => 304,
                'headers' => $headers,
                'body' => '',
            ];
        }

        $headers['Content-Length'] = (string) strlen($representation);

        return [
            'status' => 200,
            'headers' => $headers,
            'body' => 'HEAD' === $method ? '' : $representation,
        ];
    }

    private function matches(string $header, string $etag): bool
    {
        $header = trim($header);
        if ('' === $header) {
            return false;
        }

        if ('*' === $header) {
            return true;
        }

        $length = strlen($header);
        $offset = 0;
        $expected = $this->stripWeakPrefix($etag);
        $matched = false;

        while ($offset < $length) {
            while ($offset < $length && (' ' === $header[$offset] || "\t" === $header[$offset])) {
                ++$offset;
            }

            if (
                $offset + 2 <= $length
                && 'W/' === substr($header, $offset, 2)
            ) {
                $offset += 2;
            }

            if ($offset >= $length || '"' !== $header[$offset]) {
                return false;
            }

            $start = $offset;
            ++$offset;
            while ($offset < $length && '"' !== $header[$offset]) {
                $byte = ord($header[$offset]);
                if (0x21 !== $byte && ($byte < 0x23 || 0x7f === $byte)) {
                    return false;
                }
                ++$offset;
            }

            if ($offset >= $length) {
                return false;
            }

            ++$offset;
            $candidate = substr($header, $start, $offset - $start);
            $matched = $this->stripWeakPrefix($candidate) === $expected || $matched;

            while ($offset < $length && (' ' === $header[$offset] || "\t" === $header[$offset])) {
                ++$offset;
            }

            if ($offset >= $length) {
                break;
            }

            if (',' !== $header[$offset]) {
                return false;
            }
            ++$offset;

            $lookahead = $offset;
            while ($lookahead < $length && (' ' === $header[$lookahead] || "\t" === $header[$lookahead])) {
                ++$lookahead;
            }
            if ($lookahead >= $length) {
                return false;
            }
        }

        return $matched;
    }

    private function stripWeakPrefix(string $etag): string
    {
        return 0 === strncmp($etag, 'W/', 2) ? substr($etag, 2) : $etag;
    }
}
