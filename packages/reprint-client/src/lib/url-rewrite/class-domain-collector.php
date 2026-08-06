<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\WPURL;

/**
 * Scans decoded database values for HTTP/HTTPS URLs and collects unique
 * domain origins (scheme://host[:port]).
 *
 * Values are recursively classified by format before scanning. Format
 * detection is try-and-fail: construct the real parser, check if it
 * accepted the input. No heuristic pre-checks.
 *
 * - Serialized PHP: construct PhpSerializationProcessor, iterate string
 *   values and recurse
 * - JSON objects/arrays: construct JsonStringIterator, iterate string
 *   values and recurse
 * - Leaf values: scan as HTML (BlockMarkupUrlProcessor finds URLs in
 *   media tags/blocks) AND collect as plain URL (if the entire string
 *   is a URL). These two don't overlap — the HTML scanner only finds
 *   URLs inside tag attributes and block comments, while collectIfUrl
 *   only matches bare URL strings.
 *
 * This avoids polluting the domain list with external link targets
 * (twitter.com, youtube.com, etc.) while capturing the site's own
 * domains and CDN origins that need --rewrite-url mappings.
 */
class DomainCollector
{
    /**
     * HTML tags whose URL attributes reference media assets.
     * Only domains from these tags are collected; link-like tags
     * (a, link, script, iframe, form, etc.) are excluded.
     */
    private const MEDIA_TAGS = [
        'IMG'    => true,
        'VIDEO'  => true,
        'AUDIO'  => true,
        'SOURCE' => true,
        'EMBED'  => true,
        'TRACK'  => true,
        'OBJECT' => true,
        'IMAGE'  => true, // SVG <image>
    ];

    /**
     * WordPress block types whose attributes reference media assets.
     * Only domains from these blocks are collected; navigation and
     * link blocks are excluded.
     */
    private const MEDIA_BLOCKS = [
        'wp:image'      => true,
        'wp:cover'      => true,
        'wp:gallery'    => true,
        'wp:media-text' => true,
        'wp:video'      => true,
        'wp:audio'      => true,
    ];

    /** @var array<string, true> Unique domains (scheme://host:port) */
    private array $domains = [];

    /**
     * Scan a decoded database value for HTTP/HTTPS URLs and collect their
     * domains. Recursively classifies the value by format (serialized PHP,
     * JSON) using the real parsers — no heuristic detection.
     *
     * Returns any domain origins that were discovered for the first time
     * during this call (not previously known). Callers can use this to
     * log the first occurrence with context (table name, PK, etc.).
     *
     * @param string $value The decoded database value to scan.
     * @return string[] Newly discovered domain origins (empty if all were already known).
     */
    public function scan(string $value): array
    {
        if ($value === '') {
            return [];
        }

        if (strpos($value, 'http') === false) {
            return [];
        }

        $before = count($this->domains);

        // Serialized PHP: the parser validates the entire structure in
        // the constructor. If it's not malformed, iterate and recurse.
        $p = new PhpSerializationProcessor($value);
        if (!$p->is_malformed()) {
            while ($p->next_value()) {
                $this->scan($p->get_value());
            }
            return $this->newDomainsSince($before);
        }

        // JSON: the iterator calls json_decode in the constructor. If
        // it's not malformed, iterate and recurse.
        $iter = new JsonStringIterator($value);
        if (!$iter->is_malformed()) {
            while ($iter->next_value()) {
                $this->scan($iter->get_value());
            }
            return $this->newDomainsSince($before);
        }

        // Leaf: try both HTML scanning and plain URL collection.
        // These don't overlap — scanHtml collects from media tags/blocks
        // only, collectIfUrl matches bare URL strings only.
        $this->scanHtml($value);
        $this->collectIfUrl($value);
        return $this->newDomainsSince($before);
    }

    /**
     * Return domains added since the count was $before.
     *
     * @return string[]
     */
    private function newDomainsSince(int $before): array
    {
        if (count($this->domains) === $before) {
            return [];
        }
        return array_slice(array_keys($this->domains), $before);
    }

    /**
     * Scan HTML/block markup for URLs, collecting only from media tags
     * and media blocks.
     */
    private function scanHtml(string $value): void
    {
        $p = new BlockMarkupUrlProcessor($value);
        while ($p->next_url()) {
            $token_type = $p->get_token_type();

            // HTML tag attributes: only collect from media tags
            if ($token_type === '#tag') {
                if (!isset(self::MEDIA_TAGS[$p->get_tag()])) {
                    continue;
                }
            }

            // Block comment attributes: only collect from media blocks
            if ($token_type === '#block-comment') {
                if (!isset(self::MEDIA_BLOCKS[$p->get_block_name()])) {
                    continue;
                }
            }

            // Text nodes within HTML: only collect if the URL is
            // the entire text content (a bare URL pasted into content).
            // This avoids collecting domains from prose like "visit
            // twitter.com for more info".
            if ($token_type === '#text') {
                continue;
            }

            $this->collectParsedUrl($p->get_parsed_url());
        }
    }

    /**
     * Collect a domain if the entire string is a URL.
     */
    private function collectIfUrl(string $value): void
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return;
        }

        $parsed = WPURL::parse($trimmed);
        if ($parsed === false) {
            return;
        }

        $this->collectParsedUrl($parsed);
    }

    /**
     * Extract and store the origin from a parsed URL object.
     */
    private function collectParsedUrl($parsed): void
    {
        if (!$parsed) {
            return;
        }

        $scheme = $parsed->protocol; // "https:" with colon
        if ($scheme !== 'http:' && $scheme !== 'https:') {
            return;
        }

        $scheme_clean = rtrim($scheme, ':');
        $host = $parsed->hostname;
        $port = $parsed->port;

        $origin = $scheme_clean . '://' . $host;
        if ($port !== '') {
            $origin .= ':' . $port;
        }

        $this->domains[$origin] = true;
    }

    /**
     * Get sorted list of unique domain origins.
     *
     * @return string[] Sorted list of domain origins.
     */
    public function get_domains(): array
    {
        $domains = array_keys($this->domains);
        sort($domains);
        return $domains;
    }

    /**
     * Merge with a previously saved domain list.
     *
     * @param string[] $domains List of domain origins to merge.
     */
    public function merge(array $domains): void
    {
        foreach ($domains as $domain) {
            $this->domains[$domain] = true;
        }
    }
}
