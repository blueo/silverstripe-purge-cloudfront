<?php

namespace Blueo\Purge\CloudFront\Service;

use InvalidArgumentException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Turns site URLs into CloudFront invalidation paths, and cuts them into
 * batches of a size one request can carry.
 *
 * CloudFront path rules, from the CloudFront developer guide:
 *
 * - A path is relative to the distribution and begins with a slash when the
 *   API is called directly.
 * - Paths are case sensitive.
 * - A '*' is a wildcard only as the last character. Anywhere else it matches
 *   a literal asterisk.
 * - Non-ASCII characters and the characters RFC 1738 calls unsafe must be URL
 *   encoded. Nothing else may be encoded, or the path does not match.
 * - CloudFront does not accept '~' in an invalidation path, encoded or not.
 * - The maximum length of a path is 4,000 characters.
 * - A query string is part of the path when the distribution caches on query
 *   strings.
 *
 * A path this class cannot express is rejected and named, rather than dropped.
 * A dropped path is a page that stays stale with nothing to read about it.
 */
class PathBatcher
{
    use Injectable;
    use Configurable;

    /**
     * Paths in one CreateInvalidation call.
     *
     * The quota on invalidations is a rate, 150 paths or tags per second. A
     * request of 1,000 paths is one call against that rate rather than 1,000
     * calls, and it stays under the 3,000 path per request ceiling that
     * CloudFront applied before the rate quota replaced it.
     */
    private static int $paths_per_invalidation = 1000;

    /**
     * The CloudFront maximum. A longer path is rejected by the API.
     */
    private static int $max_path_length = 4000;

    /**
     * Characters CloudFront needs encoded: the RFC 1738 unsafe set, less '%'
     * so an already encoded path is left alone, and less '#' which cannot
     * reach a server anyway.
     */
    private const UNSAFE = ['<', '>', '"', '{', '}', '|', '\\', '^', '[', ']', '`', ' '];

    /**
     * @throws InvalidArgumentException
     */
    public function toPath(string $url): string
    {
        $path = trim($url);

        if ($path === '') {
            throw new InvalidArgumentException('An empty URL is not an invalidation path.');
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            $parts = parse_url($path);
            $query = $parts['query'] ?? '';
            $path = ($parts['path'] ?? '') ?: '/';
            $path .= $query !== '' ? '?' . $query : '';
        }

        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        if (str_contains($path, '~')) {
            throw new InvalidArgumentException(sprintf(
                'CloudFront does not accept "~" in an invalidation path, so %s cannot be purged by path. '
                    . 'Purge everything, or change the URL.',
                $path
            ));
        }

        $path = $this->encode($path);

        if (strlen($path) > $this->config()->get('max_path_length')) {
            throw new InvalidArgumentException(sprintf(
                'The invalidation path is %d characters and CloudFront accepts %d.',
                strlen($path),
                $this->config()->get('max_path_length')
            ));
        }

        return $path;
    }

    /**
     * Normalise, drop repeats, and cut into batches.
     *
     * The rejected paths come back beside the batches so the caller reports
     * them. Each entry is the reason, keyed by the URL it came from.
     *
     * @param array<string> $urls
     * @return array{batches: array<array<string>>, rejected: array<string, string>}
     */
    public function batch(array $urls): array
    {
        $paths = [];
        $rejected = [];

        foreach ($urls as $url) {
            try {
                $path = $this->toPath((string) $url);
            } catch (InvalidArgumentException $e) {
                $rejected[(string) $url] = $e->getMessage();

                continue;
            }

            // Two URLs can normalise to one path, and CloudFront charges for
            // each path submitted over the free monthly allowance.
            $paths[$path] = true;
        }

        $size = max(1, (int) $this->config()->get('paths_per_invalidation'));

        return [
            'batches' => array_chunk(array_keys($paths), $size),
            'rejected' => $rejected,
        ];
    }

    /**
     * Encode the characters CloudFront needs encoded and no others.
     */
    private function encode(string $path): string
    {
        $out = '';

        foreach (str_split($path) as $character) {
            $code = ord($character);

            if ($code < 0x21 || $code > 0x7E || in_array($character, self::UNSAFE, true)) {
                $out .= strtoupper(sprintf('%%%02x', $code));

                continue;
            }

            $out .= $character;
        }

        return $out;
    }
}
