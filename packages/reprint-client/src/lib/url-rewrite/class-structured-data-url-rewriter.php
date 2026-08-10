<?php

use Rowbot\Idna\Idna;
use WordPress\DataLiberation\CSS\CSSProcessor;

use function WordPress\Encoding\codepoint_to_utf8_bytes;

/**
 * Rewrites mapped URL bases without rebuilding their surrounding data.
 *
 * Complete PHP serializations and JSON documents own their nested string
 * values. CSS and block markup own the exact source spans they recognize.
 * Everything else receives a non-cascading byte-literal base replacement.
 * A value which claims a structured format but fails its parser stays opaque.
 */
class StructuredDataUrlRewriter
{
    public const BLOCK_MARKUP = 'block_markup';
    public const PLAIN_TEXT = 'plain_text';

    private const REWRITE_CACHE_MAXIMUM = 4096;

    /**
     * Direct block attributes whose relative values are URL references.
     *
     * @var array<string, string[]>
     */
    private const BLOCK_ATTRIBUTES_ACCEPTING_RELATIVE_URLS = [
        'wp:button' => ['url'],
        'wp:cover' => ['url'],
        'wp:embed' => ['url'],
        'wp:gallery' => ['url', 'fullUrl'],
        'wp:image' => ['url', 'src', 'href'],
        'wp:media-text' => ['mediaUrl', 'href'],
        'wp:navigation-link' => ['url'],
        'wp:navigation-submenu' => ['url'],
        'wp:rss' => ['feedURL'],
    ];

    /**
     * HTML attributes whose complete values are URL references.
     *
     * @var array<string, string[]>
     */
    private const HTML_ATTRIBUTES_ACCEPTING_RELATIVE_URLS = [
        'A' => ['href'],
        'APPLET' => ['codebase', 'archive'],
        'AREA' => ['href'],
        'AUDIO' => ['src'],
        'BASE' => ['href'],
        'BLOCKQUOTE' => ['cite'],
        'BUTTON' => ['formaction'],
        'COMMAND' => ['icon'],
        'DEL' => ['cite'],
        'EMBED' => ['src'],
        'FORM' => ['action'],
        'FRAME' => ['longdesc', 'src'],
        'IFRAME' => ['longdesc', 'src'],
        'IMAGE' => ['href'],
        'IMG' => ['longdesc', 'src', 'usemap', 'lowsrc', 'highsrc'],
        'INPUT' => ['src', 'usemap', 'formaction'],
        'INS' => ['cite'],
        'LINK' => ['href'],
        'OBJECT' => ['classid', 'codebase', 'data', 'usemap'],
        'Q' => ['cite'],
        'SCRIPT' => ['src'],
        'SOURCE' => ['src'],
        'TRACK' => ['src'],
        'VIDEO' => ['poster', 'src'],
    ];

    /** @var string[] HTML elements whose srcset attribute contains URL candidates. */
    private const HTML_ELEMENTS_WITH_SRCSET = ['IMG', 'SOURCE'];

    /**
     * Absolute URL mappings, including source-host spelling variants.
     *
     * @var array<int, array{
     *     source_prefix: string,
     *     source_path: string,
     *     source_scheme: string,
     *     target_base: string,
     *     target_scheme: string,
     *     order: int
     * }>
     */
    private array $absolute_mappings = [];

    /**
     * Network-path URL mappings for parser-owned relative contexts.
     *
     * @var array<int, array{
     *     source_prefix: string,
     *     source_path: string,
     *     replacement_base: string,
     *     order: int
     * }>
     */
    private array $network_path_mappings = [];

    /**
     * Root-relative path mappings for parser-owned relative contexts.
     *
     * @var array<int, array{source_path: string, replacement_base: string, order: int}>
     */
    private array $root_relative_mappings = [];

    /** @var string[] Source host spellings used for cheap decoded-value checks. */
    private array $source_domains = [];

    /** @var string[] Non-empty configured source paths. */
    private array $source_paths = [];

    /** @var array<string, array{content_type: string, input: string, output: string}> */
    private array $rewrite_cache = [];

    /** @var string[] First-in-first-out cache keys. */
    private array $rewrite_cache_keys = [];

    /**
     * @param array<string, string> $url_mapping Source URL to target URL mapping.
     *     The first supported source designates the document origin and scheme
     *     inherited by root-relative and network-path references.
     */
    public function __construct(array $url_mapping)
    {
        $source_domains = [];
        $source_paths = [];
        $network_path_candidates = [];
        $root_relative_candidates = [];
        $designated_source_scheme = null;
        $designated_source_hosts = [];
        $designated_source_port = null;
        $mapping_order = 0;

        foreach ($url_mapping as $source_url => $target_url) {
            $source_parts = parse_url($source_url);
            $target_parts = parse_url($target_url);
            if (
                !$this->has_supported_url_parts($source_parts)
                || !$this->has_supported_url_parts($target_parts)
            ) {
                ++$mapping_order;
                continue;
            }

            $source_scheme = strtolower( (string) $source_parts['scheme']);
            $target_scheme = strtolower( (string) $target_parts['scheme']);
            $default_source_port = $source_scheme === 'https' ? 443 : 80;
            $source_port_number = isset($source_parts['port'])
                ? (int) $source_parts['port']
                : $default_source_port;
            $source_host_variants = $this->get_host_variants(
                (string) $source_parts['host']
            );
            if ($designated_source_scheme === null) {
                $designated_source_scheme = $source_scheme;
                $designated_source_port = $source_port_number;
                foreach ($source_host_variants as $source_host_variant) {
                    $designated_source_hosts[strtolower($source_host_variant)] = true;
                }
            }
            $uses_designated_origin = $source_scheme === $designated_source_scheme
                && $source_port_number === $designated_source_port;
            if ($uses_designated_origin) {
                $uses_designated_origin = false;
                foreach ($source_host_variants as $source_host_variant) {
                    if (isset($designated_source_hosts[strtolower($source_host_variant)])) {
                        $uses_designated_origin = true;
                        break;
                    }
                }
            }
            $source_path = $this->normalize_mapping_path($this->get_raw_url_path($source_url));
            $target_path = $this->normalize_mapping_path($this->get_raw_url_path($target_url));
            $source_ports = [isset($source_parts['port']) ? ':' . $source_parts['port'] : ''];
            if (isset($source_parts['port']) && $source_parts['port'] === $default_source_port) {
                $source_ports[] = '';
            } elseif (!isset($source_parts['port'])) {
                $source_ports[] = ':' . $default_source_port;
            }
            $target_port = isset($target_parts['port']) ? ':' . $target_parts['port'] : '';
            $target_base = rtrim($target_url, '/');
            if ($source_path !== '') {
                $source_paths[$source_path] = $source_path;
            }

            foreach ($source_host_variants as $source_host) {
                $source_domains[strtolower($source_host)] = $source_host;
                foreach (array_unique($source_ports) as $source_port) {
                    $source_prefix = $source_scheme . '://' . $source_host . $source_port;
                    $this->absolute_mappings[] = [
                        'source_prefix' => $source_prefix,
                        'source_path' => $source_path,
                        'source_scheme' => $source_scheme,
                        'target_base' => $target_base,
                        'target_scheme' => $target_scheme,
                        'order' => $mapping_order,
                    ];
                    if ($source_scheme === $designated_source_scheme) {
                        $network_source_prefix = '//' . $source_host . $source_port;
                        $network_key = strtolower($network_source_prefix) . "\0" . $source_path;
                        if (!isset($network_path_candidates[$network_key])) {
                            $network_path_candidates[$network_key] = [
                                'source_prefix' => $network_source_prefix,
                                'source_path' => $source_path,
                                'replacements' => [],
                                'order' => $mapping_order,
                            ];
                        }
                        $network_replacement = '//'
                            . (string) $target_parts['host']
                            . $target_port
                            . $target_path;
                        $network_path_candidates[$network_key]['replacements'][
                            $network_replacement
                        ] = true;
                    }
                }
            }

            if ($uses_designated_origin) {
                if (!isset($root_relative_candidates[$source_path])) {
                    $root_relative_candidates[$source_path] = [
                        'source_path' => $source_path,
                        'replacements' => [],
                        'order' => $mapping_order,
                    ];
                }
                $root_relative_candidates[$source_path]['replacements'][$target_path] = true;
            }

            ++$mapping_order;
        }

        foreach ($network_path_candidates as $candidate) {
            if (count($candidate['replacements']) !== 1) {
                continue;
            }
            $this->network_path_mappings[] = [
                'source_prefix' => $candidate['source_prefix'],
                'source_path' => $candidate['source_path'],
                'replacement_base' => (string) array_key_first($candidate['replacements']),
                'order' => $candidate['order'],
            ];
        }
        foreach ($root_relative_candidates as $candidate) {
            if (count($candidate['replacements']) !== 1) {
                continue;
            }
            $this->root_relative_mappings[] = [
                'source_path' => $candidate['source_path'],
                'replacement_base' => (string) array_key_first($candidate['replacements']),
                'order' => $candidate['order'],
            ];
        }

        $this->source_domains = array_values($source_domains);
        $this->source_paths = array_values($source_paths);
        $sort_mappings = static function (array $first, array $second): int {
            $first_length = strlen($first['source_prefix'] ?? '')
                + strlen($first['source_path']);
            $second_length = strlen($second['source_prefix'] ?? '')
                + strlen($second['source_path']);

            return $second_length <=> $first_length
                ?: $first['order'] <=> $second['order'];
        };
        usort($this->absolute_mappings, $sort_mappings);
        usort($this->network_path_mappings, $sort_mappings);
        usort($this->root_relative_mappings, $sort_mappings);
    }

    /**
     * Rewrite URLs in one decoded database value.
     *
     * @param string      $value        Decoded value.
     * @param string|null $content_type Null for plain text, block_markup for
     *                                  known post markup, or skip for no change.
     */
    public function rewrite(string $value, ?string $content_type = null): string
    {
        if ($value === '' || $content_type === 'skip') {
            return $value;
        }

        $content_type = $content_type ?? self::PLAIN_TEXT;
        $cache_key = sha1($content_type . "\0" . $value);
        $cached = $this->get_cached_rewrite($cache_key, $content_type, $value);
        if ($cached !== null) {
            return $cached;
        }

        if (PhpSerializationProcessor::has_serialization_token_prefix($value)) {
            $rewritten = $this->rewrite_complete_php_serialization($value, $content_type);
            if ($rewritten === null) {
                return $this->cache_rewrite($cache_key, $content_type, $value, $value);
            }

            return $this->cache_rewrite($cache_key, $content_type, $value, $rewritten);
        }

        if ($this->could_be_json_document($value)) {
            $rewritten = $this->rewrite_complete_json($value, $content_type);
            if ($rewritten === null) {
                $rewritten = $this->rewrite_css_urls($value, true, false, true);
                if ($rewritten === null) {
                    $shortcodes = new ShortcodeSpanProcessor($value);
                    if (!$shortcodes->has_shortcode_prefix()) {
                        return $this->cache_rewrite($cache_key, $content_type, $value, $value);
                    }

                    $rewritten = $this->rewrite_ambiguous_text($value, false, $shortcodes);
                }
            }

            return $this->cache_rewrite($cache_key, $content_type, $value, $rewritten);
        }

        if ($content_type === self::BLOCK_MARKUP) {
            $rewritten = $this->rewrite_block_markup($value);
            return $this->cache_rewrite($cache_key, $content_type, $value, $rewritten);
        }

        $css_result = $this->rewrite_css_urls($value, true);
        if ($css_result !== null) {
            return $this->cache_rewrite($cache_key, $content_type, $value, $css_result);
        }

        $rewritten = $this->rewrite_ambiguous_text($value);

        return $this->cache_rewrite($cache_key, $content_type, $value, $rewritten);
    }

    /**
     * Rewrite a decoded value known by the SQL layer to contain block markup.
     */
    public function rewrite_known_block_markup_value(string $value): string
    {
        return $this->rewrite($value, self::BLOCK_MARKUP);
    }

    /**
     * Return whether decoded bytes might represent a configured source URL.
     */
    public function value_might_contain_source_domain(string $value): bool
    {
        if ($this->source_domains === []) {
            return false;
        }

        foreach ($this->source_domains as $domain) {
            if (stripos($value, $domain) !== false) {
                return true;
            }
        }

        if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $value) === 1) {
            return true;
        }
        if (strpos($value, '\\') !== false || strpos($value, '&') !== false) {
            return true;
        }
        foreach ($this->source_paths as $source_path) {
            if (strpos($value, $source_path) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rewrite a complete PHP serialization without evaluating it.
     *
     * @return string|null Rewritten bytes, or null when malformed.
     */
    private function rewrite_complete_php_serialization(string $value, string $content_type): ?string
    {
        $processor = new PhpSerializationProcessor($value);
        if ($processor->is_malformed()) {
            return null;
        }

        while ($processor->next_value()) {
            $original = $processor->get_value();
            $rewritten = $this->rewrite($original, $content_type);
            if ($rewritten !== $original) {
                $processor->set_value($rewritten);
            }
        }

        return $processor->get_updated_serialization();
    }

    /**
     * Rewrite a complete JSON document through lexical string-value spans.
     *
     * @return string|null Rewritten bytes, or null when malformed.
     */
    private function rewrite_complete_json(string $value, string $content_type): ?string
    {
        $iterator = new JsonStringIterator($value);
        if ($iterator->is_malformed()) {
            return null;
        }

        while ($iterator->next_value()) {
            $original = $iterator->get_value();
            $rewritten = $this->rewrite($original, $content_type);
            if ($rewritten !== $original) {
                $literal_spans = $this->get_literal_url_replacements(
                    $original,
                    $this->find_embedded_structured_spans($original)
                );
                if (
                    $literal_spans !== []
                    && $this->apply_replacements($original, $literal_spans) === $rewritten
                ) {
                    $iterator->set_value_with_spans($literal_spans);
                } else {
                    $iterator->set_value($rewritten);
                }
            }
        }

        return $iterator->get_result();
    }

    /**
     * Return whether the first non-whitespace byte claims a JSON container or string.
     */
    private function could_be_json_document(string $value): bool
    {
        $length = strlen($value);
        for ($position = 0; $position < $length; ++$position) {
            $byte = $value[$position];
            if (strpos(" \t\r\n", $byte) !== false) {
                continue;
            }

            if ($byte === '"') {
                return true;
            }

            return ( $byte === '{' || $byte === '[' )
                && $this->looks_like_embedded_json_at($value, $position);
        }

        return false;
    }

    /**
     * Rewrite a non-structured leaf while protecting embedded structured spans.
     */
    private function rewrite_ambiguous_text(
        string $value,
        bool $allow_relative = false,
        ?ShortcodeSpanProcessor $shortcodes = null
    ): string {
        $shortcodes = $shortcodes ?? new ShortcodeSpanProcessor($value);
        if ($shortcodes->is_malformed()) {
            return $value;
        }

        $tokens = [];
        foreach ($shortcodes->get_tokens() as $token) {
            $raw_token = substr($value, $token['start'], $token['length']);
            if ($this->is_css_attribute_selector_token($raw_token)) {
                $token['values'] = [];
            }
            $tokens[] = $token;
        }
        if ($tokens === []) {
            return $this->rewrite_literal_urls(
                $value,
                $this->find_embedded_structured_spans($value),
                $allow_relative
            );
        }

        $output = '';
        $cursor = 0;
        foreach ($tokens as $token) {
            $outside = substr($value, $cursor, $token['start'] - $cursor);
            $output .= $this->rewrite_plain_text_gap($outside, $allow_relative);

            $raw_token = substr($value, $token['start'], $token['length']);
            $replacements = [];
            $rewritten_attribute_values = [];
            $kept_wordpress_attribute_values = true;
            foreach ($token['values'] as $value_span) {
                $raw_attribute_value = substr(
                    $value,
                    $value_span['start'],
                    $value_span['length']
                );
                if ($value_span['quoted']) {
                    $rewritten_attribute_value = $this->rewrite(
                        $raw_attribute_value,
                        self::PLAIN_TEXT
                    );
                } else {
                    $rewritten_attribute_value = $this->rewrite_literal_urls(
                        $raw_attribute_value,
                        $this->find_embedded_structured_spans($raw_attribute_value),
                        $allow_relative
                    );
                }
                $rewritten_attribute_values[] = $rewritten_attribute_value;
                if ($rewritten_attribute_value !== $raw_attribute_value) {
                    $raw_value_has_normalized_space =
                        strpos($raw_attribute_value, "\xC2\xA0") !== false
                        || strpos($raw_attribute_value, "\xE2\x80\x8B") !== false;
                    $rewritten_value_has_normalized_space =
                        strpos($rewritten_attribute_value, "\xC2\xA0") !== false
                        || strpos($rewritten_attribute_value, "\xE2\x80\x8B") !== false;
                    $normalized_raw_attribute_value = preg_replace(
                        '/[\x{00a0}\x{200b}]+/u',
                        ' ',
                        $raw_attribute_value
                    );
                    $normalized_rewritten_attribute_value = preg_replace(
                        '/[\x{00a0}\x{200b}]+/u',
                        ' ',
                        $rewritten_attribute_value
                    );
                    $kept_wordpress_decoded_value =
                        is_string($normalized_raw_attribute_value)
                        && is_string($normalized_rewritten_attribute_value);
                    if ($kept_wordpress_decoded_value) {
                        $wordpress_raw_attribute_value = stripcslashes(
                            $normalized_raw_attribute_value
                        );
                        $wordpress_rewritten_attribute_value = stripcslashes(
                            $normalized_rewritten_attribute_value
                        );
                        $raw_attribute_html_is_unbalanced =
                            strpos($wordpress_raw_attribute_value, '<') !== false
                            && preg_match(
                                '/\A[^<]*+(?:<[^>]*+>[^<]*+)*+\z/',
                                $wordpress_raw_attribute_value
                            ) !== 1;
                        $rewritten_attribute_html_is_unbalanced =
                            strpos($wordpress_rewritten_attribute_value, '<') !== false
                            && preg_match(
                                '/\A[^<]*+(?:<[^>]*+>[^<]*+)*+\z/',
                                $wordpress_rewritten_attribute_value
                            ) !== 1;
                        if (
                            $raw_attribute_html_is_unbalanced
                            || $rewritten_attribute_html_is_unbalanced
                        ) {
                            $kept_wordpress_decoded_value = false;
                        } elseif (
                            $wordpress_raw_attribute_value !== $raw_attribute_value
                            || $wordpress_rewritten_attribute_value
                                !== $rewritten_attribute_value
                        ) {
                            if ($value_span['quoted']) {
                                $expected_wordpress_attribute_value = $this->rewrite(
                                    $wordpress_raw_attribute_value,
                                    self::PLAIN_TEXT
                                );
                            } else {
                                $expected_wordpress_attribute_value =
                                    $this->rewrite_literal_urls(
                                        $wordpress_raw_attribute_value,
                                        $this->find_embedded_structured_spans(
                                            $wordpress_raw_attribute_value
                                        ),
                                        $allow_relative
                                    );
                            }
                            $kept_wordpress_decoded_value =
                                $wordpress_rewritten_attribute_value
                                === $expected_wordpress_attribute_value;
                        }
                    }
                    if (
                        strpos($raw_attribute_value, ']') !== false
                        || strpos($rewritten_attribute_value, ']') !== false
                        || !$kept_wordpress_decoded_value
                        || ( !$value_span['quoted']
                            && ( $raw_value_has_normalized_space
                                || $rewritten_value_has_normalized_space
                                || strpbrk(
                                    $raw_attribute_value,
                                    " \t\f\r\n\v"
                                ) !== false
                                || strpbrk(
                                    $rewritten_attribute_value,
                                    " \t\f\r\n\v"
                                ) !== false ) )
                        || ( !$value_span['quoted']
                            && $value_span['named']
                            && ( strpbrk($raw_attribute_value, "'\"") !== false
                                || strpbrk(
                                    $rewritten_attribute_value,
                                    "'\""
                                ) !== false ) )
                    ) {
                        $kept_wordpress_attribute_values = false;
                    }
                    $replacements[] = [
                        'start' => $value_span['start'] - $token['start'],
                        'length' => $value_span['length'],
                        'text' => $rewritten_attribute_value,
                    ];
                }
            }
            $rewritten_token = $this->apply_replacements($raw_token, $replacements);
            if ($rewritten_token !== $raw_token) {
                $rewritten_shortcodes = new ShortcodeSpanProcessor($rewritten_token);
                $rewritten_tokens = $rewritten_shortcodes->get_tokens();
                $kept_attribute_boundaries = $kept_wordpress_attribute_values
                    && !$rewritten_shortcodes->is_malformed()
                    && count($rewritten_tokens) === 1
                    && $rewritten_tokens[0]['start'] === 0
                    && $rewritten_tokens[0]['length'] === strlen($rewritten_token)
                    && count($rewritten_tokens[0]['values']) === count($token['values']);
                if ($kept_attribute_boundaries) {
                    foreach ($rewritten_tokens[0]['values'] as $index => $rewritten_value_span) {
                        if (
                            $rewritten_value_span['quoted'] !== $token['values'][$index]['quoted']
                            || $rewritten_value_span['named']
                                !== $token['values'][$index]['named']
                            || substr(
                                $rewritten_token,
                                $rewritten_value_span['start'],
                                $rewritten_value_span['length']
                            ) !== $rewritten_attribute_values[$index]
                        ) {
                            $kept_attribute_boundaries = false;
                            break;
                        }
                    }
                }
                if (!$kept_attribute_boundaries) {
                    $rewritten_token = $raw_token;
                }
            }
            $output .= $rewritten_token;
            $cursor = $token['start'] + $token['length'];
        }

        $outside = substr($value, $cursor);
        $output .= $this->rewrite_plain_text_gap($outside, $allow_relative);

        return $output;
    }

    /**
     * Rewrite a gap outside shortcode ownership as CSS or literal text.
     */
    private function rewrite_plain_text_gap(string $value, bool $allow_relative): string
    {
        $css_rewrite = $this->rewrite_css_urls($value, $allow_relative);
        if ($css_rewrite !== null) {
            return $css_rewrite;
        }

        return $this->rewrite_literal_urls(
            $value,
            $this->find_embedded_structured_spans($value),
            $allow_relative
        );
    }

    /**
     * Rewrite CSS URL value spans and preserve all other source bytes.
     *
     * @return string|null Updated CSS or mixed text around protected URL
     *                     functions, or null when no CSS syntax is claimed.
     */
    private function rewrite_css_urls(
        string $css,
        bool $allow_relative,
        bool $assume_css = false,
        bool $require_complete_rule = false
    ): ?string {
        $rewrite = $this->get_css_url_rewrite($css, $allow_relative);
        if (
            $rewrite === null
            || ( $require_complete_rule && !$rewrite['has_complete_rule'] )
        ) {
            return null;
        }
        if (!$assume_css) {
            $shortcodes = new ShortcodeSpanProcessor($css);
            $shortcode_tokens = [];
            foreach ($shortcodes->get_tokens() as $shortcode_token) {
                $raw_shortcode = substr(
                    $css,
                    $shortcode_token['start'],
                    $shortcode_token['length']
                );
                if (!$this->is_css_attribute_selector_token($raw_shortcode)) {
                    $shortcode_tokens[] = $shortcode_token;
                }
            }
            if (
                $shortcodes->is_malformed()
                || (
                    !$rewrite['has_complete_rule']
                    && $shortcode_tokens !== []
                )
                || (
                    $rewrite['has_complete_rule']
                    && $shortcodes->has_shortcode_prefix()
                    && $shortcode_tokens !== []
                    && $shortcode_tokens[0]['values'] !== []
                )
            ) {
                return null;
            }
        }
        if ($assume_css || $rewrite['whole_value_owned']) {
            return $rewrite['result'];
        }

        $result = '';
        $cursor = 0;
        foreach ($rewrite['owned_spans'] as $owned_span) {
            $outside = substr($css, $cursor, $owned_span['start'] - $cursor);
            $result .= $this->rewrite_ambiguous_text($outside);

            $raw_owned_css = substr($css, $owned_span['start'], $owned_span['length']);
            $owned_replacements = [];
            foreach ($rewrite['replacements'] as $replacement) {
                if (
                    $replacement['start'] < $owned_span['start']
                    || $replacement['start'] + $replacement['length']
                        > $owned_span['start'] + $owned_span['length']
                ) {
                    continue;
                }
                $replacement['start'] -= $owned_span['start'];
                $owned_replacements[] = $replacement;
            }
            $result .= $this->apply_replacements($raw_owned_css, $owned_replacements);
            $cursor = $owned_span['start'] + $owned_span['length'];
        }

        return $result . $this->rewrite_ambiguous_text(substr($css, $cursor));
    }

    /**
     * Locate exact CSS URL replacements and return their applied result.
     *
     * @return array{
     *     result: string,
     *     replacements: array<int, array{start: int, length: int, text: string}>,
     *     owned_spans: array<int, array{start: int, length: int}>,
     *     whole_value_owned: bool,
     *     has_complete_rule: bool
     * }|null Null when the value claims neither CSS syntax nor a URL function.
     */
    private function get_css_url_rewrite(string $css, bool $allow_relative): ?array
    {
        $initial_scan = $this->scan_css_tokens($css, $allow_relative, true);

        $has_complete_rule = false;
        $stylesheet_spans = $this->get_css_stylesheet_spans(
            $css,
            $has_complete_rule
        );
        $owned_spans = array_merge(
            $initial_scan['owned_spans'],
            $stylesheet_spans,
        );
        $is_declaration_list = $this->looks_like_css_declaration_list($css);
        if ($is_declaration_list) {
            $owned_spans[] = [
                'start' => 0,
                'length' => strlen($css),
            ];
        }
        if ($owned_spans === []) {
            return null;
        }

        usort(
            $owned_spans,
            static fn(array $first, array $second): int => $first['start'] <=> $second['start']
        );
        $merged_spans = [];
        foreach ($owned_spans as $span) {
            if ($span['length'] <= 0) {
                continue;
            }
            $last_index = count($merged_spans) - 1;
            if (
                $last_index >= 0
                && $span['start']
                    < $merged_spans[$last_index]['start'] + $merged_spans[$last_index]['length']
            ) {
                $merged_spans[$last_index]['length'] = max(
                    $merged_spans[$last_index]['length'],
                    $span['start'] + $span['length'] - $merged_spans[$last_index]['start']
                );
                continue;
            }
            $merged_spans[] = $span;
        }

        $replacements = [];
        foreach ($merged_spans as $span) {
            $raw_span = substr($css, $span['start'], $span['length']);
            $span_is_declaration_list = $is_declaration_list
                && $span['start'] === 0
                && $span['length'] === strlen($css);
            $span_scan = $this->scan_css_tokens(
                $raw_span,
                $allow_relative,
                !$span_is_declaration_list
            );
            if (!$span_scan['valid']) {
                continue;
            }
            foreach ($span_scan['replacements'] as $replacement) {
                $replacement['start'] += $span['start'];
                $replacements[] = $replacement;
            }
        }

        $whole_value_owned = $merged_spans !== [];
        $coverage_position = 0;
        foreach ($merged_spans as $span) {
            if (trim(substr($css, $coverage_position, $span['start'] - $coverage_position)) !== '') {
                $whole_value_owned = false;
                break;
            }
            $coverage_position = $span['start'] + $span['length'];
        }
        if ($whole_value_owned && trim(substr($css, $coverage_position)) !== '') {
            $whole_value_owned = false;
        }

        return [
            'result' => $this->apply_replacements($css, $replacements),
            'replacements' => $replacements,
            'owned_spans' => $merged_spans,
            'whole_value_owned' => $whole_value_owned,
            'has_complete_rule' => $has_complete_rule,
        ];
    }

    /**
     * Scan one CSS-owned span for exact URL values and protected syntax.
     *
     * @return array{
     *     replacements: array<int, array{start: int, length: int, text: string}>,
     *     owned_spans: array<int, array{start: int, length: int}>,
     *     valid: bool
     * }
     */
    private function scan_css_tokens(
        string $css,
        bool $allow_relative,
        bool $allow_import_strings
    ): array {
        $processor = CSSProcessor::create($css);
        $expected_closers = [];
        $replacements = [];
        $owned_spans = [];
        $url_function_state = null;
        $url_function_start = null;
        $import_string_pending = false;
        $at_top_level_rule_start = true;
        $valid = true;

        while ($processor->next_token()) {
            $type = $processor->get_token_type();
            $raw_token = $processor->get_unnormalized_token() ?? '';
            $token_start = $processor->get_token_start();
            $token_length = $processor->get_token_length();
            $is_trivia = $type === CSSProcessor::TOKEN_WHITESPACE
                || $type === CSSProcessor::TOKEN_COMMENT;
            $direct_context_index = count($expected_closers) - 1;
            $direct_function = $direct_context_index < 0
                ? null
                : $expected_closers[$direct_context_index]['function'];
            $image_set_expects_image = $direct_context_index >= 0
                && $expected_closers[$direct_context_index]['image_set_expects_image'];

            if (
                ( $type === CSSProcessor::TOKEN_COMMENT
                    || $type === CSSProcessor::TOKEN_BAD_STRING )
                && $token_start !== null
                && $token_length !== null
            ) {
                $owned_spans[] = [
                    'start' => $token_start,
                    'length' => $token_length,
                ];
            }

            if ($type === CSSProcessor::TOKEN_BAD_URL) {
                if ($token_start !== null && $token_length !== null) {
                    $owned_spans[] = [
                        'start' => $token_start,
                        'length' => $token_length,
                    ];
                }
                $valid = false;
                break;
            }
            if ($type === CSSProcessor::TOKEN_BAD_STRING) {
                $valid = false;
                break;
            }
            if ($type === CSSProcessor::TOKEN_COMMENT && substr($raw_token, -2) !== '*/') {
                $valid = false;
                break;
            }
            if ($type === CSSProcessor::TOKEN_STRING) {
                $first_quote = $raw_token[0] ?? '';
                if (
                    ( $first_quote !== '"' && $first_quote !== "'" )
                    || substr($raw_token, -1) !== $first_quote
                ) {
                    $valid = false;
                    break;
                }
            }

            $is_url_value = $type === CSSProcessor::TOKEN_STRING
                && $image_set_expects_image
                && (
                    strcasecmp( (string) $direct_function, 'image-set') === 0
                    || strcasecmp( (string) $direct_function, '-webkit-image-set') === 0
                );
            if ($import_string_pending) {
                if ($is_trivia) {
                    continue;
                }
                $import_string_pending = false;
                if ($type === CSSProcessor::TOKEN_STRING) {
                    $is_url_value = true;
                }
            }
            if ($url_function_state === 'awaiting-value') {
                if ($is_trivia) {
                    continue;
                }
                if ($type !== CSSProcessor::TOKEN_STRING) {
                    $valid = false;
                    break;
                }
                $is_url_value = true;
                $url_function_state = 'awaiting-closer';
            } elseif ($url_function_state === 'awaiting-closer') {
                if ($is_trivia) {
                    continue;
                }
                if ($type !== CSSProcessor::TOKEN_RIGHT_PAREN) {
                    $valid = false;
                    break;
                }
                if (
                    $url_function_start === null
                    || $token_start === null
                    || $token_length === null
                ) {
                    $valid = false;
                    break;
                }
                $owned_spans[] = [
                    'start' => $url_function_start,
                    'length' => $token_start + $token_length - $url_function_start,
                ];
                $url_function_state = null;
                $url_function_start = null;
            }

            if (
                $direct_context_index >= 0
                && (
                    strcasecmp( (string) $direct_function, 'image-set') === 0
                    || strcasecmp( (string) $direct_function, '-webkit-image-set') === 0
                )
                && !$is_trivia
                && $type !== CSSProcessor::TOKEN_RIGHT_PAREN
            ) {
                $expected_closers[$direct_context_index]['image_set_expects_image'] =
                    $type === CSSProcessor::TOKEN_COMMA;
            }

            if ($type === CSSProcessor::TOKEN_FUNCTION) {
                $function_name = strtolower( (string) $processor->get_token_value());
                $expected_closers[] = [
                    'closer' => ')',
                    'function' => $function_name,
                    'start' => $token_start,
                    'image_set_expects_image' => $function_name === 'image-set'
                        || $function_name === '-webkit-image-set',
                ];
                if ($function_name === 'url') {
                    $url_function_start = $token_start;
                    if ($url_function_start === null) {
                        $valid = false;
                        break;
                    }
                    $url_function_state = 'awaiting-value';
                }
            } elseif ($type === CSSProcessor::TOKEN_LEFT_PAREN) {
                $expected_closers[] = [
                    'closer' => ')',
                    'function' => null,
                    'start' => null,
                    'image_set_expects_image' => false,
                ];
            } elseif ($type === CSSProcessor::TOKEN_LEFT_BRACKET) {
                $expected_closers[] = [
                    'closer' => ']',
                    'function' => null,
                    'start' => null,
                    'image_set_expects_image' => false,
                ];
            } elseif ($type === CSSProcessor::TOKEN_LEFT_BRACE) {
                $expected_closers[] = [
                    'closer' => '}',
                    'function' => null,
                    'start' => null,
                    'image_set_expects_image' => false,
                ];
            } elseif (
                $type === CSSProcessor::TOKEN_RIGHT_PAREN
                || $type === CSSProcessor::TOKEN_RIGHT_BRACKET
                || $type === CSSProcessor::TOKEN_RIGHT_BRACE
            ) {
                $expected = array_pop($expected_closers);
                if ($expected === null || $expected['closer'] !== $raw_token) {
                    $valid = false;
                    break;
                }
                if (
                    $expected['start'] !== null
                    && $token_start !== null
                    && $token_length !== null
                    && (
                        $expected['function'] === 'image-set'
                        || $expected['function'] === '-webkit-image-set'
                    )
                ) {
                    $owned_spans[] = [
                        'start' => $expected['start'],
                        'length' => $token_start + $token_length - $expected['start'],
                    ];
                }
            }

            if ($type === CSSProcessor::TOKEN_URL) {
                $is_url_value = true;
                if ($token_start === null || $token_length === null) {
                    $valid = false;
                    break;
                }
                $owned_spans[] = [
                    'start' => $token_start,
                    'length' => $token_length,
                ];
                if (substr(rtrim($raw_token), -1) !== ')') {
                    $valid = false;
                    break;
                }
            }

            if (
                $allow_import_strings
                && $at_top_level_rule_start
                && $type === CSSProcessor::TOKEN_AT_KEYWORD
                && strcasecmp( (string) $processor->get_token_value(), 'import') === 0
            ) {
                $import_string_pending = true;
            }

            if ($is_url_value) {
                $value_start = $processor->get_token_value_start();
                $value_length = $processor->get_token_value_length();
                if ($value_start === null || $value_length === null) {
                    $valid = false;
                    break;
                }
                if (
                    $type === CSSProcessor::TOKEN_STRING
                    && $token_start !== null
                    && $token_length !== null
                ) {
                    $owned_spans[] = [
                        'start' => $token_start,
                        'length' => $token_length,
                    ];
                }
                $raw_value = substr($css, $value_start, $value_length);
                $decoded_value = $processor->get_token_value();
                if (!is_string($decoded_value)) {
                    $decoded_value = $raw_value;
                }
                $quote = $type === CSSProcessor::TOKEN_STRING
                    ? ( $raw_token[0] ?? '' )
                    : '';
                $rewritten_value = $this->rewrite_css_encoded_value(
                    $raw_value,
                    $decoded_value,
                    $quote,
                    $allow_relative
                );
                if ($rewritten_value !== $raw_value) {
                    $replacements[] = [
                        'start' => $value_start,
                        'length' => $value_length,
                        'text' => $rewritten_value,
                    ];
                }
            }

            if (!$is_trivia) {
                $at_top_level_rule_start = (
                    $type === CSSProcessor::TOKEN_SEMICOLON
                    || $type === CSSProcessor::TOKEN_RIGHT_BRACE
                ) && $expected_closers === [];
            }
        }

        if ($url_function_start !== null) {
            $owned_spans[] = [
                'start' => $url_function_start,
                'length' => strlen($css) - $url_function_start,
            ];
        }

        return [
            'replacements' => $replacements,
            'owned_spans' => $owned_spans,
            'valid' => $valid
                && $expected_closers === []
                && $url_function_state === null,
        ];
    }

    /**
     * Return whether one exact bracket token follows CSS attribute-selector grammar.
     */
    private function is_css_attribute_selector_token(string $candidate): bool
    {
        $processor = CSSProcessor::create($candidate);
        $tokens = [];
        while ($processor->next_token()) {
            $type = $processor->get_token_type();
            $raw_token = $processor->get_unnormalized_token() ?? '';
            if ($type === CSSProcessor::TOKEN_WHITESPACE) {
                continue;
            }
            if ($type === CSSProcessor::TOKEN_COMMENT) {
                if (substr($raw_token, -2) !== '*/') {
                    return false;
                }
                continue;
            }
            if (
                $type === null
                || $type === CSSProcessor::TOKEN_BAD_STRING
                || $type === CSSProcessor::TOKEN_BAD_URL
            ) {
                return false;
            }

            $token_start = $processor->get_token_start();
            $token_length = $processor->get_token_length();
            if ($token_start === null || $token_length === null) {
                return false;
            }
            if ($type === CSSProcessor::TOKEN_STRING) {
                $quote = $raw_token[0] ?? '';
                if (
                    ( $quote !== '"' && $quote !== "'" )
                    || substr($raw_token, -1) !== $quote
                ) {
                    return false;
                }
            }

            $token_value = $processor->get_token_value();
            $tokens[] = [
                'type' => $type,
                'value' => is_string($token_value) ? $token_value : $raw_token,
                'start' => $token_start,
                'end' => $token_start + $token_length,
            ];
        }

        $token_count = count($tokens);
        $closing_position = $token_count - 1;
        if (
            $token_count < 3
            || $tokens[0]['type'] !== CSSProcessor::TOKEN_LEFT_BRACKET
            || $tokens[0]['start'] !== 0
            || $tokens[$closing_position]['type'] !== CSSProcessor::TOKEN_RIGHT_BRACKET
            || $tokens[$closing_position]['end'] !== strlen($candidate)
        ) {
            return false;
        }

        $position = 1;
        if ($tokens[$position]['type'] === CSSProcessor::TOKEN_IDENT) {
            $name_end = $tokens[$position]['end'];
            ++$position;
            if (
                $position + 1 < $closing_position
                && $tokens[$position]['type'] === CSSProcessor::TOKEN_DELIM
                && $tokens[$position]['value'] === '|'
                && $tokens[$position + 1]['type'] === CSSProcessor::TOKEN_IDENT
                && $name_end === $tokens[$position]['start']
                && $tokens[$position]['end'] === $tokens[$position + 1]['start']
            ) {
                $position += 2;
            }
        } elseif (
            $tokens[$position]['type'] === CSSProcessor::TOKEN_DELIM
            && ( $tokens[$position]['value'] === '*' || $tokens[$position]['value'] === '|' )
        ) {
            $namespace_start = $position;
            if ($tokens[$position]['value'] === '*') {
                ++$position;
                if (
                    $position >= $closing_position
                    || $tokens[$position]['type'] !== CSSProcessor::TOKEN_DELIM
                    || $tokens[$position]['value'] !== '|'
                    || $tokens[$namespace_start]['end'] !== $tokens[$position]['start']
                ) {
                    return false;
                }
            }
            ++$position;
            if (
                $position >= $closing_position
                || $tokens[$position]['type'] !== CSSProcessor::TOKEN_IDENT
                || $tokens[$position - 1]['end'] !== $tokens[$position]['start']
            ) {
                return false;
            }
            ++$position;
        } else {
            return false;
        }

        if ($position === $closing_position) {
            return true;
        }
        if (
            $tokens[$position]['type'] !== CSSProcessor::TOKEN_DELIM
            || strpos('=~|^$*', $tokens[$position]['value']) === false
        ) {
            return false;
        }
        if ($tokens[$position]['value'] === '=') {
            ++$position;
        } else {
            $matcher_start = $position;
            ++$position;
            if (
                $position >= $closing_position
                || $tokens[$position]['type'] !== CSSProcessor::TOKEN_DELIM
                || $tokens[$position]['value'] !== '='
                || $tokens[$matcher_start]['end'] !== $tokens[$position]['start']
            ) {
                return false;
            }
            ++$position;
        }

        if (
            $position >= $closing_position
            || (
                $tokens[$position]['type'] !== CSSProcessor::TOKEN_IDENT
                && $tokens[$position]['type'] !== CSSProcessor::TOKEN_STRING
            )
        ) {
            return false;
        }
        ++$position;
        if ($position < $closing_position) {
            if (
                $tokens[$position - 1]['end'] === $tokens[$position]['start']
                ||
                $tokens[$position]['type'] !== CSSProcessor::TOKEN_IDENT
                || (
                    strcasecmp($tokens[$position]['value'], 'i') !== 0
                    && strcasecmp($tokens[$position]['value'], 's') !== 0
                )
            ) {
                return false;
            }
            ++$position;
        }

        return $position === $closing_position;
    }

    /**
     * Locate complete top-level CSS rules before any trailing non-CSS bytes.
     *
     * @param bool $has_complete_rule Set when at least one complete rule was found.
     * @return array<int, array{start: int, length: int}> Rule spans.
     */
    private function get_css_stylesheet_spans(
        string $css,
        bool &$has_complete_rule
    ): array
    {
        $has_complete_rule = false;
        $length = strlen($css);
        $position = 0;
        $spans = [];
        $attributed_shortcodes = [];
        $shortcodes = new ShortcodeSpanProcessor($css);
        foreach ($shortcodes->get_tokens() as $shortcode) {
            $raw_shortcode = substr($css, $shortcode['start'], $shortcode['length']);
            if (
                $shortcode['values'] !== []
                && !$this->is_css_attribute_selector_token($raw_shortcode)
            ) {
                $attributed_shortcodes[$shortcode['start']] = true;
            }
        }

        while ($position < $length) {
            while ($position < $length) {
                $byte = $css[$position];
                if (strpos(" \t\r\n\f", $byte) !== false) {
                    ++$position;
                    continue;
                }
                if ($byte !== '/' || ( $css[$position + 1] ?? '' ) !== '*') {
                    break;
                }

                $comment_end = strpos($css, '*/', $position + 2);
                if ($comment_end === false) {
                    return $spans;
                }
                $position = $comment_end + 2;
            }
            if ($position === $length) {
                return $spans;
            }
            if (isset($attributed_shortcodes[$position])) {
                return $spans;
            }

            $rule_start = $position;
            $is_at_rule = $css[$position] === '@';
            $first_prelude_byte = $css[$position];
            $second_prelude_byte = $css[$position + 1] ?? '';
            $strong_rule_claim = $is_at_rule
                || $first_prelude_byte === '['
                || $first_prelude_byte === '*'
                || (
                    strpos('.#:', $first_prelude_byte) !== false
                    && $second_prelude_byte !== ''
                    && strpos(" \t\r\n\f", $second_prelude_byte) === false
                );
            $square_depth = 0;
            $parenthesis_depth = 0;
            $quote = null;
            $saw_attribute_selector = false;
            $last_attribute_selector_end = null;
            $saw_prelude_content = false;
            $body_start = null;
            for (; $position < $length; ++$position) {
                $byte = $css[$position];
                if ($quote !== null) {
                    if ($byte === '\\') {
                        ++$position;
                    } elseif ($byte === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($byte === '/' && ( $css[$position + 1] ?? '' ) === '*') {
                    $comment_end = strpos($css, '*/', $position + 2);
                    if ($comment_end === false) {
                        if ($strong_rule_claim) {
                            $spans[] = [
                                'start' => $rule_start,
                                'length' => $length - $rule_start,
                            ];
                        }
                        return $spans;
                    }
                    $position = $comment_end + 1;
                    continue;
                }
                if (strpos(" \t\r\n\f", $byte) !== false) {
                    continue;
                }
                if ($byte === '\\') {
                    $saw_prelude_content = true;
                    ++$position;
                    continue;
                }
                if ($byte === '"' || $byte === "'") {
                    if (
                        !$is_at_rule
                        && $square_depth === 0
                        && $parenthesis_depth === 0
                    ) {
                        break;
                    }
                    $saw_prelude_content = true;
                    $quote = $byte;
                    continue;
                }
                if ($byte === '[') {
                    $saw_prelude_content = true;
                    $saw_attribute_selector = true;
                    $strong_rule_claim = true;
                    ++$square_depth;
                    continue;
                }
                if ($byte === ']') {
                    if ($square_depth === 0) {
                        if ($strong_rule_claim) {
                            $spans[] = [
                                'start' => $rule_start,
                                'length' => $length - $rule_start,
                            ];
                        }
                        return $spans;
                    }
                    --$square_depth;
                    if ($square_depth === 0) {
                        $last_attribute_selector_end = $position + 1;
                    }
                    continue;
                }
                if ($byte === '(') {
                    $saw_prelude_content = true;
                    ++$parenthesis_depth;
                    continue;
                }
                if ($byte === ')') {
                    if ($parenthesis_depth === 0) {
                        if ($strong_rule_claim) {
                            $spans[] = [
                                'start' => $rule_start,
                                'length' => $length - $rule_start,
                            ];
                        }
                        return $spans;
                    }
                    --$parenthesis_depth;
                    continue;
                }
                if ($square_depth !== 0 || $parenthesis_depth !== 0) {
                    $saw_prelude_content = true;
                    continue;
                }
                if ($byte === '{') {
                    if (!$saw_prelude_content) {
                        return $spans;
                    }
                    $body_start = $position + 1;
                    break;
                }
                if ($byte === ';' && $is_at_rule) {
                    $has_complete_rule = true;
                    $spans[] = [
                        'start' => $rule_start,
                        'length' => $position + 1 - $rule_start,
                    ];
                    ++$position;
                    continue 2;
                }
                if ($byte === '=' || $byte === ';' || $byte === '/' || $byte === '}') {
                    break;
                }
                $saw_prelude_content = true;
            }
            if ($body_start === null) {
                $selector_end = $last_attribute_selector_end ?? $length;
                $candidate = substr($css, $rule_start, $selector_end - $rule_start);
                $candidate_shortcodes = new ShortcodeSpanProcessor($candidate);
                $candidate_shortcode_tokens = $candidate_shortcodes->get_tokens();
                $is_complete_shortcode = !$candidate_shortcodes->is_malformed()
                    && count($candidate_shortcode_tokens) === 1
                    && $candidate_shortcode_tokens[0]['start'] === 0
                    && $candidate_shortcode_tokens[0]['length'] === strlen($candidate)
                    && !$this->is_css_attribute_selector_token($candidate);
                if (
                    $strong_rule_claim
                    && (
                        $square_depth !== 0
                        || $parenthesis_depth !== 0
                        || $quote !== null
                    )
                ) {
                    $spans[] = [
                        'start' => $rule_start,
                        'length' => $length - $rule_start,
                    ];
                } elseif (
                    !$is_complete_shortcode
                    && $strong_rule_claim
                    && $saw_attribute_selector
                ) {
                    $spans[] = [
                        'start' => $rule_start,
                        'length' => $selector_end - $rule_start,
                    ];
                }
                return $spans;
            }

            $curly_depth = 1;
            $body_quote = null;
            $body_end = null;
            for (
                $body_position = $body_start;
                $body_position < $length;
                ++$body_position
            ) {
                $body_byte = $css[$body_position];
                if ($body_quote !== null) {
                    if ($body_byte === '\\') {
                        ++$body_position;
                    } elseif ($body_byte === $body_quote) {
                        $body_quote = null;
                    }
                    continue;
                }
                if ($body_byte === '/' && ( $css[$body_position + 1] ?? '' ) === '*') {
                    $comment_end = strpos($css, '*/', $body_position + 2);
                    if ($comment_end === false) {
                        $spans[] = [
                            'start' => $rule_start,
                            'length' => $length - $rule_start,
                        ];
                        return $spans;
                    }
                    $body_position = $comment_end + 1;
                    continue;
                }
                if ($body_byte === '"' || $body_byte === "'") {
                    $body_quote = $body_byte;
                    continue;
                }
                if ($body_byte === '\\') {
                    ++$body_position;
                    continue;
                }
                if ($body_byte === '{') {
                    ++$curly_depth;
                } elseif ($body_byte === '}') {
                    --$curly_depth;
                    if ($curly_depth === 0) {
                        $body_end = $body_position;
                        break;
                    }
                }
            }
            if ($body_end === null) {
                $spans[] = [
                    'start' => $rule_start,
                    'length' => $length - $rule_start,
                ];
                return $spans;
            }

            $body = substr($css, $body_start, $body_end - $body_start);
            if (trim($body) !== '' && !$this->looks_like_css_block_contents($body)) {
                if ($strong_rule_claim) {
                    $spans[] = [
                        'start' => $rule_start,
                        'length' => $body_end + 1 - $rule_start,
                    ];
                }
                return $spans;
            }

            $has_complete_rule = true;
            $spans[] = [
                'start' => $rule_start,
                'length' => $body_end + 1 - $rule_start,
            ];
            $position = $body_end + 1;
        }

        return $spans;
    }

    /**
     * Return whether bytes form CSS declarations, nested rules, or both.
     */
    private function looks_like_css_block_contents(string $css): bool
    {
        $length = strlen($css);
        $position = 0;
        $saw_content = false;

        while ($position < $length) {
            while ($position < $length) {
                $byte = $css[$position];
                if (strpos(" \t\r\n\f;", $byte) !== false) {
                    ++$position;
                    continue;
                }
                if ($byte !== '/' || ( $css[$position + 1] ?? '' ) !== '*') {
                    break;
                }
                $comment_end = strpos($css, '*/', $position + 2);
                if ($comment_end === false) {
                    return false;
                }
                $saw_content = true;
                $position = $comment_end + 2;
            }
            if ($position === $length) {
                return $saw_content;
            }

            $property_processor = CSSProcessor::create(substr($css, $position));
            $property_length = null;
            if (
                $property_processor->next_token()
                && $property_processor->get_token_type() === CSSProcessor::TOKEN_IDENT
                && $property_processor->get_token_start() === 0
            ) {
                $property_length = $property_processor->get_token_length();
            }
            if ($property_length !== null) {
                $property = substr($css, $position, $property_length);
                $value_separator = $position + $property_length;
                while ($value_separator < $length) {
                    $byte = $css[$value_separator];
                    if (strpos(" \t\r\n\f", $byte) !== false) {
                        ++$value_separator;
                        continue;
                    }
                    if ($byte !== '/' || ( $css[$value_separator + 1] ?? '' ) !== '*') {
                        break;
                    }
                    $comment_end = strpos($css, '*/', $value_separator + 2);
                    if ($comment_end === false) {
                        return false;
                    }
                    $value_separator = $comment_end + 2;
                }
                if (( $css[$value_separator] ?? '' ) === ':') {
                    $value_start = $value_separator + 1;
                    $parenthesis_depth = 0;
                    $bracket_depth = 0;
                    $brace_depth = 0;
                    $quote = null;
                    $value_end = $length;
                    $next_position = $length;
                    for ($scan = $value_start; $scan < $length; ++$scan) {
                        $byte = $css[$scan];
                        if ($quote !== null) {
                            if ($byte === '\\') {
                                ++$scan;
                            } elseif ($byte === $quote) {
                                $quote = null;
                            }
                            continue;
                        }
                        if ($byte === '/' && ( $css[$scan + 1] ?? '' ) === '*') {
                            $comment_end = strpos($css, '*/', $scan + 2);
                            if ($comment_end === false) {
                                return false;
                            }
                            $scan = $comment_end + 1;
                            continue;
                        }
                        if ($byte === '"' || $byte === "'") {
                            $quote = $byte;
                        } elseif ($byte === '\\') {
                            ++$scan;
                        } elseif ($byte === '(') {
                            ++$parenthesis_depth;
                        } elseif ($byte === ')') {
                            if ($parenthesis_depth === 0) {
                                return false;
                            }
                            --$parenthesis_depth;
                        } elseif ($byte === '[') {
                            ++$bracket_depth;
                        } elseif ($byte === ']') {
                            if ($bracket_depth === 0) {
                                return false;
                            }
                            --$bracket_depth;
                        } elseif ($byte === '{') {
                            ++$brace_depth;
                        } elseif ($byte === '}') {
                            if ($brace_depth === 0) {
                                return false;
                            }
                            --$brace_depth;
                        } elseif (
                            $byte === ';'
                            && $parenthesis_depth === 0
                            && $bracket_depth === 0
                            && $brace_depth === 0
                        ) {
                            $value_end = $scan;
                            $next_position = $scan + 1;
                            break;
                        }
                    }
                    if (
                        $quote !== null
                        || $parenthesis_depth !== 0
                        || $bracket_depth !== 0
                        || $brace_depth !== 0
                    ) {
                        return false;
                    }

                    $value = trim(substr($css, $value_start, $value_end - $value_start));
                    $is_custom_property = strncmp($property, '--', 2) === 0;
                    if (
                        ( $value === '' && !$is_custom_property )
                        || (
                            preg_match('/\Ahttps?\z/i', $property) === 1
                            && strncmp($value, '//', 2) === 0
                        )
                    ) {
                        return false;
                    }

                    $saw_content = true;
                    $position = $next_position;
                    continue;
                }
            }

            $rule_start = $position;
            $is_at_rule = $css[$position] === '@';
            $square_depth = 0;
            $parenthesis_depth = 0;
            $quote = null;
            $body_start = null;
            for (; $position < $length; ++$position) {
                $byte = $css[$position];
                if ($quote !== null) {
                    if ($byte === '\\') {
                        ++$position;
                    } elseif ($byte === $quote) {
                        $quote = null;
                    }
                    continue;
                }
                if ($byte === '/' && ( $css[$position + 1] ?? '' ) === '*') {
                    $comment_end = strpos($css, '*/', $position + 2);
                    if ($comment_end === false) {
                        return false;
                    }
                    $position = $comment_end + 1;
                    continue;
                }
                if ($byte === '\\') {
                    ++$position;
                    continue;
                }
                if ($byte === '"' || $byte === "'") {
                    if (
                        !$is_at_rule
                        && $square_depth === 0
                        && $parenthesis_depth === 0
                    ) {
                        return false;
                    }
                    $quote = $byte;
                    continue;
                }
                if ($byte === '[') {
                    ++$square_depth;
                    continue;
                }
                if ($byte === ']') {
                    if ($square_depth === 0) {
                        return false;
                    }
                    --$square_depth;
                    continue;
                }
                if ($byte === '(') {
                    ++$parenthesis_depth;
                    continue;
                }
                if ($byte === ')') {
                    if ($parenthesis_depth === 0) {
                        return false;
                    }
                    --$parenthesis_depth;
                    continue;
                }
                if ($square_depth !== 0 || $parenthesis_depth !== 0) {
                    continue;
                }
                if ($byte === ';' && $is_at_rule) {
                    if ($position === $rule_start + 1) {
                        return false;
                    }
                    $saw_content = true;
                    ++$position;
                    continue 2;
                }
                if ($byte === '{') {
                    if (trim(substr($css, $rule_start, $position - $rule_start)) === '') {
                        return false;
                    }
                    $body_start = $position + 1;
                    break;
                }
                if ($byte === ';' || $byte === '/' || $byte === '=' || $byte === '}') {
                    return false;
                }
            }
            if ($body_start === null) {
                return false;
            }

            $curly_depth = 1;
            $body_quote = null;
            $body_end = null;
            for ($scan = $body_start; $scan < $length; ++$scan) {
                $byte = $css[$scan];
                if ($body_quote !== null) {
                    if ($byte === '\\') {
                        ++$scan;
                    } elseif ($byte === $body_quote) {
                        $body_quote = null;
                    }
                    continue;
                }
                if ($byte === '/' && ( $css[$scan + 1] ?? '' ) === '*') {
                    $comment_end = strpos($css, '*/', $scan + 2);
                    if ($comment_end === false) {
                        return false;
                    }
                    $scan = $comment_end + 1;
                    continue;
                }
                if ($byte === '"' || $byte === "'") {
                    $body_quote = $byte;
                } elseif ($byte === '\\') {
                    ++$scan;
                } elseif ($byte === '{') {
                    ++$curly_depth;
                } elseif ($byte === '}') {
                    --$curly_depth;
                    if ($curly_depth === 0) {
                        $body_end = $scan;
                        break;
                    }
                }
            }
            if ($body_end === null) {
                return false;
            }

            $body = substr($css, $body_start, $body_end - $body_start);
            if (trim($body) !== '' && !$this->looks_like_css_block_contents($body)) {
                return false;
            }

            $saw_content = true;
            $position = $body_end + 1;
        }

        return $saw_content;
    }

    /**
     * Return whether bytes form a complete CSS declaration list.
     */
    private function looks_like_css_declaration_list(
        string $css,
        bool $require_semicolon = true
    ): bool
    {
        $length = strlen($css);
        $segment = '';
        $parenthesis_depth = 0;
        $bracket_depth = 0;
        $brace_depth = 0;
        $saw_semicolon = false;
        $saw_comment = false;
        $saw_custom_property = false;
        $declaration_count = 0;
        $allow_url_schemes = !$require_semicolon;

        for ($position = 0; $position < $length; ++$position) {
            $byte = $css[$position];
            if ($byte === '/' && ( $css[$position + 1] ?? '' ) === '*') {
                $saw_comment = true;
                $comment_end = strpos($css, '*/', $position + 2);
                if ($comment_end === false) {
                    return $declaration_count > 0
                        || $this->is_css_declaration_segment($segment, $allow_url_schemes);
                }
                $position = $comment_end + 1;
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                $segment .= $quote . $quote;
                $quote_position = $position + 1;
                for (; $quote_position < $length; ++$quote_position) {
                    if ($css[$quote_position] === '\\') {
                        ++$quote_position;
                        continue;
                    }
                    if ($css[$quote_position] === $quote) {
                        break;
                    }
                }
                if ($quote_position >= $length) {
                    return $declaration_count > 0
                        || $this->is_css_declaration_segment($segment, $allow_url_schemes);
                }
                $position = $quote_position;
                continue;
            }
            if ($byte === '(') {
                ++$parenthesis_depth;
            } elseif ($byte === ')') {
                if ($parenthesis_depth === 0) {
                    return false;
                }
                --$parenthesis_depth;
            } elseif ($byte === '[') {
                ++$bracket_depth;
            } elseif ($byte === ']') {
                if ($bracket_depth === 0) {
                    return false;
                }
                --$bracket_depth;
            } elseif ($byte === '{') {
                ++$brace_depth;
            } elseif ($byte === '}') {
                if ($brace_depth === 0) {
                    return false;
                }
                --$brace_depth;
            } elseif (
                $byte === ';'
                && $parenthesis_depth === 0
                && $bracket_depth === 0
                && $brace_depth === 0
            ) {
                if (trim($segment) !== '') {
                    if (!$this->is_css_declaration_segment($segment, $allow_url_schemes)) {
                        return false;
                    }
                    $colon = strpos($segment, ':');
                    $saw_custom_property = $saw_custom_property || (
                        $colon !== false
                        && strncmp(trim(substr($segment, 0, $colon)), '--', 2) === 0
                    );
                    ++$declaration_count;
                }
                $segment = '';
                $saw_semicolon = true;
                continue;
            }
            $segment .= $byte;
        }

        if ($parenthesis_depth !== 0 || $bracket_depth !== 0 || $brace_depth !== 0) {
            return false;
        }
        if (trim($segment) !== '') {
            if (!$this->is_css_declaration_segment($segment, $allow_url_schemes)) {
                return false;
            }
            $colon = strpos($segment, ':');
            $saw_custom_property = $saw_custom_property || (
                $colon !== false
                && strncmp(trim(substr($segment, 0, $colon)), '--', 2) === 0
            );
            ++$declaration_count;
        }

        return ( !$require_semicolon || $saw_semicolon || $saw_custom_property )
            && ( $declaration_count > 0 || ( !$require_semicolon && $saw_comment ) );
    }

    /**
     * Validate one deliberately narrow CSS declaration segment.
     */
    private function is_css_declaration_segment(
        string $segment,
        bool $allow_url_schemes = false
    ): bool
    {
        $colon = strpos($segment, ':');
        if ($colon === false) {
            return false;
        }
        $property = trim(substr($segment, 0, $colon));
        $value = trim(substr($segment, $colon + 1));
        $is_custom_property = strncmp($property, '--', 2) === 0;
        $has_unowned_url_scheme = false;
        if (!$allow_url_schemes && strpos($value, '://') !== false) {
            $scan = $this->scan_css_tokens($value, false, false);
            if (!$scan['valid']) {
                return false;
            }

            $search_position = 0;
            $scheme_position = strpos($value, '://', $search_position);
            while ($scheme_position !== false) {
                $is_owned = false;
                foreach ($scan['owned_spans'] as $owned_span) {
                    if (
                        $scheme_position >= $owned_span['start']
                        && $scheme_position < $owned_span['start'] + $owned_span['length']
                    ) {
                        $is_owned = true;
                        break;
                    }
                }
                if (!$is_owned) {
                    $has_unowned_url_scheme = true;
                    break;
                }
                $search_position = $scheme_position + 3;
                $scheme_position = strpos($value, '://', $search_position);
            }
        }

        return $this->is_css_property_name($property)
            && ( $value !== '' || $is_custom_property )
            && !(
                preg_match('/\Ahttps?\z/i', $property) === 1
                && strncmp($value, '//', 2) === 0
            )
            && (
                $allow_url_schemes
                || $is_custom_property
                || !$has_unowned_url_scheme
            );
    }

    /**
     * Return whether bytes form one complete CSS property name token.
     */
    private function is_css_property_name(string $property): bool
    {
        if ($property === '' || $property === '--') {
            return false;
        }

        $processor = CSSProcessor::create($property);
        if (
            !$processor->next_token()
            || $processor->get_token_type() !== CSSProcessor::TOKEN_IDENT
            || $processor->get_token_start() !== 0
            || $processor->get_token_length() !== strlen($property)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Splice a changed decoded CSS token value into its original escape form.
     */
    private function rewrite_css_encoded_value(
        string $raw_value,
        string $decoded_value,
        string $quote,
        bool $allow_relative
    ): string {
        $decoded_replacements = $this->get_owned_url_replacements(
            $decoded_value,
            $allow_relative
        );
        if ($decoded_replacements === []) {
            return $raw_value;
        }

        $boundaries = $this->get_css_decoded_boundaries($raw_value, $decoded_value);
        if ($boundaries === null) {
            return $raw_value;
        }
        $raw_replacements = [];
        foreach ($decoded_replacements as $replacement) {
            $decoded_end = $replacement['start'] + $replacement['length'];
            if (
                !isset($boundaries[$replacement['start']])
                || !isset($boundaries[$decoded_end])
            ) {
                return $raw_value;
            }
            $encoded_replacement = $this->encode_css_replacement(
                $replacement['text'],
                $quote
            );
            if ($encoded_replacement === null) {
                return $raw_value;
            }
            $raw_start = $boundaries[$replacement['start']];
            $raw_end = $boundaries[$decoded_end];
            $raw_replacements[] = [
                'start' => $raw_start,
                'length' => $raw_end - $raw_start,
                'text' => $encoded_replacement,
            ];
        }

        return $this->apply_replacements($raw_value, $raw_replacements);
    }

    /**
     * Map decoded CSS string offsets to raw token-value boundaries.
     *
     * @return array<int, int>|null Decoded offset to raw offset, or null when
     *                              the local scan disagrees with CSSProcessor.
     */
    private function get_css_decoded_boundaries(
        string $raw_value,
        string $decoded_value
    ): ?array {
        $raw_length = strlen($raw_value);
        $raw_position = 0;
        $decoded = '';
        $boundaries = [0 => 0];

        while ($raw_position < $raw_length) {
            if ($raw_value[$raw_position] !== '\\') {
                $character_length = $this->get_utf8_character_length(
                    $raw_value,
                    $raw_position
                );
                $decoded .= substr($raw_value, $raw_position, $character_length);
                $raw_position += $character_length;
                $boundaries[strlen($decoded)] = $raw_position;
                continue;
            }

            ++$raw_position;
            if ($raw_position >= $raw_length) {
                return null;
            }
            if ($raw_value[$raw_position] === "\r") {
                ++$raw_position;
                if (( $raw_value[$raw_position] ?? '' ) === "\n") {
                    ++$raw_position;
                }
                $boundaries[strlen($decoded)] = $raw_position;
                continue;
            }
            if ($raw_value[$raw_position] === "\n" || $raw_value[$raw_position] === "\f") {
                ++$raw_position;
                $boundaries[strlen($decoded)] = $raw_position;
                continue;
            }

            $hex_length = strspn(
                $raw_value,
                '0123456789abcdefABCDEF',
                $raw_position,
                min(6, $raw_length - $raw_position)
            );
            if ($hex_length > 0) {
                $codepoint = hexdec(substr($raw_value, $raw_position, $hex_length));
                $raw_position += $hex_length;
                if (
                    $raw_position < $raw_length
                    && strpos(" \t\f\r\n", $raw_value[$raw_position]) !== false
                ) {
                    if (
                        $raw_value[$raw_position] === "\r"
                        && ( $raw_value[$raw_position + 1] ?? '' ) === "\n"
                    ) {
                        $raw_position += 2;
                    } else {
                        ++$raw_position;
                    }
                }
                if (
                    $codepoint === 0
                    || $codepoint > 0x10FFFF
                    || ( $codepoint >= 0xD800 && $codepoint <= 0xDFFF )
                ) {
                    $decoded .= "\xEF\xBF\xBD";
                } else {
                    $decoded .= codepoint_to_utf8_bytes($codepoint);
                }
                $boundaries[strlen($decoded)] = $raw_position;
                continue;
            }

            $character_length = $this->get_utf8_character_length($raw_value, $raw_position);
            $decoded .= substr($raw_value, $raw_position, $character_length);
            $raw_position += $character_length;
            $boundaries[strlen($decoded)] = $raw_position;
        }

        return $decoded === $decoded_value ? $boundaries : null;
    }

    /**
     * Encode changed CSS bytes without touching any unchanged token bytes.
     */
    private function encode_css_replacement(string $replacement, string $quote): ?string
    {
        if (preg_match('//u', $replacement) !== 1) {
            return null;
        }

        $encoded = '';
        $length = strlen($replacement);
        for ($position = 0; $position < $length;) {
            $character_length = $this->get_utf8_character_length($replacement, $position);
            $character = substr($replacement, $position, $character_length);
            $position += $character_length;

            $byte = ord($character[0]);
            $must_escape = $character === '\\'
                || ( $quote !== '' && $character === $quote )
                || ( $quote === '' && strpos("\"'() \t\f\r\n", $character) !== false )
                || $byte <= 0x1F
                || $byte === 0x7F;
            if (!$must_escape) {
                $encoded .= $character;
                continue;
            }

            if ($character === '\\' || ( $quote !== '' && $character === $quote )) {
                $encoded .= '\\' . $character;
            } else {
                $encoded .= '\\' . dechex($byte) . ' ';
            }
        }

        return $encoded;
    }

    /**
     * Rewrite parser-owned block markup token spans.
     */
    private function rewrite_block_markup(string $content): string
    {
        $processor = new BlockMarkupSpanProcessor($content);
        $tokens = [];

        while ($processor->next_token()) {
            $span = $processor->get_current_token_span();
            if ($span === null) {
                continue;
            }
            $attributes = $processor->get_current_attribute_value_spans();
            $attribute_quotes = null;
            if ($attributes !== null) {
                $attribute_quotes = [];
                foreach ($attributes as $name => $attribute) {
                    $attribute_quotes[$name] = $attribute['quote'];
                }
            }
            $tokens[] = [
                'start' => $span['start'],
                'length' => $span['length'],
                'type' => (string) $processor->get_token_type(),
                'tag' => (string) ( $processor->get_tag() ?? '' ),
                'tag_closer' => $processor->is_tag_closer(),
                'block_name' => (string) ( $processor->get_block_name() ?: '' ),
                'block_closer' => $processor->is_block_closer(),
                'block_self_closing' => $processor->is_self_closing_block(),
                'attributes' => $attributes,
                'attribute_quotes' => $attribute_quotes,
            ];
        }

        if ($processor->paused_at_incomplete_token()) {
            return $content;
        }
        $block_error = $processor->get_last_error();
        $block_error_code = null;
        if ($block_error !== null) {
            $block_error_code = method_exists($block_error, 'get_error_code')
                ? $block_error->get_error_code()
                : ( $block_error->code ?? null );
        }
        if ($block_error_code === 'mismatched-closer') {
            return $content;
        }

        $replacements = [];
        $rewritten_position_delta = 0;
        foreach ($tokens as $index => $token) {
            $raw_token = substr($content, $token['start'], $token['length']);
            $rewritten_token = $raw_token;

            if ($token['type'] === '#block-comment') {
                $rewritten_token = $this->rewrite_block_comment_token(
                    $raw_token,
                    $token['block_name']
                );
            } elseif ($token['type'] === '#text') {
                $css_rewrite = $this->rewrite_css_urls($raw_token, true);
                $rewritten_token = $css_rewrite === null
                    ? $this->rewrite_ambiguous_text($raw_token)
                    : $css_rewrite;
            } elseif ($token['type'] === '#tag' && !$token['tag_closer']) {
                $rewritten_token = $this->rewrite_html_tag_token(
                    $raw_token,
                    $token['tag'],
                    $token['attributes'] ?? [],
                    $token['start']
                );
                if ($token['tag'] === 'STYLE') {
                    $rewritten_token = $this->rewrite_style_token($rewritten_token);
                }
            }

            if ($rewritten_token !== $raw_token) {
                $replacements[] = [
                    'start' => $token['start'],
                    'length' => $token['length'],
                    'text' => $rewritten_token,
                ];
            }
            $tokens[$index]['rewritten_start'] = $token['start'] + $rewritten_position_delta;
            $tokens[$index]['rewritten_length'] = strlen($rewritten_token);
            $rewritten_position_delta += strlen($rewritten_token) - $token['length'];
        }

        $rewritten_content = $this->apply_replacements($content, $replacements);
        if ($rewritten_content === $content) {
            return $content;
        }

        $rewritten_processor = new BlockMarkupSpanProcessor($rewritten_content);
        $rewritten_tokens = [];
        while ($rewritten_processor->next_token()) {
            $span = $rewritten_processor->get_current_token_span();
            if ($span === null) {
                return $content;
            }
            $attributes = $rewritten_processor->get_current_attribute_value_spans();
            $attribute_quotes = null;
            if ($attributes !== null) {
                $attribute_quotes = [];
                foreach ($attributes as $name => $attribute) {
                    $attribute_quotes[$name] = $attribute['quote'];
                }
            }
            $rewritten_tokens[] = [
                'start' => $span['start'],
                'length' => $span['length'],
                'type' => (string) $rewritten_processor->get_token_type(),
                'tag' => (string) ( $rewritten_processor->get_tag() ?? '' ),
                'tag_closer' => $rewritten_processor->is_tag_closer(),
                'block_name' => (string) ( $rewritten_processor->get_block_name() ?: '' ),
                'block_closer' => $rewritten_processor->is_block_closer(),
                'block_self_closing' => $rewritten_processor->is_self_closing_block(),
                'attribute_quotes' => $attribute_quotes,
            ];
        }

        if ($rewritten_processor->paused_at_incomplete_token()) {
            return $content;
        }
        $rewritten_block_error = $rewritten_processor->get_last_error();
        $rewritten_block_error_code = null;
        if ($rewritten_block_error !== null) {
            $rewritten_block_error_code = method_exists($rewritten_block_error, 'get_error_code')
                ? $rewritten_block_error->get_error_code()
                : ( $rewritten_block_error->code ?? null );
        }
        if (
            $rewritten_block_error_code !== $block_error_code
            || count($rewritten_tokens) !== count($tokens)
        ) {
            return $content;
        }

        foreach ($rewritten_tokens as $index => $rewritten_token) {
            $token = $tokens[$index];
            if (
                $rewritten_token['start'] !== $token['rewritten_start']
                || $rewritten_token['length'] !== $token['rewritten_length']
                || $rewritten_token['type'] !== $token['type']
                || $rewritten_token['tag'] !== $token['tag']
                || $rewritten_token['tag_closer'] !== $token['tag_closer']
                || $rewritten_token['block_name'] !== $token['block_name']
                || $rewritten_token['block_closer'] !== $token['block_closer']
                || $rewritten_token['block_self_closing'] !== $token['block_self_closing']
                || $rewritten_token['attribute_quotes'] !== $token['attribute_quotes']
            ) {
                return $content;
            }
        }

        return $rewritten_content;
    }

    /**
     * Rewrite parser-confirmed URL and style attribute value spans.
     *
     * @param array<string, array{start: int, length: int, quote: string}> $attributes
     *     Exact attribute value spans in the complete block markup value.
     */
    private function rewrite_html_tag_token(
        string $token,
        string $tag,
        array $attributes,
        int $token_start
    ): string {
        $url_attributes = self::HTML_ATTRIBUTES_ACCEPTING_RELATIVE_URLS[$tag] ?? [];
        $replacements = [];

        foreach ($attributes as $name => $span) {
            if ($span['length'] === 0) {
                continue;
            }
            $relative_start = $span['start'] - $token_start;
            $raw_value = substr($token, $relative_start, $span['length']);
            $decoded_value = WP_HTML_Decoder::decode_attribute($raw_value);

            if ($name === 'style') {
                $rewritten_value = $raw_value;
                $css_rewrite = $this->get_css_url_rewrite($decoded_value, true);
                if ($css_rewrite !== null && $css_rewrite['replacements'] !== []) {
                    $rewritten_value = $this->rewrite_html_encoded_value(
                        $raw_value,
                        $decoded_value,
                        $css_rewrite['replacements'],
                        $span['quote']
                    );
                }
            } elseif (in_array($name, $url_attributes, true)) {
                $rewritten_value = $this->rewrite_html_encoded_value(
                    $raw_value,
                    $decoded_value,
                    $this->get_owned_url_replacements(
                        $decoded_value,
                        true,
                        $tag === 'APPLET' && $name === 'archive'
                    ),
                    $span['quote']
                );
            } elseif (
                $name === 'srcset'
                && in_array($tag, self::HTML_ELEMENTS_WITH_SRCSET, true)
            ) {
                $rewritten_value = $this->rewrite_html_encoded_value(
                    $raw_value,
                    $decoded_value,
                    $this->get_srcset_url_replacements($decoded_value),
                    $span['quote']
                );
            } else {
                continue;
            }

            if ($rewritten_value !== $raw_value) {
                $replacements[] = [
                    'start' => $relative_start,
                    'length' => $span['length'],
                    'text' => $rewritten_value,
                ];
            }
        }

        return $this->apply_replacements($token, $replacements);
    }

    /**
     * Locate the URL portion of each srcset candidate.
     *
     * @return array<int, array{start: int, length: int, text: string}>
     */
    private function get_srcset_url_replacements(string $value): array
    {
        $length = strlen($value);
        $position = 0;
        $replacements = [];

        while ($position < $length) {
            $position += strspn($value, " \t\f\r\n,", $position);
            if ($position >= $length) {
                break;
            }

            $url_start = $position;
            $position += strcspn($value, " \t\f\r\n", $position);
            $url_end = $position;
            while ($url_end > $url_start && $value[$url_end - 1] === ',') {
                --$url_end;
            }

            if ($url_end > $url_start) {
                foreach (
                    $this->get_owned_url_prefix_replacement(
                        substr($value, $url_start, $url_end - $url_start),
                        true
                    ) as $replacement
                ) {
                    $replacement['start'] += $url_start;
                    $replacements[] = $replacement;
                }
            }

            if ($url_end < $position) {
                continue;
            }

            $parenthesis_depth = 0;
            while ($position < $length) {
                $byte = $value[$position];
                if ($byte === '(') {
                    ++$parenthesis_depth;
                } elseif ($byte === ')') {
                    if ($parenthesis_depth === 0) {
                        return [];
                    }
                    --$parenthesis_depth;
                } elseif ($byte === ',' && $parenthesis_depth === 0) {
                    ++$position;
                    break;
                }
                ++$position;
            }
            if ($parenthesis_depth !== 0) {
                return [];
            }
        }

        return $replacements;
    }

    /**
     * Rewrite string values inside one parser-confirmed block delimiter.
     */
    private function rewrite_block_comment_token(string $token, string $block_name): string
    {
        if ($block_name === '' || substr($token, 0, 4) !== '<!--' || substr($token, -3) !== '-->') {
            return $token;
        }

        $position = 4;
        $token_end = strlen($token) - 3;
        $position += strspn($token, " \t\f\r\n", $position, $token_end - $position);
        if (substr($token, $position, strlen($block_name)) !== $block_name) {
            return $token;
        }
        $position += strlen($block_name);
        $position += strspn($token, " \t\f\r\n", $position, $token_end - $position);
        if ($position >= $token_end || ( $token[$position] !== '{' && $token[$position] !== '[' )) {
            return $token;
        }

        $json_end = $token_end;
        while ($json_end > $position && strpos(" \t\f\r\n", $token[$json_end - 1]) !== false) {
            --$json_end;
        }
        if ($json_end > $position && $token[$json_end - 1] === '/') {
            --$json_end;
            while ($json_end > $position && strpos(" \t\f\r\n", $token[$json_end - 1]) !== false) {
                --$json_end;
            }
        }

        $json = substr($token, $position, $json_end - $position);
        $iterator = new JsonStringIterator($json, true);
        if ($iterator->is_malformed()) {
            return $token;
        }

        while ($iterator->next_value()) {
            $original = $iterator->get_value();
            $object_key = $iterator->get_current_object_key();
            $is_direct_url_attribute = (
                $iterator->get_current_nesting_depth() === 1
                && $object_key !== null
                && isset(self::BLOCK_ATTRIBUTES_ACCEPTING_RELATIVE_URLS[$block_name])
                && in_array(
                    $object_key,
                    self::BLOCK_ATTRIBUTES_ACCEPTING_RELATIVE_URLS[$block_name],
                    true
                )
            );
            $rewritten = $is_direct_url_attribute
                ? $this->apply_replacements(
                    $original,
                    $this->get_owned_url_replacements($original, true)
                )
                : $this->rewrite($original, self::BLOCK_MARKUP);
            if ($rewritten !== $original) {
                $iterator->set_value($rewritten);
            }
        }

        $rewritten_json = $iterator->get_result();
        if ($rewritten_json === $json) {
            return $token;
        }

        return substr($token, 0, $position)
            . $rewritten_json
            . substr($token, $json_end);
    }

    /**
     * Rewrite CSS inside one parser-confirmed STYLE raw-text token.
     */
    private function rewrite_style_token(string $token): string
    {
        $opener_end = $this->find_tag_opener_end($token);
        $closer_start = strripos($token, '</style');
        if ($opener_end === null || $closer_start === false || $closer_start < $opener_end) {
            return $token;
        }

        $css = substr($token, $opener_end, $closer_start - $opener_end);
        $rewritten_css = $this->rewrite_css_urls($css, true, true);
        if ($rewritten_css === null || $rewritten_css === $css) {
            return $token;
        }

        return substr($token, 0, $opener_end)
            . $rewritten_css
            . substr($token, $closer_start);
    }

    /**
     * Locate the first byte after a raw tag opener without decoding it.
     */
    private function find_tag_opener_end(string $token): ?int
    {
        $quote = null;
        $length = strlen($token);
        for ($position = 1; $position < $length; ++$position) {
            $byte = $token[$position];
            if ($quote !== null) {
                if ($byte === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($byte === '"' || $byte === "'") {
                $quote = $byte;
                continue;
            }
            if ($byte === '>') {
                return $position + 1;
            }
        }

        return null;
    }

    /**
     * Rewrite a decoded HTML attribute while retaining unchanged raw bytes.
     *
     * @param array<int, array{start: int, length: int, text: string}> $decoded_replacements
     *     Replacements located in the decoded attribute value.
     */
    private function rewrite_html_encoded_value(
        string $raw_value,
        string $decoded_value,
        array $decoded_replacements,
        string $quote
    ): string {
        if ($decoded_replacements === []) {
            return $raw_value;
        }

        $boundaries = $this->get_html_decoded_boundaries($raw_value, $decoded_value);
        if ($boundaries === null) {
            return $raw_value;
        }

        $raw_replacements = [];
        foreach ($decoded_replacements as $replacement) {
            $decoded_end = $replacement['start'] + $replacement['length'];
            if (
                !isset($boundaries[$replacement['start']])
                || !isset($boundaries[$decoded_end])
                || preg_match('//u', $replacement['text']) !== 1
            ) {
                return $raw_value;
            }

            $encoded_replacement = str_replace('&', '&amp;', $replacement['text']);
            if ($quote === '"') {
                $encoded_replacement = str_replace('"', '&quot;', $encoded_replacement);
            } elseif ($quote === "'") {
                $encoded_replacement = str_replace("'", '&#039;', $encoded_replacement);
            } else {
                $encoded_replacement = preg_replace_callback(
                    '/[\x00-\x20"\'`=<>]/',
                    static fn(array $matched_bytes): string => '&#' . ord($matched_bytes[0]) . ';',
                    $encoded_replacement
                );
                if (!is_string($encoded_replacement)) {
                    return $raw_value;
                }
            }

            $raw_start = $boundaries[$replacement['start']];
            $raw_end = $boundaries[$decoded_end];
            $raw_replacements[] = [
                'start' => $raw_start,
                'length' => $raw_end - $raw_start,
                'text' => $encoded_replacement,
            ];
        }

        return $this->apply_replacements($raw_value, $raw_replacements);
    }

    /**
     * Map decoded HTML attribute offsets to their raw source boundaries.
     *
     * @return array<int, int>|null Decoded offset to raw offset, or null when
     *                              the local scan disagrees with the decoder.
     */
    private function get_html_decoded_boundaries(
        string $raw_value,
        string $decoded_value
    ): ?array {
        $raw_length = strlen($raw_value);
        $raw_position = 0;
        $decoded = '';
        $boundaries = [0 => 0];

        while ($raw_position < $raw_length) {
            $raw_start = $raw_position;
            $decoded_chunk = '';

            if (
                $raw_value[$raw_position] === '&'
                && preg_match(
                    '/\A&(?:#[xX][0-9A-Fa-f]+|#[0-9]+|[A-Za-z][A-Za-z0-9]+);?/',
                    substr($raw_value, $raw_position),
                    $match
                ) === 1
            ) {
                $raw_chunk = $match[0];
                $decoded_chunk = WP_HTML_Decoder::decode_attribute($raw_chunk);
                if ($decoded_chunk !== $raw_chunk) {
                    $raw_position += strlen($raw_chunk);
                }
            }

            if ($raw_position === $raw_start) {
                $character_length = $this->get_utf8_character_length(
                    $raw_value,
                    $raw_position
                );
                $decoded_chunk = substr($raw_value, $raw_position, $character_length);
                $raw_position += $character_length;
            }

            $decoded .= $decoded_chunk;
            $boundaries[strlen($decoded)] = $raw_position;
        }

        return $decoded === $decoded_value ? $boundaries : null;
    }


    /**
     * Rewrite source bases in one pass over the original bytes.
     *
     * @param array<int, array{start: int, length: int}> $protected_spans Opaque spans.
     */
    private function rewrite_literal_urls(
        string $content,
        array $protected_spans = [],
        bool $allow_relative = false
    ): string {
        return $this->apply_replacements(
            $content,
            $this->get_literal_url_replacements(
                $content,
                $protected_spans,
                $allow_relative
            )
        );
    }

    /**
     * Locate mappings only in complete parser-owned URL references.
     *
     * @return array<int, array{start: int, length: int, text: string}>
     */
    private function get_owned_url_replacements(
        string $value,
        bool $allow_relative,
        bool $is_space_separated_list = false
    ): array {
        if ($is_space_separated_list) {
            preg_match_all(
                '/[^ \t\f\r\n]+/',
                $value,
                $components,
                PREG_OFFSET_CAPTURE
            );
            $replacements = [];
            foreach ($components[0] as $component) {
                foreach (
                    $this->get_owned_url_prefix_replacement($component[0], $allow_relative)
                    as $replacement
                ) {
                    $replacement['start'] += $component[1];
                    $replacements[] = $replacement;
                }
            }

            return $replacements;
        }

        $reference_start = strspn($value, " \t\f\r\n");
        $reference = substr($value, $reference_start);
        $replacements = $this->get_owned_url_prefix_replacement($reference, $allow_relative);
        foreach ($replacements as &$replacement) {
            $replacement['start'] += $reference_start;
        }
        unset($replacement);

        return $replacements;
    }

    /**
     * Locate a mapping which begins at a complete URL reference's URL prefix.
     *
     * @return array<int, array{start: int, length: int, text: string}>
     */
    private function get_owned_url_prefix_replacement(
        string $reference,
        bool $allow_relative
    ): array {
        $url_start = $this->get_owned_url_start($reference, $allow_relative);
        if ($url_start === null) {
            return [];
        }
        $replacements = $this->get_literal_url_replacements(
            $reference,
            [],
            $allow_relative,
            true
        );
        foreach ($replacements as $replacement) {
            if ($replacement['start'] === $url_start) {
                return [$replacement];
            }
        }

        if (
            $allow_relative
            && ( $reference[$url_start] ?? '' ) === '/'
            && ( $reference[$url_start + 1] ?? '' ) !== '/'
        ) {
            foreach ($this->root_relative_mappings as $mapping) {
                if ($mapping['source_path'] === '' && $mapping['replacement_base'] !== '') {
                    return [[
                        'start' => $url_start,
                        'length' => 0,
                        'text' => $mapping['replacement_base'],
                    ]];
                }
            }
        }

        return [];
    }

    /**
     * Return the mapped-reference start after a supported lexical wrapper.
     */
    private function get_owned_url_start(
        string $reference,
        bool $allow_relative
    ): ?int {
        if (preg_match('~^https?://~i', $reference) === 1) {
            return 0;
        }
        $lowercase_reference = strtolower($reference);
        foreach (['&quot;', '&#34;', '&#x22;'] as $quote_reference) {
            $quote_length = strlen($quote_reference);
            if (
                strncmp($lowercase_reference, $quote_reference, $quote_length) === 0
                && substr($lowercase_reference, -$quote_length) === $quote_reference
            ) {
                $inner_start = $this->get_owned_url_start(
                    substr($reference, $quote_length, -$quote_length),
                    $allow_relative
                );
                return $inner_start === null ? null : $quote_length + $inner_start;
            }
        }
        if (!$allow_relative) {
            return null;
        }

        return strncmp($reference, '/', 1) === 0 ? 0 : null;
    }

    /**
     * Locate non-overlapping source-base replacements in original bytes.
     *
     * @param array<int, array{start: int, length: int}> $protected_spans Opaque spans.
     * @return array<int, array{start: int, length: int, text: string}>
     */
    private function get_literal_url_replacements(
        string $content,
        array $protected_spans = [],
        bool $allow_relative = false,
        bool $strict_url_boundary = false
    ): array {
        if ($this->absolute_mappings === []) {
            return [];
        }

        $content_length = strlen($content);
        $cursor = 0;
        $replacements = [];

        while ($cursor < $content_length) {
            $match = $this->find_next_literal_match(
                $content,
                $cursor,
                $protected_spans,
                $allow_relative,
                $strict_url_boundary
            );
            if ($match === null) {
                break;
            }

            $replacements[] = [
                'start' => $match['position'],
                'length' => $match['length'],
                'text' => $match['replacement'],
            ];
            $cursor = $match['position'] + $match['length'];
        }

        return $replacements;
    }

    /**
     * Find the earliest eligible mapping after a byte offset.
     *
     * @param array<int, array{start: int, length: int}> $protected_spans Opaque spans.
     * @return array{position: int, length: int, replacement: string}|null
     */
    private function find_next_literal_match(
        string $content,
        int $offset,
        array $protected_spans,
        bool $allow_relative,
        bool $strict_url_boundary
    ): ?array {
        $best_match = null;

        foreach ($this->absolute_mappings as $mapping) {
            $match = $this->find_prefixed_mapping_match(
                $content,
                $offset,
                $protected_spans,
                $mapping['source_prefix'],
                $mapping['source_path'],
                $mapping['target_base'],
                true,
                $mapping['source_scheme'],
                $mapping['target_scheme'],
                $strict_url_boundary
            );
            $best_match = $this->select_preferred_match($best_match, $match);
        }

        if ($allow_relative) {
            foreach ($this->network_path_mappings as $mapping) {
                $match = $this->find_prefixed_mapping_match(
                    $content,
                    $offset,
                    $protected_spans,
                    $mapping['source_prefix'],
                    $mapping['source_path'],
                    $mapping['replacement_base'],
                    true,
                    null,
                    null,
                    $strict_url_boundary
                );
                $best_match = $this->select_preferred_match($best_match, $match);
            }
            foreach ($this->root_relative_mappings as $mapping) {
                if ($mapping['source_path'] === '') {
                    continue;
                }
                $match = $this->find_prefixed_mapping_match(
                    $content,
                    $offset,
                    $protected_spans,
                    '',
                    $mapping['source_path'],
                    $mapping['replacement_base'],
                    false,
                    null,
                    null,
                    $strict_url_boundary
                );
                $best_match = $this->select_preferred_match($best_match, $match);
            }
        }

        return $best_match;
    }

    /**
     * Locate one mapping while comparing host prefixes without case sensitivity.
     *
     * @param array<int, array{start: int, length: int}> $protected_spans Opaque spans.
     * @return array{position: int, length: int, replacement: string}|null
     */
    private function find_prefixed_mapping_match(
        string $content,
        int $offset,
        array $protected_spans,
        string $source_prefix,
        string $source_path,
        string $replacement_base,
        bool $case_insensitive_prefix,
        ?string $source_scheme = null,
        ?string $target_scheme = null,
        bool $strict_url_boundary = false
    ): ?array {
        $needle = $source_prefix !== '' ? $source_prefix : $source_path;
        $search_offset = $offset;
        $content_length = strlen($content);

        while ($search_offset < $content_length) {
            $position = $case_insensitive_prefix
                ? stripos($content, $needle, $search_offset)
                : strpos($content, $needle, $search_offset);
            if ($position === false) {
                return null;
            }

            $path_position = $position + strlen($source_prefix);
            if (
                $source_prefix !== ''
                && $source_path !== ''
                && substr($content, $path_position, strlen($source_path)) !== $source_path
            ) {
                $search_offset = $position + 1;
                continue;
            }

            $match_length = strlen($source_prefix) + strlen($source_path);
            $match_end = $position + $match_length;
            if (
                $this->position_is_protected($position, $protected_spans)
                || !$this->has_literal_start_boundary($content, $position)
                || (
                    $strict_url_boundary
                        ? !$this->has_owned_url_end_boundary($content, $match_end)
                        : !$this->has_literal_end_boundary($content, $match_end)
                )
                || $this->unmatched_path_escapes_source_base($content, $match_end)
            ) {
                $search_offset = $position + 1;
                continue;
            }

            $replacement = $replacement_base;
            if (
                $source_scheme !== null
                && $target_scheme !== null
                && $source_scheme === $target_scheme
            ) {
                $separator = strpos($content, '://', $position);
                $target_separator = strpos($replacement, '://');
                if ($separator !== false && $target_separator !== false) {
                    $actual_scheme = substr($content, $position, $separator - $position);
                    $replacement = $actual_scheme . substr($replacement, $target_separator);
                }
            }
            if (
                $source_prefix === ''
                && $replacement === ''
                && ( $match_end === $content_length || $content[$match_end] !== '/' )
            ) {
                $replacement = '/';
            }

            return [
                'position' => $position,
                'length' => $match_length,
                'replacement' => $replacement,
            ];
        }

        return null;
    }

    /**
     * Return whether unmatched dot segments climb above the matched base path.
     */
    private function unmatched_path_escapes_source_base(string $content, int $match_end): bool
    {
        $next_byte = $content[$match_end] ?? '';
        if ($next_byte !== '/' && $next_byte !== '\\') {
            return false;
        }

        $path_end = strcspn($content, "?#\"'<> \t\r\n\0", $match_end);
        $suffix = str_replace('\\', '/', substr($content, $match_end, $path_end));
        $depth = 0;
        foreach (explode('/', $suffix) as $segment) {
            $dot_segment = preg_replace('/%2e/i', '.', $segment);
            if ($dot_segment === null || $dot_segment === '' || $dot_segment === '.') {
                continue;
            }
            if ($dot_segment === '..') {
                if ($depth === 0) {
                    return true;
                }
                --$depth;
                continue;
            }
            ++$depth;
        }

        return false;
    }

    /**
     * Choose the earlier match, preferring the longer source at one position.
     *
     * @param array{position: int, length: int, replacement: string}|null $current
     * @param array{position: int, length: int, replacement: string}|null $candidate
     * @return array{position: int, length: int, replacement: string}|null
     */
    private function select_preferred_match(?array $current, ?array $candidate): ?array
    {
        if ($candidate === null) {
            return $current;
        }
        if (
            $current === null
            || $candidate['position'] < $current['position']
            || (
                $candidate['position'] === $current['position']
                && $candidate['length'] > $current['length']
            )
        ) {
            return $candidate;
        }

        return $current;
    }

    /**
     * Return whether a source base starts at a standalone URL boundary.
     */
    private function has_literal_start_boundary(string $content, int $position): bool
    {
        if ($position === 0) {
            return true;
        }

        $previous_byte = $content[$position - 1];
        $has_boundary = ctype_space($previous_byte)
            || strpos('"\'([{<', $previous_byte) !== false
            || substr($content, max(0, $position - 6), 6) === '&quot;'
            || substr($content, max(0, $position - 5), 5) === '&#34;'
            || strtolower(substr($content, max(0, $position - 6), 6)) === '&#x22;';
        if (!$has_boundary) {
            return false;
        }

        $prefix_start = $position;
        while ($prefix_start > 0 && !ctype_space($content[$prefix_start - 1])) {
            --$prefix_start;
        }
        $prefix = substr($content, $prefix_start, $position - $prefix_start);

        return preg_match('~[a-z][a-z0-9+.-]*:[^\s]*$~i', $prefix) !== 1;
    }

    /**
     * Return whether the source base ends at its host/path boundary.
     */
    private function has_literal_end_boundary(string $content, int $position): bool
    {
        $length = strlen($content);
        if ($position === $length) {
            return true;
        }

        $next_byte = $content[$position];
        if (ctype_space($next_byte) || strpos('/?#\\"\'()[]{}<>,;', $next_byte) !== false) {
            return true;
        }
        if ($next_byte === '&') {
            $suffix = strtolower(substr($content, $position, 6));
            return $suffix === '&quot;'
                || substr($content, $position, 5) === '&#34;'
                || $suffix === '&#x22;';
        }
        if ($next_byte === '.') {
            if ($position + 1 === $length) {
                return true;
            }
            $after_period = $content[$position + 1];
            return ctype_space($after_period)
                || strpos('"\'()[]{}<>,;', $after_period) !== false;
        }

        return false;
    }

    /**
     * Return whether a parser-owned URL continues after a mapped base.
     */
    private function has_owned_url_end_boundary(string $content, int $position): bool
    {
        if ($position === strlen($content)) {
            return true;
        }

        $next_byte = $content[$position];
        if (strpos('/?#\\', $next_byte) !== false) {
            return true;
        }
        $remaining = substr($content, $position);
        if (strspn($remaining, " \t\f\r\n") === strlen($remaining)) {
            return true;
        }

        return in_array(strtolower($remaining), ['&quot;', '&#34;', '&#x22;'], true);
    }

    /**
     * Find opaque PHP serialization and JSON spans embedded in freeform text.
     *
     * @return array<int, array{start: int, length: int}>
     */
    private function find_embedded_structured_spans(string $content): array
    {
        $spans = [];
        $length = strlen($content);
        $position = 0;

        while ($position < $length) {
            if ($this->looks_like_embedded_serialization_at($content, $position)) {
                $processor = new PhpSerializationProcessor(substr($content, $position));
                $span_length = $processor->get_serialized_prefix_byte_length();
                if ($span_length === null) {
                    $span_length = $this->find_malformed_serialization_span_length(
                        $content,
                        $position
                    );
                }
                $spans[] = ['start' => $position, 'length' => $span_length];
                $position += max(1, $span_length);
                continue;
            }

            if (( $content[$position] === '{' || $content[$position] === '[' )
                && $this->looks_like_embedded_json_at($content, $position)
            ) {
                $json_end = $this->find_balanced_json_end($content, $position);
                if ($json_end !== null) {
                    $spans[] = [
                        'start' => $position,
                        'length' => $json_end - $position,
                    ];
                    $position = $json_end;
                    continue;
                }
            }

            ++$position;
        }

        return $spans;
    }

    /**
     * Return whether a byte offset plausibly begins a PHP serialization token.
     */
    private function looks_like_embedded_serialization_at(string $content, int $position): bool
    {
        if ($position > 0) {
            $previous_byte = $content[$position - 1];
            if (!ctype_space($previous_byte) && strpos('=([{;:\'"', $previous_byte) === false) {
                return false;
            }
        }

        $candidate = substr($content, $position);
        if (!PhpSerializationProcessor::has_serialization_token_prefix($candidate)) {
            return false;
        }
        if (( $candidate[0] ?? '' ) === 'N') {
            return true;
        }

        $payload_start = $candidate[2] ?? '';
        if (strpos('siaObCrRE', $candidate[0]) !== false) {
            return ctype_digit($payload_start);
        }
        if ($candidate[0] === 'b') {
            return $payload_start === '0' || $payload_start === '1';
        }
        if ($candidate[0] === 'd') {
            return ctype_digit($payload_start)
                || $payload_start === '-'
                || $payload_start === 'I'
                || $payload_start === 'N';
        }

        return false;
    }

    /**
     * Bound one malformed serialization-shaped span conservatively.
     */
    private function find_malformed_serialization_span_length(string $content, int $position): int
    {
        $token = $content[$position];
        if ($token === 's' || $token === 'E') {
            $end = strpos($content, '";', $position + 2);
            return $end === false ? strlen($content) - $position : $end + 2 - $position;
        }
        if (strpos('idbrR', $token) !== false) {
            $end = strpos($content, ';', $position + 2);
            return $end === false ? strlen($content) - $position : $end + 1 - $position;
        }
        if ($token === 'C') {
            // A custom payload is opaque and may contain arbitrary braces.
            $end = strrpos($content, '}');
            return $end === false ? strlen($content) - $position : $end + 1 - $position;
        }

        $opening_brace = strpos($content, '{', $position + 2);
        if ($opening_brace === false) {
            return strlen($content) - $position;
        }
        $depth = 1;
        $length = strlen($content);
        for ($cursor = $opening_brace + 1; $cursor < $length; ++$cursor) {
            if (
                ( $content[$cursor] === 's' || $content[$cursor] === 'E' )
                && preg_match(
                    '/\A(?:s|E):(\d+):"/',
                    substr($content, $cursor),
                    $string_prefix
                ) === 1
            ) {
                $payload_start = $cursor + strlen($string_prefix[0]);
                $declared_end = $payload_start + (int) $string_prefix[1];
                if (
                    $declared_end + 1 < $length
                    && substr($content, $declared_end, 2) === '";'
                ) {
                    $cursor = $declared_end + 1;
                    continue;
                }

                $fallback_end = strpos($content, '";', $payload_start);
                if ($fallback_end === false) {
                    return $length - $position;
                }
                $cursor = $fallback_end + 1;
                continue;
            }
            if ($content[$cursor] === '{') {
                ++$depth;
            } elseif ($content[$cursor] === '}' && --$depth === 0) {
                return $cursor + 1 - $position;
            }
        }

        return $length - $position;
    }

    /**
     * Return whether an opener is followed by a JSON-shaped first token.
     */
    private function looks_like_embedded_json_at(string $content, int $position): bool
    {
        $length = strlen($content);
        $cursor = $position + 1;
        while ($cursor < $length && strpos(" \t\r\n", $content[$cursor]) !== false) {
            ++$cursor;
        }
        if ($cursor >= $length) {
            return false;
        }

        if ($content[$position] === '{') {
            return $content[$cursor] === '"' || $content[$cursor] === '}';
        }

        return strpos('"{[-0123456789tfn]', $content[$cursor]) !== false;
    }

    /**
     * Locate the end of one balanced JSON-shaped container.
     */
    private function find_balanced_json_end(string $content, int $position): ?int
    {
        $expected_closers = [$content[$position] === '{' ? '}' : ']'];
        $in_string = false;
        $escaped = false;
        $length = strlen($content);

        for ($cursor = $position + 1; $cursor < $length; ++$cursor) {
            $byte = $content[$cursor];
            if ($in_string) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($byte === '\\') {
                    $escaped = true;
                } elseif ($byte === '"') {
                    $in_string = false;
                }
                continue;
            }
            if ($byte === '"') {
                $in_string = true;
            } elseif ($byte === '{') {
                $expected_closers[] = '}';
            } elseif ($byte === '[') {
                $expected_closers[] = ']';
            } elseif ($byte === '}' || $byte === ']') {
                if (array_pop($expected_closers) !== $byte) {
                    return null;
                }
                if ($expected_closers === []) {
                    return $cursor + 1;
                }
            }
        }

        return null;
    }

    /**
     * Return whether an offset is inside an opaque source span.
     *
     * @param array<int, array{start: int, length: int}> $spans Opaque spans.
     */
    private function position_is_protected(int $position, array $spans): bool
    {
        foreach ($spans as $span) {
            if ($position < $span['start']) {
                return false;
            }
            if ($position < $span['start'] + $span['length']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply non-overlapping source replacements from the end of a value.
     *
     * @param array<int, array{start: int, length: int, text: string}> $replacements Replacements.
     */
    private function apply_replacements(string $content, array $replacements): string
    {
        if ($replacements === []) {
            return $content;
        }

        usort(
            $replacements,
            static fn(array $first, array $second): int => $second['start'] <=> $first['start']
        );
        foreach ($replacements as $replacement) {
            $content = substr($content, 0, $replacement['start'])
                . $replacement['text']
                . substr($content, $replacement['start'] + $replacement['length']);
        }

        return $content;
    }

    /**
     * Return whether parse_url() produced a safe source or target base.
     *
     * @param array<string, mixed>|false $parts Parsed URL parts.
     */
    private function has_supported_url_parts($parts): bool
    {
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ));

        return ( $scheme === 'http' || $scheme === 'https' )
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    /**
     * Remove only trailing path separators from a configured base path.
     */
    private function normalize_mapping_path(string $path): string
    {
        $path = rtrim($path, '/');
        return $path === '/' ? '' : $path;
    }

    /**
     * Read the path bytes directly because parse_url() alters non-ASCII paths.
     */
    private function get_raw_url_path(string $url): string
    {
        $scheme_separator = strpos($url, '://');
        if ($scheme_separator === false) {
            return '';
        }
        $path_start = strpos($url, '/', $scheme_separator + 3);

        return $path_start === false ? '' : substr($url, $path_start);
    }

    /**
     * Return Unicode and ASCII spellings for one source host.
     *
     * @return string[] Host spellings.
     */
    private function get_host_variants(string $host): array
    {
        $variants = [strtolower($host) => $host];
        if (class_exists(Idna::class)) {
            foreach ([Idna::toAscii($host), Idna::toUnicode($host)] as $result) {
                if (!$result->hasErrors() && $result->getDomain() !== '') {
                    $variant = $result->getDomain();
                    $variants[strtolower($variant)] = $variant;
                }
            }
        }

        return array_values($variants);
    }

    /**
     * Return the byte length of the UTF-8 character at an offset.
     *
     * Invalid bytes are treated as single-byte units. Structured decoders
     * reject them where UTF-8 is required, while literal suffixes stay raw.
     */
    private function get_utf8_character_length(string $value, int $position): int
    {
        $first_byte = ord($value[$position]);
        if ($first_byte < 0x80 || $first_byte < 0xC2) {
            return 1;
        }
        if ($first_byte < 0xE0) {
            return 2;
        }
        if ($first_byte < 0xF0) {
            return 3;
        }
        if ($first_byte < 0xF5) {
            return 4;
        }

        return 1;
    }

    private function get_cached_rewrite(string $cache_key, string $content_type, string $input): ?string
    {
        if (!isset($this->rewrite_cache[$cache_key])) {
            return null;
        }
        $entry = $this->rewrite_cache[$cache_key];
        if ($entry['content_type'] !== $content_type || $entry['input'] !== $input) {
            return null;
        }

        return $entry['output'];
    }

    private function cache_rewrite(
        string $cache_key,
        string $content_type,
        string $input,
        string $output
    ): string {
        if (!isset($this->rewrite_cache[$cache_key])) {
            if (count($this->rewrite_cache_keys) >= self::REWRITE_CACHE_MAXIMUM) {
                $oldest_key = array_shift($this->rewrite_cache_keys);
                if ($oldest_key !== null) {
                    unset($this->rewrite_cache[$oldest_key]);
                }
            }
            $this->rewrite_cache_keys[] = $cache_key;
        }
        $this->rewrite_cache[$cache_key] = [
            'content_type' => $content_type,
            'input'       => $input,
            'output'      => $output,
        ];

        return $output;
    }
}
