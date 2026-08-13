<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;

/**
 * Uses cautious byte replacement for opaque block-markup values.
 *
 * This is a temporary bridge. BlockMarkupUrlProcessor already understands
 * HTML URL attributes, block attributes, and CSS. Its text-token path uses a
 * general URL parser, however, and that parser must write the complete URL
 * back after changing it. A shortcode such as:
 *
 * ```
 * [vc_video link="https:\/\/source.example\/media\/video.mp4"]
 * ```
 *
 * has no declared escaping rules. Decoding and serializing that URL can change
 * its slashes, quotes, entities, query, or fragment. This subclass replaces
 * only the configured source base in the raw token bytes. The surrounding
 * bytes never pass through the HTML text encoder.
 *
 * Tags, block attributes, and CSS continue through BlockMarkupUrlProcessor.
 * After that exact handling, this class can apply the same cautious replacement
 * to the current raw token. That covers unsupported subsyntaxes without a
 * second pass over the complete markup.
 *
 * The intended design belongs in the PHP toolkit: its block URL processor
 * should apply this token-local fallback directly. Once that exists, this
 * subclass should disappear.
 *
 * @method bool set_bookmark(string $name)
 * @method bool release_bookmark(string $name)
 * @property array<string, WP_HTML_Span> $bookmarks
 * @property array<int|string, WP_HTML_Text_Replacement> $lexical_updates
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class CautiousTextBlockMarkupUrlProcessor extends BlockMarkupUrlProcessor {
    /** @var array<string, string> */
    private array $url_mapping;

    /**
     * @param string                $html            Block markup to process.
     * @param string|null           $base_url_string Base URL for exact URL parsing.
     * @param array<string, string> $url_mapping     Source URL base => target URL.
     */
    public function __construct($html, ?string $base_url_string, array $url_mapping)
    {
        parent::__construct($html, $base_url_string);
        $this->url_mapping = $url_mapping;
    }

    /**
     * Yield exact URLs first, then cautiously rewrite the rest of the token.
     *
     * Text tokens are entirely opaque. `archive` is also left to the cautious
     * fallback because it is a URL list, not one URL.
     */
    public function next_url_in_current_token()
    {
        if ('#text' !== $this->get_token_type()) {
            while (parent::next_url_in_current_token()) {
                if ('archive' === $this->get_inspected_attribute_name()) {
                    continue;
                }

                return true;
            }
        }

        $this->replace_url_bases_in_current_token();
        return false;
    }

    /**
     * Replace configured URL bases in the current raw token.
     *
     * Applying pending exact changes first keeps the token span current. The
     * cautious processor then changes only configured source-base bytes within
     * that span, preserving every other byte in the token.
     */
    private function replace_url_bases_in_current_token(): bool
    {
        $html = $this->get_updated_html();
        if (!$this->set_bookmark('cautious URL base replacement')) {
            return false;
        }

        $token_span = $this->bookmarks['cautious URL base replacement'];
        $this->release_bookmark('cautious URL base replacement');
        $raw_token = substr($html, $token_span->start, $token_span->length);
        $processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules(
            $raw_token,
            $this->url_mapping
        );
        while ($processor->next_url()) {
            $processor->replace_url_base();
        }

        $updated_token = $processor->get_updated_text();
        if ($updated_token === $raw_token) {
            return false;
        }

        $this->lexical_updates[] = new WP_HTML_Text_Replacement(
            $token_span->start,
            $token_span->length,
            $updated_token
        );

        return true;
    }
}
