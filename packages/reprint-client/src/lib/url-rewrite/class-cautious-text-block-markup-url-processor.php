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
 * only the configured source base in the raw text-token bytes. The surrounding
 * bytes never pass through the HTML text encoder.
 *
 * Tags, block attributes, and CSS continue through BlockMarkupUrlProcessor.
 * This class also handles the raw `srcset`, `content`, and `archive`
 * subsyntaxes listed by that processor, plus style and script element bodies
 * and nested block-comment values. It deliberately does not inspect arbitrary
 * HTML attributes. A SiteOrigin value such as this remains unchanged:
 *
 * ```
 * <input value="{&quot;url&quot;:&quot;https:\/\/source.example\/image.jpg&quot;}">
 * ```
 *
 * The intended design belongs in the PHP toolkit: structured processors
 * should expose the raw spans of opaque string leaves, and the cautious URL
 * base processor should update those spans without decoding and re-encoding
 * their enclosing format. Once that exists, this subclass should disappear.
 *
 * @method string get_modifiable_text()
 * @method bool set_modifiable_text(string $plaintext_content)
 * @method string|null get_tag()
 * @method string|true|null get_attribute(string $name)
 * @method bool set_attribute(string $name, string|bool $value)
 * @method WP_HTML_Span|false get_block_delimiter_span()
 * @property array<string, WP_HTML_Text_Replacement> $lexical_updates
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class CautiousTextBlockMarkupUrlProcessor extends BlockMarkupUrlProcessor {
    /**
     * Replace configured URL bases in the current raw text token.
     *
     * WP_HTML_Tag_Processor exposes decoded text through get_modifiable_text()
     * and HTML-encodes the complete replacement in set_modifiable_text(). The
     * protected lexical update contains the raw span selected by the parser.
     * Replacing its text directly preserves every byte outside the URL base.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     */
    public function replace_url_bases_in_current_text(array $url_mapping): bool
    {
        if ('#text' !== $this->get_token_type()) {
            return false;
        }

        return $this->replace_url_bases_in_current_modifiable_text($url_mapping);
    }

    /**
     * Replace configured URL bases in the current style element body.
     *
     * Style element bodies are raw text on the STYLE token, not #text tokens.
     * Their raw span can be changed without re-encoding the CSS around a URL.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     */
    public function replace_url_bases_in_current_style_element_body(array $url_mapping): bool
    {
        if ('#tag' !== $this->get_token_type() || 'STYLE' !== $this->get_tag()) {
            return false;
        }

        return $this->replace_url_bases_in_current_modifiable_text($url_mapping);
    }

    /**
     * Replace configured URL bases in the current script element body.
     *
     * Script element bodies are raw text on the SCRIPT token. This only
     * replaces configured source bases and leaves JavaScript syntax untouched.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     */
    public function replace_url_bases_in_current_script_element_body(array $url_mapping): bool
    {
        if ('#tag' !== $this->get_token_type() || 'SCRIPT' !== $this->get_tag()) {
            return false;
        }

        return $this->replace_url_bases_in_current_modifiable_text($url_mapping);
    }

    /**
     * Replace configured URL bases in one raw HTML attribute value.
     *
     * Calling set_attribute() gives this subclass the raw lexical span selected
     * by the tag processor. Restoring that span with only the source base
     * changed keeps the attribute spelling, quotes, and entities intact.
     *
     * @param string                $attribute_name Attribute to replace within.
     * @param array<string, string> $url_mapping    Source URL base => target URL.
     */
    public function replace_url_bases_in_current_attribute(
        string $attribute_name,
        array $url_mapping
    ): bool {
        if ('#tag' !== $this->get_token_type() || !is_string($this->get_attribute($attribute_name))) {
            return false;
        }

        // Apply earlier URL changes before this attribute's raw span is read.
        $html = $this->get_updated_html();
        if (!$this->set_attribute($attribute_name, '')) {
            return false;
        }

        $attribute_update_key = strtolower($attribute_name);
        $attribute_update = $this->lexical_updates[$attribute_update_key] ?? null;
        if ($attribute_update === null) {
            return false;
        }

        $raw_attribute = substr($html, $attribute_update->start, $attribute_update->length);
        $updated_attribute = $this->get_cautiously_rewritten_value($raw_attribute, $url_mapping);
        if ($updated_attribute === $raw_attribute) {
            unset($this->lexical_updates[$attribute_update_key]);
            return false;
        }

        $attribute_update->text = $updated_attribute;
        return true;
    }

    /**
     * Replace configured URL bases in the current block comment.
     *
     * BlockMarkupUrlProcessor decodes block JSON to inspect scalar values and
     * skips nested values. The raw block-comment span lets this processor
     * update a configured source base without serializing that JSON again.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     */
    public function replace_url_bases_in_current_block_comment(array $url_mapping): bool
    {
        if ('#comment' === $this->get_token_type()) {
            // The toolkit does not yet recognize custom block names containing
            // a slash, but their delimiter still identifies a block comment.
            if (preg_match('/^\s*wp:[A-Za-z0-9_-]+\//', $this->get_modifiable_text()) !== 1) {
                return false;
            }

            return $this->replace_url_bases_in_current_modifiable_text($url_mapping);
        }

        if ('#block-comment' !== $this->get_token_type()) {
            return false;
        }

        // Apply any exact block-attribute rewrite before reading its span.
        $html = $this->get_updated_html();
        $block_span = $this->get_block_delimiter_span();
        if ($block_span === false) {
            return false;
        }

        $raw_block_comment = substr($html, $block_span->start, $block_span->length);
        $updated_block_comment = $this->get_cautiously_rewritten_value(
            $raw_block_comment,
            $url_mapping
        );
        if ($updated_block_comment === $raw_block_comment) {
            return false;
        }

        $this->lexical_updates['block delimiter'] = new WP_HTML_Text_Replacement(
            $block_span->start,
            $block_span->length,
            $updated_block_comment
        );

        return true;
    }

    /**
     * Replace URL bases in the current raw text or raw-text-element body.
     *
     * @param array<string, string> $url_mapping Source URL base => target URL.
     */
    private function replace_url_bases_in_current_modifiable_text(array $url_mapping): bool
    {
        // Apply earlier tag, block-attribute, or CSS changes before reading the
        // current span. Their replacement lengths may have shifted its offset.
        $html = $this->get_updated_html();

        if (!$this->set_modifiable_text('')) {
            return false;
        }

        $text_update = $this->lexical_updates['modifiable text'];
        $raw_text = substr($html, $text_update->start, $text_update->length);
        $updated_text = $this->get_cautiously_rewritten_value($raw_text, $url_mapping);
        if ($updated_text === $raw_text) {
            unset($this->lexical_updates['modifiable text']);
            return false;
        }

        $text_update->text = $updated_text;
        return true;
    }

    /**
     * @param array<string, string> $url_mapping Source URL base => target URL.
     */
    private function get_cautiously_rewritten_value(string $value, array $url_mapping): string
    {
        $processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules(
            $value,
            $url_mapping
        );

        while ($processor->next_url()) {
            $processor->replace_url_base();
        }

        return $processor->get_updated_text();
    }
}
