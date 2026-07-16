<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;
use WordPress\DataLiberation\URL\URLInTextProcessor;
use WordPress\DataLiberation\URL\WPURL;

use function WordPress\DataLiberation\URL\is_child_url_of;
/**
 * Rewrites URLs in a single decoded database value by detecting the data
 * format and applying the appropriate rewriting strategy.
 *
 * Format detection is try-and-fail after conservative syntax gates: construct
 * the real parser, check if it accepted the input. The gates only skip parser
 * attempts for byte prefixes that cannot contain string leaves for that format;
 * the parsers themselves remain the authority on what's valid.
 *
 * 1. Serialized PHP → construct PhpSerializationProcessor, if not malformed,
 *    iterate string values and recurse on each
 * 2. JSON → construct JsonStringIterator, if not malformed, iterate string
 *    values and recurse on each
 * 3. Base64 → decode, recurse on decoded content, re-encode if changed
 * 4. Leaf text → BlockMarkupUrlProcessor (block_markup hint) or
 *    URLInTextProcessor (default)
 *
 * HTML is never auto-detected — the caller must explicitly pass
 * content_type='block_markup' for values known to contain HTML/block markup.
 * The hint propagates through recursive calls so that leaf strings inside
 * serialized PHP, JSON, or base64 eventually reach the same block-markup
 * parser.
 */
class StructuredDataUrlRewriter
{
    const BLOCK_MARKUP = 'block_markup';
    const PLAIN_TEXT = 'plain_text';
    private const STRUCTURED_REWRITE_CACHE_MAX = 4096;
    private const REWRITE_RESULT_CACHE_MAX = 4096;

    /** @var string[] Source domains extracted from url_mapping keys, for quick-reject checks. */
    private array $source_domains;

    /**
     * Pre-parsed url_mapping: each entry is
     *   [ 'from_url' => <parsed URL>, 'to_url' => <parsed URL> ]
     * where <parsed URL> is whatever WPURL::parse() returns (declared as
     * mixed here because is_child_url_of() and WPURL::replace_base_url()
     * both accept either a string or the parsed object form — we pass the
     * object form for performance).
     *
     * Parsing is pure, deterministic work that used to happen inside
     * rewrite_urls() on every leaf-value call. With N mappings and L leaves
     * that's 2·N·L WPURL::parse() invocations. On a wp.com-shaped dump
     * (N=120, L≈28k) that single loop dominated 94 % of db-apply wall time
     * under WASM PHP. Hoisting it into the constructor collapses it to 2·N,
     * which is effectively free.
     *
     * @var array<int, array{from_url: mixed, to_url: mixed}>
     */
    private array $parsed_mapping;

    /** @var string Default base_url used by the URL processors (first from-url). */
    private string $base_url;

    /** @var string Cache namespace for this rewriter's URL mapping. */
    private string $mapping_cache_key;

    /** @var array<string, array{content_type: string, input: string, output: string}> */
    private array $structured_rewrite_cache = [];

    /** @var string[] */
    private array $structured_rewrite_cache_ring = [];

    private int $structured_rewrite_cache_next = 0;

    /** @var array<string, false|array{raw_url: string, parsed_url: mixed}> */
    private array $rewrite_result_cache = [];

    /** @var string[] */
    private array $rewrite_result_cache_ring = [];

    private int $rewrite_result_cache_next = 0;

    /**
     * @param array<string, string> $url_mapping Source URL => target URL mapping.
     */
    public function __construct(array $url_mapping)
    {
        // Extract unique source domains for the quick-reject check.
        $domains = [];
        foreach (array_keys($url_mapping) as $from_url) {
            $host = parse_url($from_url, PHP_URL_HOST);
            if ($host !== null && $host !== false) {
                $this->add_source_domain_variants($domains, $host);
            }
        }
        $this->source_domains = array_keys($domains);

        // Parse the mapping once. Each WPURL::parse() does non-trivial work
        // (scheme/host/path tokenisation, punycode, etc.) and used to be
        // repeated on every leaf we rewrote.
        $this->parsed_mapping = [];
        foreach ($url_mapping as $from_url_string => $to_url_string) {
            $this->parsed_mapping[] = [
                'from_url' => WPURL::parse($from_url_string),
                'to_url'   => WPURL::parse($to_url_string),
            ];
        }
        $this->mapping_cache_key = sha1(json_encode($url_mapping, JSON_UNESCAPED_SLASHES));

        // Default base_url: first from-url in the mapping. Preserves the
        // behaviour of the previous per-call default so outputs are unchanged.
        $from_urls = array_keys($url_mapping);
        $this->base_url = $from_urls[0] ?? '';
    }

    /**
     * Rewrite URLs in a single decoded value.
     *
     * @param string      $value        The decoded database value.
     * @param string|null $content_type Content type hint: null (auto-detect, plain text default),
     *                                  'block_markup' (use BlockMarkupUrlProcessor), or 'skip' (no-op).
     * @return string The rewritten value, or the original if no changes were made.
     */
    public function rewrite(string $value, ?string $content_type = null): string
    {
        if ($value === '') {
            return $value;
        }

        if ($content_type === 'skip') {
            return $value;
        }

        if ($content_type === null) {
            $content_type = self::PLAIN_TEXT;
        }

        $structured_cache_key = sha1($content_type . "\0" . $value);
        $cached = $this->get_cached_structured_rewrite($structured_cache_key, $content_type, $value);
        if ($cached !== null) {
            return $cached;
        }

        // Quick-reject: if the value doesn't contain href=", src=", or any
        // source domain, there's nothing to rewrite. This avoids expensive
        // parsing (serialized PHP, JSON, block markup) for the vast majority
        // of values that don't contain any rewritable URLs.
        if (!$this->maybe_contains_rewritable_urls($value)) {
            return $value;
        }

        // Performance guard: avoid constructing the serialized-PHP parser for
        // ordinary URL strings and block markup. The parser still owns
        // validation once entered; this gate only skips first-byte shapes that
        // cannot expose serialized string values for rewriting.
        if ($this->could_be_php_serialization_with_strings($value)) {
            $p = new PhpSerializationProcessor($value);
            if (!$p->is_malformed()) {
                while ($p->next_value()) {
                    $original = $p->get_value();
                    $rewritten = $this->rewrite($original, $content_type);
                    if ($rewritten !== $original) {
                        $p->set_value($rewritten);
                    }
                }
                $rewritten_value = $p->get_updated_serialization();
                $this->set_cached_structured_rewrite($structured_cache_key, $content_type, $value, $rewritten_value);
                return $rewritten_value;
            }
        }

        // Performance guard: avoid calling json_decode() for ordinary URL
        // strings and block markup. JsonStringIterator still owns validation
        // once entered; this gate only skips first non-whitespace bytes that
        // cannot start a JSON value containing string leaves.
        if ($this->could_be_json_with_strings($value)) {
            $iter = new JsonStringIterator($value);
            if (!$iter->is_malformed()) {
                while ($iter->next_value()) {
                    $original = $iter->get_value();
                    $rewritten = $this->rewrite($original, $content_type);
                    if ($rewritten !== $original) {
                        $iter->set_value($rewritten);
                    }
                }
                $rewritten_value = $iter->get_result();
                $this->set_cached_structured_rewrite($structured_cache_key, $content_type, $value, $rewritten_value);
                return $rewritten_value;
            }
        }

        // Base64 decoding is temporarily disabled for performance.
        // The base64 transport layer in SQL is already handled by
        // Base64ValueScanner in SqlStatementRewriter — this block
        // was for base64-within-base64 nesting which is rare in practice.

        $rewritten_value = $this->rewrite_urls($value, $content_type);
        $this->set_cached_structured_rewrite($structured_cache_key, $content_type, $value, $rewritten_value);
        return $rewritten_value;
    }

    /**
     * Quick-reject check: returns false when the value certainly doesn't
     * contain any rewritable URLs, avoiding expensive parsing.
     *
     * A value is considered potentially rewritable if it contains:
     * - href=" or src=" (HTML attributes that carry URLs), OR
     * - any source domain from the url_mapping (bare URL occurrences)
     */
    private function maybe_contains_rewritable_urls(string $value): bool
    {
        if (stripos($value, 'href=') !== false || stripos($value, 'src=') !== false) {
            return true;
        }
        foreach ($this->source_domains as $domain) {
            if (stripos($value, $domain) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return whether the value starts with a PHP serialization token that may
     * expose string values to rewrite.
     *
     * This is a speed guard before constructing PhpSerializationProcessor. It
     * deliberately omits scalar serialized types such as i:, d:, b:, N;, r:,
     * and R: because they cannot contain string leaves. The processor remains
     * responsible for full validation once this coarse first-byte check passes.
     */
    private function could_be_php_serialization_with_strings(string $value): bool
    {
        $first_byte = $value[0] ?? '';

        return $first_byte === 'a'
            || $first_byte === 's'
            || $first_byte === 'O'
            || $first_byte === 'C';
    }

    /**
     * Return whether the value starts with a JSON token that may expose string
     * leaves to rewrite.
     *
     * This is a speed guard before constructing JsonStringIterator, whose
     * constructor calls json_decode(). Objects and arrays can contain nested
     * string leaves, and JSON string scalars can themselves be rewritten. The
     * iterator remains responsible for full JSON validation after this coarse
     * first-byte check passes.
     */
    private function could_be_json_with_strings(string $value): bool
    {
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = $value[$i];
            if ($byte === ' ' || $byte === "\n" || $byte === "\r" || $byte === "\t") {
                continue;
            }

            return $byte === '{' || $byte === '[' || $byte === '"';
        }

        return false;
    }

    /**
     * @param array<string, true> $domains
     */
    private function add_source_domain_variants(array &$domains, string $host): void
    {
        if ($host === '') {
            return;
        }

        $domains[$host] = true;

        if (function_exists('idn_to_ascii')) {
            $ascii = defined('INTL_IDNA_VARIANT_UTS46')
                ? @idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46)
                : @idn_to_ascii($host);
            if (is_string($ascii) && $ascii !== '') {
                $domains[$ascii] = true;
            }
        }

        if (function_exists('idn_to_utf8')) {
            $unicode = defined('INTL_IDNA_VARIANT_UTS46')
                ? @idn_to_utf8($host, 0, INTL_IDNA_VARIANT_UTS46)
                : @idn_to_utf8($host);
            if (is_string($unicode) && $unicode !== '') {
                $domains[$unicode] = true;
            }
        }
    }

    private function get_cached_structured_rewrite(string $cache_key, string $content_type, string $value): ?string
    {
        if (!array_key_exists($cache_key, $this->structured_rewrite_cache)) {
            return null;
        }

        $entry = $this->structured_rewrite_cache[$cache_key];
        if ($entry['content_type'] !== $content_type || $entry['input'] !== $value) {
            return null;
        }

        return $entry['output'];
    }

    private function set_cached_structured_rewrite(string $cache_key, string $content_type, string $input, string $output): void
    {
        if (!array_key_exists($cache_key, $this->structured_rewrite_cache)) {
            if (count($this->structured_rewrite_cache_ring) < self::STRUCTURED_REWRITE_CACHE_MAX) {
                $this->structured_rewrite_cache_ring[] = $cache_key;
            } else {
                $evicted_key = $this->structured_rewrite_cache_ring[$this->structured_rewrite_cache_next];
                unset($this->structured_rewrite_cache[$evicted_key]);
                $this->structured_rewrite_cache_ring[$this->structured_rewrite_cache_next] = $cache_key;
            }

            $this->structured_rewrite_cache_next = ($this->structured_rewrite_cache_next + 1) % self::STRUCTURED_REWRITE_CACHE_MAX;
        }

        $this->structured_rewrite_cache[$cache_key] = [
            'content_type' => $content_type,
            'input'       => $input,
            'output'      => $output,
        ];
    }

    /**
     * Rewrite a decoded value already known by the SQL layer to be block markup.
     *
     * Block markup owns HTML attributes, block-comment JSON, CSS url() values,
     * text URLs, entity decoding, URL casing, and IDN canonicalization. This
     * intentionally routes through the structured parser instead of doing a
     * literal source-base replacement, because one database value may contain
     * multiple spellings of the same URL that only the parser can recognize as
     * equivalent.
     */
    public function rewrite_known_block_markup_value(string $value): string
    {
        return $this->rewrite($value, self::BLOCK_MARKUP);
    }

    /**
     * Rewrite a decoded value known from schema context to be scalar text.
     *
     * This still routes through URLInTextProcessor; it only skips the
     * serialized-PHP and JSON container probes that are impossible for narrow
     * core URL columns such as wp_posts.guid or wp_users.user_url.
     */
    public function rewrite_known_plain_text_value(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (!$this->maybe_contains_rewritable_urls($value)) {
            return $value;
        }

        return $this->rewrite_urls($value, self::PLAIN_TEXT);
    }

    /**
     * Return whether a decoded value may contain one of the configured source
     * domains. This intentionally checks hosts instead of full source URLs so
     * escaped spellings of `://` in block markup or JSON do not matter.
     */
    public function value_might_contain_source_domain(string $value): bool
    {
        if ($this->source_domains === []) {
            return true;
        }

        foreach ($this->source_domains as $domain) {
            if (stripos($value, $domain) !== false) {
                return true;
            }
        }

        return false;
    }

    private function get_cached_rewrite_result(string $cache_key)
    {
        return array_key_exists($cache_key, $this->rewrite_result_cache)
            ? $this->rewrite_result_cache[$cache_key]
            : null;
    }

    /**
     * @param array|false $value {
     *     Cached rewrite result, or false for an uncacheable value.
     *
     *     @type string $raw_url    Raw URL value.
     *     @type mixed  $parsed_url Parsed URL value.
     * }
     * @phpstan-param false|array{raw_url: string, parsed_url: mixed} $value
     */
    private function set_cached_rewrite_result(string $cache_key, $value): void
    {
        if (!array_key_exists($cache_key, $this->rewrite_result_cache)) {
            if (count($this->rewrite_result_cache_ring) < self::REWRITE_RESULT_CACHE_MAX) {
                $this->rewrite_result_cache_ring[] = $cache_key;
            } else {
                $evicted_key = $this->rewrite_result_cache_ring[$this->rewrite_result_cache_next];
                unset($this->rewrite_result_cache[$evicted_key]);
                $this->rewrite_result_cache_ring[$this->rewrite_result_cache_next] = $cache_key;
            }

            $this->rewrite_result_cache_next = ($this->rewrite_result_cache_next + 1) % self::REWRITE_RESULT_CACHE_MAX;
        }

        $this->rewrite_result_cache[$cache_key] = $value;
    }

    /**
     * Migrate URLs in post content. See WPRewriteUrlsTests for
     * specific examples. TODO: A better description.
     *
     * Example:
     *
     * ```php
     * php > wp_rewrite_urls([
     *   'block_markup' => '<!-- wp:image {"src": "http://legacy-blog.com/image.jpg"} -->',
     *   'url-mapping' => [
     *     'http://legacy-blog.com' => 'https://modern-webstore.org'
     *   ]
     * ])
     * <!-- wp:image {"src":"https:\/\/modern-webstore.org\/image.jpg"} -->
     * ```
     *
     * @TODO Use a proper JSON parser and encoder to:
     * * Support UTF-16 characters
     * * Gracefully handle recoverable encoding issues
     * * Avoid changing the whitespace in the same manner as
     *   we do in WP_HTML_Tag_Processor. e.g. if we start with:
     *
     * ```html
     * <!-- wp:block {"url":"https://w.org"}` -->
     *                     ^ no space here
     * ```
     *
     * then it would be nice to re-encode that block markup also without the space character. This is similar
     * to how the tag processor avoids changing parts of the tag it doesn't need to change.
     * 
     * TODO: Migrate these changes back into the php-toolkit repo
     */
    private function rewrite_urls( string $content, string $content_type ): string {
        // $this->parsed_mapping is built once in the constructor and reused
        // here on every call, avoiding a fresh round of WPURL::parse() per
        // leaf value.
        $parsed_mapping = $this->parsed_mapping;
        $base_url       = $this->base_url;

        switch ( $content_type ) {
            case self::BLOCK_MARKUP:
                $p = new BlockMarkupUrlProcessor( $content, $base_url );
                while ( $p->next_url() ) {
                    $raw_url = $p->get_raw_url();
                    $token_type = $p->get_token_type() ?? '';
                    $cache_key = $this->mapping_cache_key . "\0" . self::BLOCK_MARKUP . "\0" . $token_type . "\0" . $raw_url;
                    $cached = $this->get_cached_rewrite_result($cache_key);
                    if ($cached !== null) {
                        if ($cached !== false) {
                            $p->set_url($cached['raw_url'], $cached['parsed_url']);
                        }
                        continue;
                    }

                    $parsed_url = $p->get_parsed_url();
                    $converted = false;
                    foreach ( $parsed_mapping as $mapping ) {
                        if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
                            $converted = WPURL::replace_base_url(
                                $parsed_url,
                                array(
                                    'old_base_url' => $base_url,
                                    'new_base_url' => $mapping['to_url'],
                                    'raw_url'      => $raw_url,
                                    'is_relative'  => (
                                        '#text' !== $token_type &&
                                        ! WPURL::can_parse($raw_url)
                                    ),
                                )
                            );
                            break;
                        }
                    }

                    $cache_value = false;
                    if ($converted !== false) {
                        $cache_value = [
                            'raw_url'    => (string) $converted,
                            'parsed_url' => $converted->new_url,
                        ];
                        $p->set_url($cache_value['raw_url'], $cache_value['parsed_url']);
                    }
                    $this->set_cached_rewrite_result($cache_key, $cache_value);
                }

                return $p->get_updated_html();

            case self::PLAIN_TEXT:
                $p = new URLInTextProcessor( $content, $base_url );
                while ( $p->next_url() ) {
                    $raw_url = $p->get_raw_url();
                    $cache_key = $this->mapping_cache_key . "\0" . self::PLAIN_TEXT . "\0" . $raw_url;
                    $cached = $this->get_cached_rewrite_result($cache_key);
                    if ($cached !== null) {
                        if ($cached !== false) {
                            $p->set_raw_url($cached['raw_url']);
                        }
                        continue;
                    }

                    $parsed_url = $p->get_parsed_url();
                    $converted = false;
                    foreach ( $parsed_mapping as $mapping ) {
                        if ( is_child_url_of( $parsed_url, $mapping['from_url'] ) ) {
                            $converted = WPURL::replace_base_url(
                                $parsed_url,
                                array(
                                    'old_base_url' => $base_url,
                                    'new_base_url' => $mapping['to_url'],
                                    'raw_url'      => $p->get_raw_url(),
                                    'is_relative'  => false,
                                )
                            );
                            break;
                        }
                    }

                    $cache_value = false;
                    if ($converted !== false) {
                        $cache_value = [
                            'raw_url'    => (string) $converted,
                            'parsed_url' => $converted->new_url,
                        ];
                        $p->set_raw_url($cache_value['raw_url']);
                    }
                    $this->set_cached_rewrite_result($cache_key, $cache_value);
                }

                return $p->get_updated_text();

            default:
                _doing_it_wrong( __FUNCTION__, 'rewrite_urls() requires either block_markup or plain_text to be provided', '1.0.0' );
                return '';
        }
    }
}
