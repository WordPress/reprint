<?php

use Rowbot\URL\URL;
use WordPress\DataLiberation\BlockMarkup\BlockMarkupProcessor;
use WordPress\DataLiberation\URL\CSSURLProcessor;
use WordPress\DataLiberation\URL\WPURL;

use function WordPress\DataLiberation\URL\is_child_url_of;

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
 * @method string get_modifiable_text()
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
	/**
	 * Whether the current field accepts relative URLs, such as `/photo.jpg`
	 * in an HTML href. Unknown block settings accept only absolute URLs.
	 * Keep this decision until the cache lookup and URL parsing are done;
	 * accepted relative URLs use the existing base_url_string.
	 */
	private bool $current_field_accepts_relative_urls = false;
	private $css_url_processor;
	private $css_url_processor_updated;

	/** @var bool Whether the CSS parser reads a STYLE body instead of a style attribute. */
	private $in_style_element = false;

	/** @var bool Whether to parse STYLE bodies as CSS instead of leaving them to raw-text rewriting. */
	private $parse_style_elements;

	/**
	 * Whether a structured URL in this token failed source-boundary validation.
	 *
	 * The cautious pass cannot exclude that URL from its raw-token scan. Skipping
	 * the pass for this token prevents it from bypassing the failed validation.
	 *
	 * @var bool
	 */
	private $structured_url_source_boundary_failed = false;

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

	/**
	 * @param string      $html                 HTML or block markup to visit.
	 * @param string|null $base_url_string      Base for known relative URL fields.
	 * @param bool        $parse_style_elements Decode CSS URLs in STYLE bodies.
	 *     Ordinary single-site migrations pass false to keep the raw-text path.
	 *     Extracting one site from a multisite network passes true: decoded CSS
	 *     paths distinguish that site's links from links to other network sites.
	 *     It stays true even when there are no child-site paths.
	 *     Inline style attributes are parsed in either mode.
	 */
	public function __construct( $html, ?string $base_url_string = null, bool $parse_style_elements = false ) {
		parent::__construct( $html );
		$this->base_url_string = $base_url_string;
		$this->parse_style_elements = $parse_style_elements;
		$this->base_url_object = $base_url_string ? WPURL::parse( $base_url_string ) : null;
	}

	/** Flush CSS edits before the parent applies this token's HTML and block edits. */
	public function get_updated_html(): string {
		if ( $this->css_url_processor_updated ) {
			if ( null !== $this->css_url_processor ) {
				$updated_css = $this->css_url_processor->get_updated_css();
				if ( $this->in_style_element ) {
					$this->set_modifiable_text( $updated_css );
				} else {
					$this->set_attribute( 'style', $updated_css );
				}
			}
			$this->css_url_processor_updated = false;
		}

		return parent::get_updated_html();
	}

	public function get_raw_url() {
		return $this->raw_url;
	}

	/** Parse a cache miss; repeated links can be replaced without another URL parse. */
	public function get_parsed_url() {
		if ( null === $this->parsed_url && null !== $this->raw_url ) {
			// Full HTTP(S) URLs need no base. Shorthand such as `https:photo.jpg`
			// still does: with an HTTPS base at /shop/, it means /shop/photo.jpg.
			// This prefix check selects the base argument; WPURL validates the URL.
			$this->parsed_url = WPURL::parse(
				$this->raw_url,
				$this->has_absolute_http_url_prefix( $this->raw_url ) ? null : $this->get_url_base()
			);
		}
		return $this->parsed_url;
	}

	/** Include the field's relative-URL context in cache keys, even before parsing. */
	public function get_url_base(): ?string {
		return $this->current_field_accepts_relative_urls ? $this->base_url_string : null;
	}

	/** Flush the current token, then discard its URL and CSS parser state. */
	public function next_token(): bool {
		// The parent flushes this token through our get_updated_html() before
		// moving on. Keep the CSS parser alive until that flush has finished.
		$has_token = parent::next_token();

		$this->raw_url                            = null;
		$this->parsed_url                         = null;
		$this->current_field_accepts_relative_urls = false;
		$this->inspecting_html_attributes         = null;
		$this->css_url_processor                  = null;
		$this->in_style_element                   = false;
		$this->structured_url_source_boundary_failed = false;
		// get_updated_html() cleared the update flag before we dropped its parser.
		return $has_token;
	}

	public function next_url() {
		do {
			if ( $this->next_url_in_current_token() ) {
				return true;
			}
		} while ( false !== $this->next_token() );

		return false;
	}

	/** Visit URL attributes, then a STYLE body; block fields use their own parser. */
	public function next_url_in_current_token() {
		while ( $this->next_raw_url_in_current_token() ) {
			if ( false !== $this->get_parsed_url() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Read the next decoded URL field without parsing the URL itself.
	 *
	 * HTML, CSS, and block parsers still identify and decode the field. The
	 * rewriter checks its bounded cache next, then parses the URL on a miss.
	 * Call next_url_in_current_token() when invalid URLs must be skipped.
	 */
	public function next_raw_url_in_current_token() {
		$this->raw_url = null;
		$this->parsed_url = null;
		$this->current_field_accepts_relative_urls = true;
		switch ( parent::get_token_type() ) {
			case '#tag':
				if ( $this->in_style_element ) {
					return $this->next_url_in_style_element();
				}
				// Start the STYLE body only after its attributes have been read.
				if ( $this->next_url_attribute() ) {
					return true;
				}

				if ( $this->parse_style_elements ) {
					return $this->next_url_in_style_element();
				}

				return false;
			case '#block-comment':
				return $this->next_url_block_attribute();
			default:
				return false;
		}
	}

	/**
	 * Skip replace_url_bases_in_current_token() for the current token.
	 *
	 * Call this when a structured URL matched a source base but its spelling
	 * cannot be edited safely. The raw-text pass cannot tell that URL apart
	 * from other token bytes, so it would otherwise bypass the failed check.
	 */
	public function keep_url_base_replacement_out_of_current_token(): void {
		$this->structured_url_source_boundary_failed = true;
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
		if ( $this->structured_url_source_boundary_failed ) {
			return false;
		}
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

	/** Visit declared CSS URLs in the STYLE body once, after the tag's attributes. */
	private function next_url_in_style_element(): bool {
		if ( 'STYLE' !== $this->get_tag() || $this->is_tag_closer() ) {
			return false;
		}
		if ( ! $this->in_style_element ) {
			// Attribute edits were flushed before reaching the body. The same
			// CSS loop can now read the body instead.
			$this->css_url_processor = new CSSURLProcessor( $this->get_modifiable_text() );
			$this->in_style_element = true;
		}
		return $this->next_url_in_css();
	}

	/**
	 * Advances to the next CSS URL in the style attribute or STYLE body.
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
			$this->raw_url    = $url_maybe;

			return true;
		}

		return false;
	}

	/** Read top-level block URL fields, allowing relative URLs only for known fields. */
	private function next_url_block_attribute() {
		// This reader accepts "url" in {"url":"https://example.com/a"}.
		// Divi often uses {"module":{"content":{"value":"https://example.com/a"}}}.
		// Here "module" is an array, so this reader has no string to return.
		// Skip next_block_attribute(): it would build ["module","content","value"]
		// only to discard it in the loop below. This fast reject remains for ordinary
		// single-site imports and direct URL-iterator callers. Multisite-to-single-site
		// imports skip this iterator: StructuredDataUrlRewriter walks
		// get_block_attributes() once and returns changes through set_block_attributes()
		// before the next token.
		// Skip this scan while the iterator has a current attribute path.
		if ( false === $this->get_block_attribute_path() ) {
			$has_top_level_string = false;
			foreach ( $this->get_block_attributes() ?: array() as $value ) {
				if ( is_string( $value ) ) {
					$has_top_level_string = true;
					break;
				}
			}
			if ( ! $has_top_level_string ) {
				return false;
			}
		}

		while ( $this->next_block_attribute() ) {
			$url_maybe = $this->get_block_attribute_value();
			if ( ! is_string( $url_maybe ) ||
				count( $this->get_block_attribute_path() ) > 1
			) {
				// This iterator reports only top-level URL fields. The rewriter
				// handles nested strings separately; non-string values stay unchanged.
				continue;
			}

			$this->current_field_accepts_relative_urls = $this->block_attribute_accepts_relative_urls( $this->get_block_attribute_key() );

			$this->raw_url    = $url_maybe;
			return true;
		}

		return false;
	}

	/**
	 * Check whether a top-level block field accepts relative URLs.
	 *
	 * In wp:navigation-link, url="/about-us" names a page relative to the site.
	 * In an unknown field, "/about-us" could also be a class name. Return false
	 * for that field so callers parse it without the existing site base.
	 *
	 * Callers pass only top-level string fields. A nested key named "url"
	 * does not inherit the block's URL rule.
	 *
	 * @param string|int $attribute_name Top-level key in the decoded block JSON.
	 * @return bool Whether the field may use the existing site base.
	 */
	public function block_attribute_accepts_relative_urls( $attribute_name ): bool {
		$is_relative_url_block_attribute = (
			isset( self::BLOCK_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM[ $this->get_block_name() ] ) &&
			in_array( $attribute_name, self::BLOCK_ATTRIBUTES_TO_ACCEPT_RELATIVE_URLS_FROM[ $this->get_block_name() ], true )
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
				'attribute_name' => $attribute_name,
			)
		);

		return (bool) $is_relative_url_block_attribute;
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
		if ( $raw_url === $this->raw_url ) {
			// A relative URL may resolve against a new base without changing its source spelling.
			$this->parsed_url = $parsed_url;
			return true;
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
	 * It keeps absolute URLs absolute, does not add a scheme to relative URLs,
	 * and preserves every decoded URL byte outside the mapped scheme,
	 * authority, and pathname prefix. Unsupported source spellings are left
	 * unchanged.
	 */
	public function replace_base_url( $to_url, $base_url = null ) {
		$base_url = $base_url ?? $this->base_url_object;
		$base_url = WPURL::parse( $base_url );
		$to_url   = WPURL::parse( $to_url );
		if ( false === $base_url || false === $to_url ) {
			return false;
		}

		$result = WPURL::replace_base_url(
			$this->get_parsed_url(),
			array(
				'old_base_url' => $base_url,
				'new_base_url' => $to_url,
				'raw_url'      => $this->get_raw_url(),
				'is_relative'  => ! $this->is_url_absolute(),
			)
		);

		if ( false === $result ) {
			return false;
		}

		$source_preserving_raw_url = self::create_source_preserving_raw_url(
			$this->get_raw_url(),
			$this->get_parsed_url(),
			$result,
			$base_url,
			$to_url
		);
		if ( null === $source_preserving_raw_url ) {
			/*
			 * The cautious token pass cannot distinguish this structured URL from
			 * opaque token content. Skip that pass rather than bypassing the failed
			 * source-boundary check.
			 */
			$this->structured_url_source_boundary_failed = true;
			return false;
		}

		return $this->set_url(
			$source_preserving_raw_url,
			WPURL::parse( $source_preserving_raw_url, $to_url->toString() )
		);
	}

	/**
	 * Creates a decoded URL with only its base components replaced.
	 *
	 * Only spellings whose base-component boundaries can be located from
	 * literal delimiters are accepted. Ambiguous spellings return null. The
	 * edited string is reparsed and returned only when it matches the normal
	 * semantic conversion.
	 *
	 * @param string $raw_url URL as spelled in its field.
	 * @param URL $url $raw_url parsed against the field's base.
	 * @param \WordPress\DataLiberation\URL\ConvertedUrl $converted_url WPURL::replace_base_url() result for $url.
	 * @param URL $old_base_url Source base that $url is a child of.
	 * @param URL $new_base_url Target base that replaces $old_base_url.
	 * @return string|null $raw_url with its base components replaced, or null
	 *                     when their boundaries cannot be located safely.
	 */
	public static function create_source_preserving_raw_url( $raw_url, $url, $converted_url, $old_base_url, $new_base_url ) {
		$updated_url = $converted_url->new_url;
		$source_url = WPURL::parse( $raw_url, $old_base_url->toString() );
		if (
			false === $source_url ||
			$source_url->toString() !== $url->toString() ||
			! is_child_url_of( $source_url, $old_base_url )
		) {
			return null;
		}

		$is_absolute          = 1 === preg_match( '/\A([A-Za-z][A-Za-z0-9+.\-]*):\/\//', $raw_url, $absolute_match );
		$is_protocol_relative = 0 === strpos( $raw_url, '//' );
		$is_path_relative     = ! $is_absolute && ! $is_protocol_relative && 0 !== strpos( $raw_url, '/' );
		if (
			( $is_absolute && 'file' === strtolower( $absolute_match[1] ) ) ||
			( ! $is_absolute && 1 === preg_match( '/\A[A-Za-z][A-Za-z0-9+.\-]*:/', $raw_url ) ) ||
			( $is_path_relative && ( 0 === strpos( $raw_url, './' ) || 0 === strpos( $raw_url, '../' ) ) )
		) {
			return null;
		}

		$path_end     = strcspn( $raw_url, '?#' );
		$path_start   = 0;
		$replacements = array();
		if ( $is_absolute || $is_protocol_relative ) {
			$scheme_end      = $is_absolute ? strpos( $raw_url, ':' ) : null;
			$authority_start = $is_absolute ? $scheme_end + 3 : 2;
			$path_start      = $authority_start + strcspn( $raw_url, '/?#', $authority_start );
			if ( $authority_start === $path_start ) {
				return null;
			}

			$authority   = substr( $raw_url, $authority_start, $path_start - $authority_start );
			$userinfo_at = strrpos( $authority, '@' );
			$host_start  = $authority_start + ( false === $userinfo_at ? 0 : $userinfo_at + 1 );
			$raw_host    = substr( $raw_url, $host_start, $path_start - $host_start );
			if ( $is_absolute && $url->protocol !== $updated_url->protocol ) {
				$replacements[] = array(
					'start'       => 0,
					'length'      => $scheme_end,
					'replacement' => rtrim( $updated_url->protocol, ':' ),
				);
			}
			if (
				$url->host !== $updated_url->host ||
				(
					$url->protocol !== $updated_url->protocol &&
					1 === preg_match( '/:\d+$/', $raw_host )
				)
			) {
				$replacements[] = array(
					'start'       => $host_start,
					'length'      => $path_start - $host_start,
					'replacement' => $updated_url->host,
				);
			}
		}

		if ( $old_base_url->pathname !== $new_base_url->pathname ) {
			$raw_path = substr( $raw_url, $path_start, $path_end - $path_start );

			/*
			 * Rooted spellings use the parsed base's number of slash-delimited
			 * segments. Relative spellings use the leading reference that resolves
			 * to the base. Parsing that prefix validates its normalized path. Split
			 * before decoding so "%2F" cannot become a delimiter.
			 */
			$source_segment_count = substr_count( rtrim( $old_base_url->pathname, '/' ), '/' );
			if ( $is_path_relative ) {
				$source_segment_count = (
					'' === $raw_path ||
					0 === $source_segment_count ||
					'/' === substr( $old_base_url->pathname, -1 )
				) ? 0 : 1;
			}

			$raw_path_segments = explode( '/', $raw_path );
			$segments_to_use   = $source_segment_count + ( 0 === strpos( $raw_path, '/' ) ? 1 : 0 );
			$source_length     = strlen( implode( '/', array_slice( $raw_path_segments, 0, $segments_to_use ) ) );

			$source_prefix_url = WPURL::parse(
				substr( $raw_url, 0, $path_start + $source_length ),
				$old_base_url->toString()
			);
			if (
				false === $source_prefix_url ||
				$source_prefix_url->protocol !== $old_base_url->protocol ||
				$source_prefix_url->hostname !== $old_base_url->hostname ||
				array_map( 'rawurldecode', explode( '/', rtrim( $source_prefix_url->pathname, '/' ) ) ) !==
					array_map( 'rawurldecode', explode( '/', rtrim( $old_base_url->pathname, '/' ) ) )
			) {
				return null;
			}

			$unmatched_path = substr( $raw_path, $source_length );
			$target_path    = '/' === $new_base_url->pathname ? '' : rtrim( $new_base_url->pathname, '/' );
			if (
				'' !== $target_path &&
				(
					( '' !== $unmatched_path && '/' !== $unmatched_path[0] ) ||
					( '' === $unmatched_path && $path_end < strlen( $raw_url ) ) ||
					( $is_path_relative && '' === $raw_path && '/' === substr( $old_base_url->pathname, -1 ) )
				)
			) {
				$target_path .= '/';
			} elseif (
				'' === $unmatched_path &&
				'' === $target_path &&
				( ! $is_path_relative || '' === $raw_path )
			) {
				$target_path = '/';
			}
			$replacements[] = array(
				'start'       => $path_start,
				'length'      => $source_length,
				'replacement' => $target_path,
			);
		}

		foreach ( $replacements as $replacement ) {
			if ( false !== strpos( substr( $raw_url, $replacement['start'], $replacement['length'] ), '\\' ) ) {
				return null;
			}
		}

		$source_preserving_raw_url = $raw_url;
		foreach ( array_reverse( $replacements ) as $replacement ) {
			$source_preserving_raw_url = substr_replace(
				$source_preserving_raw_url,
				$replacement['replacement'],
				$replacement['start'],
				$replacement['length']
			);
		}

		$source_preserving_url = WPURL::parse( $source_preserving_raw_url, $new_base_url->toString() );
		$expected_url          = WPURL::parse( (string) $converted_url, $new_base_url->toString() );
		if (
			false === $source_preserving_url ||
			false === $expected_url ||
			$source_preserving_url->toString() !== $expected_url->toString()
		) {
			return null;
		}

		return $source_preserving_raw_url;
	}

	/**
	 * Returns true if the raw URL can be parsed without a base URL.
	 *
	 * get_parsed_url() can resolve href="photo.jpg" against a site base such
	 * as https://example.com/shop/. Parsing then succeeds, but "photo.jpg"
	 * still needs that base. can_parse() below checks without one.
	 *
	 * @return bool Whether the currently matched URL is absolute.
	 */
	public function is_url_absolute() {
		if ( ! $this->get_parsed_url() ) {
			return false;
		}
		// A full HTTP(S) prefix already passed parsing without a base.
		// The decoded field can also contain " https://example.com/photo.jpg ":
		// the prefix check misses its space, but the parser accepts it alone.
		// "photo.jpg" needs a base and must stay relative. Check the field text,
		// not get_parsed_url(), which has already resolved it to a complete URL.
		return $this->has_absolute_http_url_prefix( $this->get_raw_url() )
			|| WPURL::can_parse( $this->get_raw_url() );
	}

	/**
	 * Recognize a full HTTP(S) prefix, not shorthand such as `https:photo.jpg`.
	 */
	private function has_absolute_http_url_prefix( string $url ): bool {
		return 0 === strncasecmp( $url, 'https://', 8 ) || 0 === strncasecmp( $url, 'http://', 7 );
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
