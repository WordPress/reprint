<?php

use WordPress\DataLiberation\BlockMarkup\BlockMarkupUrlProcessor;

/**
 * Uses cautious byte replacement for text tokens in block markup.
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
 * This class deliberately does not inspect arbitrary HTML attributes. A
 * SiteOrigin value such as this remains unchanged:
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

        // Apply earlier tag, block-attribute, or CSS changes before reading the
        // current span. Their replacement lengths may have shifted its offset.
        $html = $this->get_updated_html();

        if (!$this->set_modifiable_text('')) {
            return false;
        }

        $text_update = $this->lexical_updates['modifiable text'];
        $raw_text = substr($html, $text_update->start, $text_update->length);
        $processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules(
            $raw_text,
            $url_mapping
        );

        while ($processor->next_url()) {
            $processor->replace_url_base();
        }

        $updated_text = $processor->get_updated_text();
        if ($updated_text === $raw_text) {
            unset($this->lexical_updates['modifiable text']);
            return false;
        }

        $text_update->text = $updated_text;
        return true;
    }
}
