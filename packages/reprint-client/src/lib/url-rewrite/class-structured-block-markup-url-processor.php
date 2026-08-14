<?php

use Rowbot\URL\URL;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use WordPress\DataLiberation\URL\CSSURLProcessor;
use WordPress\DataLiberation\URL\WPURL;

/**
 * Reports URLs in structured block markup and enables rewriting them.
 *
 * HTML attributes, block attributes, and CSS url() values have declared
 * grammars and are exposed as individual URLs. Other token content may use an
 * undeclared escaping scheme, for example:
 *
 * ```
 * [vc_video link="https:\/\/source.example\/media\/video.mp4"]
 * ```
 *
 * Decoding and serializing that URL could change its slashes, quotes,
 * entities, query, or fragment. After structured URLs have been handled,
 * replace_url_bases_in_current_token() changes only configured source-base
 * bytes in the raw token. The surrounding bytes never pass through the HTML
 * text encoder.
 *
 * @TODO Contribute this structured processor back to the PHP toolkit after
 *       its Reprint behavior has stabilized.
 *
 * @method bool set_bookmark(string $name)
 * @method bool release_bookmark(string $name)
 * @method mixed get_attribute(string $name)
 * @method string|null get_tag()
 * @method bool set_attribute(string $name, mixed $value)
 * @property array<string, WP_HTML_Span> $bookmarks
 * @property array<int|string, WP_HTML_Text_Replacement> $lexical_updates
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
class StructuredBlockMarkupUrlProcessor extends BlockMarkupProcessor {

	private $raw_url;
	/**
	 * @var URL|null
	 */
	private $parsed_url;
	private $base_url_string;
	private $base_url_object;
	private $css_url_processor;
	private $css_url_processor_updated;

	/**
	 * The list of names of URL-related HTML attributes that may be available on
	 * the current token. They will be inspected by next_url_attribute().
	 *
	 * Possible values:
	 *
	 * - null: We haven't inspected any attribute yet.
	 * - array: The first element is the currently inspected attribute
	 *          and the rest of the list are elements yet to be inspected on
	 *          the upcoming next_url_attribute() call.
	 * - empty array: We've already inspected all the URL-related attributes.
	 *
	 * @var array<string>|null
	 */
	private $inspecting_html_attributes;

	public function __construct( $html, ?string $base_url_string = null ) {
		// @phpstan-ignore-next-line -- The conditional HTML processor wrapper hides its parent constructor.
		parent::__construct( $html );
		$this->base_url_string = $base_url_string;
		$this->base_url_object = $base_url_string ? WPURL::parse( $base_url_string ) : null;
	}

	public function get_updated_html(): string {
		if ( $this->css_url_processor_updated ) {
			if ( null !== $this->css_url_processor ) {
				$updated_css = $this->css_url_processor->get_updated_css();
				$this->set_attribute( 'style', $updated_css );
			}
			$this->css_url_processor_updated = false;
		}

		return parent::get_updated_html();
	}

	public function get_raw_url() {
		return $this->raw_url;
	}

	public function get_parsed_url() {
		return $this->parsed_url;
	}

	public function next_token(): bool {
		$this->get_updated_html();

		$this->raw_url                    = null;
		$this->parsed_url                 = null;
		$this->inspecting_html_attributes = null;
		$this->css_url_processor          = null;
		/*
		 * Do not reset css_url_processor_updated – it is reset in
		 * get_updated_html() which is called in parent::next_token().
		 */

		return parent::next_token();
	}

	public function next_url() {
		do {
			if ( $this->next_url_in_current_token() ) {
				return true;
			}
		} while ( false !== $this->next_token() );

		return false;
	}

	public function next_url_in_current_token() {
		$this->raw_url = null;
		switch ( parent::get_token_type() ) {
			case '#tag':
				return $this->next_url_attribute();
			case '#block-comment':
				return $this->next_url_block_attribute();
			default:
				return false;
		}
	}

	/**
	 * Replace configured URL bases in the current raw token.
	 *
	 * Applying pending structured changes first keeps the token span current.
	 * The cautious processor then changes only configured source-base bytes in
	 * opaque text and unsupported subsyntaxes, preserving every other byte.
	 *
	 * @param CautiousURLBaseRewriteMapping $prepared_url_mapping Prepared URL mapping.
	 * @return bool Whether the token changed.
	 */
	public function replace_url_bases_in_current_token( CautiousURLBaseRewriteMapping $prepared_url_mapping ): bool {
		$html = $this->get_updated_html();
		if ( ! $this->set_bookmark( 'cautious URL base replacement' ) ) {
			return false;
		}

		$token_span = $this->bookmarks['cautious URL base replacement'];
		$this->release_bookmark( 'cautious URL base replacement' );
		$raw_token = substr( $html, $token_span->start, $token_span->length );
		$processor = new CautiousURLBaseProcessorInTextWithMixedUnknownEscapeRules(
			$raw_token,
			$prepared_url_mapping
		);
		while ( $processor->next_url() ) {
			$processor->replace_url_base();
		}

		$updated_token = $processor->get_updated_text();
		if ( $updated_token === $raw_token ) {
			return false;
		}

		$this->lexical_updates[] = new WP_HTML_Text_Replacement(
			$token_span->start,
			$token_span->length,
			$updated_token
		);

		return true;
	}

	/**
	 * Advances to the next CSS URL in the `style` attribute of the current tag token.
	 *
	 * @return bool Whether a CSS URL was found.
	 */
	private function next_url_in_css() {
		if ( '#tag' !== $this->get_token_type() ) {
			return false;
		}

		if ( null === $this->css_url_processor ) {
			$css_value = $this->get_attribute( 'style' );
			if ( ! is_string( $css_value ) ) {
				return false;
			}

			$this->css_url_processor = new CSSURLProcessor( $css_value );
		}

		while ( $this->css_url_processor->next_url() ) {
			/**
			 * Skip data URIs. They may be really large and they don't
			 * have a hostname to migrate.
			 */
			if ( $this->css_url_processor->is_data_uri() ) {
				continue;
			}
			$this->raw_url    = $this->css_url_processor->get_raw_url();
			$this->parsed_url = WPURL::parse( $this->raw_url, $this->base_url_string );
			if ( false === $this->parsed_url ) {
				continue;
			}

			return true;
		}

		return false;
	}

	private function next_url_attribute() {
		$tag = $this->get_tag();

		// Check if we have a style attribute with CSS URLs to process.
		if ( null !== $this->css_url_processor ) {
			if ( $this->next_url_in_css() ) {
				return true;
			}
			// Done with CSS URLs in this attribute, apply any pending updates and move on.
			$this->get_updated_html();
			$this->css_url_processor = null;
		}

		if ( null === $this->inspecting_html_attributes ) {
			if ( array_key_exists( $tag, self::HTML_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM ) ) {
				/**
				 * Initialize the list on the first call to next_url_attribute()
				 * for the current token. The last element is the attribute we'll
				 * inspect in the while() loop below.
				 */
				$this->inspecting_html_attributes = self::HTML_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM[ $tag ];
				// Add style attribute to the list if it exists.
				if ( null !== $this->get_attribute( 'style' ) ) {
					$this->inspecting_html_attributes[] = 'style';
				}
			} elseif ( null !== $this->get_attribute( 'style' ) ) {
				$this->inspecting_html_attributes = array( 'style' );
			} else {
				return false;
			}
		} else {
			/**
			 * Forget the attribute we've inspected on the previous call to
			 * next_url_attribute().
			 */
			array_pop( $this->inspecting_html_attributes );
		}

		while ( count( $this->inspecting_html_attributes ) > 0 ) {
			$attr      = $this->inspecting_html_attributes[ count( $this->inspecting_html_attributes ) - 1 ];
			$url_maybe = $this->get_attribute( $attr );
			if ( ! is_string( $url_maybe ) ) {
				array_pop( $this->inspecting_html_attributes );
				continue;
			}

			// Rewrite any CSS `url()` declarations in the `style` attribute.
			if ( 'style' === $attr ) {
				$this->css_url_processor = new CSSURLProcessor( $url_maybe );
				if ( $this->next_url_in_css() ) {
					return true;
				}
				// No CSS URLs found, move to next attribute.
				$this->css_url_processor = null;
				array_pop( $this->inspecting_html_attributes );
				continue;
			}

			/*
			 * Use base URL to resolve known URI attributes as we are certain we're
			 * dealing with URI values.
			 * With a base URL, the string "plugins.php" in <a href="plugins.php"> will
			 * be correctly recognized as a URL.
			 * Without a base URL, this Processor would incorrectly skip it.
			 */
			$parsed_url = WPURL::parse( $url_maybe, $this->base_url_string );

			if ( false === $parsed_url ) {
				array_pop( $this->inspecting_html_attributes );
				continue;
			}
			$this->raw_url    = $url_maybe;
			$this->parsed_url = $parsed_url;

			return true;
		}

		return false;
	}

	private function next_url_block_attribute() {
		while ( $this->next_block_attribute() ) {
			$url_maybe = $this->get_block_attribute_value();
			if ( ! is_string( $url_maybe ) ||
				count( $this->get_block_attribute_path() ) > 1
			) {
				// @TODO: support arrays, objects, and other non-string data structures.
				continue;
			}

			/**
			 * Decide whether the current block attribute holds a URL.
			 *
			 * Known URL attributes can be assumed to hold a URL and be
			 * parsed with the base URL. For example, a "/about-us" value
			 * in a wp:navigation-link block's `url` attribute is a
			 * relative URL to the `/about-us` page.
			 *
			 * Other attributes may or may not contain URLs, but we cannot assume
			 * they do. A value `/about-us` could be a relative URL or a class name.
			 * In those cases, we'll let go of relative URLs and only detect
			 * absolute URLs to avoid treating every string as a URL. This requires
			 * parsing without a base URL.
			 */
			$is_relative_url_block_attribute = (
				isset( self::BLOCK_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM[ $this->get_block_name() ] ) &&
				in_array( $this->get_block_attribute_key(), self::BLOCK_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM[ $this->get_block_name() ], true )
			);

			/**
			 * Filters whether a block attribute is known to contain a relative URL.
			 *
			 * This filter allows extending the list of block attributes that are
			 * recognized as containing URLs. When a block attribute is marked as
			 * a known URL attribute, it will be parsed with the base URL, allowing
			 * relative URLs to be properly resolved.
			 *
			 * @since 6.8.0
			 *
			 * @param bool  $is_relative_url_block_attribute Whether the block attribute is known to contain a relative URL.
			 * @param array $context {
			 *     Context information about the block attribute.
			 *
			 *     @type string $block_name      The name of the block (e.g., 'wp:image', 'wp:button').
			 *     @type string $attribute_name  The name of the attribute (e.g., 'url', 'href').
			 * }
			 */
			$is_relative_url_block_attribute = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Toolkit extension hook.
				'url_processor_is_relative_url_block_attribute',
				$is_relative_url_block_attribute,
				array(
					'block_name' => $this->get_block_name(),
					'attribute_name' => $this->get_block_attribute_key(),
				)
			);

			$parsed_url = false;
			if ( $is_relative_url_block_attribute ) {
				// Known relative URL attribute – let's parse with the base URL.
				$parsed_url = WPURL::parse( $url_maybe, $this->base_url_string );
			} else {
				// Other attributes – let's parse without a base URL (and only detect absolute URLs).
				$parsed_url = WPURL::parse( $url_maybe );
			}

			if ( false === $parsed_url ) {
				continue;
			}

			$this->raw_url    = $url_maybe;
			$this->parsed_url = $parsed_url;
			return true;
		}

		return false;
	}

	/**
	 * Replaces the currently matched URL with a new one.
	 *
	 * @param  string $raw_url  The raw URL.
	 * @param  URL    $parsed_url  The parsed version of the raw URL. It is required
	 *                             as $raw_url might be a relative URL pointing to a different
	 *                             host than this processor's base URL.
	 *
	 * @return bool True if the URL was set, false otherwise.
	 */
	public function set_url( $raw_url, $parsed_url ) {
		if ( null === $this->raw_url ) {
			return false;
		}
		$this->raw_url    = $raw_url;
		$this->parsed_url = $parsed_url;
		switch ( parent::get_token_type() ) {
			case '#tag':
				// Check if we're processing a CSS URL.
				if ( null !== $this->css_url_processor ) {
					$this->css_url_processor_updated = true;
					return $this->css_url_processor->set_raw_url( $raw_url );
				}

				$attr = $this->get_inspected_attribute_name();
				if ( false === $attr ) {
					return false;
				}
				$this->set_attribute( $attr, $raw_url );

				return true;

			case '#block-comment':
				return $this->set_block_attribute_value( $raw_url );
		}

		return false;
	}

	/**
	 * Rewrites the components of the currently matched URL from ones
	 * provided in $from_url to ones specified in $to_url.
	 *
	 * It preserves the relative nature of the matched URL.
	 */
	public function replace_base_url( $to_url, $base_url = null ) {
		$base_url = $base_url ?? $this->base_url_object;
		if ( ! $base_url ) {
			return false;
		}

		$result = WPURL::replace_base_url(
			$this->get_parsed_url(),
			array(
				'old_base_url' => $base_url,
				'new_base_url' => $to_url,
				'raw_url'      => $this->get_raw_url(),
				'is_relative'  => ! WPURL::can_parse( $this->get_raw_url() ),
			)
		);

		if ( false === $result ) {
			return false;
		}

		$this->set_url( $result . '', $result->new_url );

		return true;
	}

	/**
	 * Returns true if the currently matched URL is absolute.
	 *
	 * @return bool Whether the currently matched URL is absolute.
	 */
	public function is_url_absolute() {
		return WPURL::can_parse( $this->get_raw_url() );
	}

	public function get_inspected_attribute_name() {
		if ( '#tag' !== $this->get_token_type() ) {
			return false;
		}

		if ( null === $this->inspecting_html_attributes ) {
			return false;
		}

		if ( empty( $this->inspecting_html_attributes ) ) {
			return false;
		}

		return $this->inspecting_html_attributes[ count( $this->inspecting_html_attributes ) - 1 ];
	}

	/**
	 * A list of block attributes that are known to contain URLs.
	 *
	 * It covers WordPress core blocks as of WordPress version 6.9. It can be
	 * extended by plugins and themes via the "url_processor_is_relative_url_block_attribute"
	 * filter.
	 *
	 * @var array
	 */
	public const BLOCK_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM = array(
		'wp:button'             => array( 'url', 'linkTarget' ),
		'wp:cover'              => array( 'url' ),
		'wp:embed'              => array( 'url' ),
		'wp:gallery'            => array( 'url', 'fullUrl' ),
		'wp:image'              => array( 'url', 'src', 'href' ),
		'wp:media-text'         => array( 'mediaUrl', 'href' ),
		'wp:navigation-link'    => array( 'url' ),
		'wp:navigation-submenu' => array( 'url' ),
		'wp:rss'                => array( 'feedURL' ),
	);

	/**
	 * A list of HTML attributes meant to contain URLs, as defined in the HTML specification.
	 * It includes some deprecated attributes like `lowsrc` and `highsrc` for the `IMG` element.
	 *
	 * See https://html.spec.whatwg.org/multipage/indices.html#attributes-1.
	 * See https://stackoverflow.com/questions/2725156/complete-list-of-html-tag-attributes-which-have-a-url-value.
	 */
	public const HTML_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM = array(
		'A'          => array( 'href' ),
		'APPLET'     => array( 'codebase' ),
		'AREA'       => array( 'href' ),
		'AUDIO'      => array( 'src' ),
		'BASE'       => array( 'href' ),
		'BLOCKQUOTE' => array( 'cite' ),
		'BODY'       => array( 'background' ),
		'BUTTON'     => array( 'formaction' ),
		'COMMAND'    => array( 'icon' ),
		'DEL'        => array( 'cite' ),
		'EMBED'      => array( 'src' ),
		'FORM'       => array( 'action' ),
		'FRAME'      => array( 'longdesc', 'src' ),
		'HEAD'       => array( 'profile' ),
		'HTML'       => array( 'manifest' ),
		'IFRAME'     => array( 'longdesc', 'src' ),
		// SVG <image> element.
		'IMAGE'      => array( 'href' ),
		'IMG'        => array( 'longdesc', 'src', 'usemap', 'lowsrc', 'highsrc' ),
		'INPUT'      => array( 'src', 'usemap', 'formaction' ),
		'INS'        => array( 'cite' ),
		'LINK'       => array( 'href' ),
		'OBJECT'     => array( 'classid', 'codebase', 'data', 'usemap' ),
		'Q'          => array( 'cite' ),
		'SCRIPT'     => array( 'src' ),
		'SOURCE'     => array( 'src' ),
		'TRACK'      => array( 'src' ),
		'VIDEO'      => array( 'poster', 'src' ),
	);

	/**
	 * @TODO: Either explicitly support these attributes, or explicitly drop support for
	 *        handling their subsyntax. A generic URL matcher might be good enough.
	 */
	public const HTML_ATTRIBUTES_WITH_SUBSYNTAX_TO_ACCEPT_RELATIVE_URLS_FROM = array(
		'*'      => array( 'style' ), // background(), background-image().
		'APPLET' => array( 'archive' ),
		'IMG'    => array( 'srcset' ),
		'META'   => array( 'content' ),
		'SOURCE' => array( 'srcset' ),
		'OBJECT' => array( 'archive' ),
	);

	/**
	 * Also <style> and <script> tag content can contain URLs.
	 * <style> has specific syntax rules we can use for matching, but perhaps a generic matcher would be good enough?
	 *
	 * <style>
	 * #domID { background:url(https://mysite.com/wp-content/uploads/image.png) }
	 * </style>
	 *
	 * @TODO: Either explicitly support these tags, or explicitly drop support for
	 *         handling their subsyntax. A generic URL matcher might be good enough.
	 */
	public const HTML_TAGS_WITH_SUBSYNTAX_TO_ACCEPT_RELATIVE_URLS_FROM = array(
		'STYLE',
		'SCRIPT',
	);
}
