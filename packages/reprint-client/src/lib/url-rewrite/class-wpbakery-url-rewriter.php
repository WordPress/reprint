<?php

/**
 * Supplies WPBakery shortcode codecs to the generic structured URL rewriter.
 *
 * WPBakery controls store their values in several private formats. This class
 * contains those format rules. Shortcode traversal, parser order, and recursive
 * format detection remain in StructuredDataUrlRewriter.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- URL rewriter classes use unprefixed domain names.
class WPBakeryUrlRewriter {
	/** @var Closure(string): string */
	private Closure $rewrite_block_markup;

	/** @var Closure(string): string */
	private Closure $rewrite_plain_text;

	/**
	 * @param callable(string): string $rewrite_block_markup Rewrite decoded HTML and block markup.
	 * @param callable(string): string $rewrite_plain_text   Rewrite one decoded plain-text value.
	 */
	public function __construct( callable $rewrite_block_markup, callable $rewrite_plain_text ) {
		$this->rewrite_block_markup = Closure::fromCallable( $rewrite_block_markup );
		$this->rewrite_plain_text   = Closure::fromCallable( $rewrite_plain_text );
	}

	/**
	 * Return body codecs keyed by shortcode tag.
	 *
	 * @return array<string, Closure(string): string>
	 */
	public function get_shortcode_body_rewriters(): array {
		return array(
			'vc_table'    => function ( string $body ): string {
				return $this->rewrite_table_body( $body );
			},
			'vc_raw_html' => function ( string $body ): string {
				return $this->rewrite_raw_code_body( $body );
			},
			'vc_raw_js'   => function ( string $body ): string {
				return $this->rewrite_raw_code_body( $body );
			},
		);
	}

	/**
	 * Return attribute codecs keyed by shortcode tag and attribute name.
	 *
	 * @return array<string, array<string, array{rewrite: Closure(string): string, hides_url: bool}>>
	 */
	public function get_shortcode_attribute_rewriters(): array {
		$link_attribute_rewriter = array(
			'rewrite'   => function ( string $value ): string {
				return $this->rewrite_link_attribute( $value );
			},
			'hides_url' => false,
		);
		$safe_attribute_rewriter = array(
			'rewrite'   => function ( string $value ): string {
				return $this->rewrite_safe_attribute( $value );
			},
			'hides_url' => true,
		);

		return array(
			'vc_btn'             => array(
				'link' => $link_attribute_rewriter,
				'url'  => $link_attribute_rewriter,
			),
			'vc_button2'         => array(
				'link' => $link_attribute_rewriter,
				'url'  => $link_attribute_rewriter,
			),
			'vc_cta_button2'     => array(
				'link' => $link_attribute_rewriter,
			),
			'vc_custom_heading'  => array(
				'link' => $link_attribute_rewriter,
				'url'  => $link_attribute_rewriter,
			),
			'vc_gallery'         => array(
				'custom_links' => $safe_attribute_rewriter,
				'custom_srcs'  => $safe_attribute_rewriter,
			),
			'vc_gmaps'           => array(
				'link' => $safe_attribute_rewriter,
			),
			'vc_gitem_image'     => array(
				'url' => $link_attribute_rewriter,
			),
			'vc_gitem_zone'      => array(
				'url' => $link_attribute_rewriter,
			),
			'vc_gitem_zone_a'    => array(
				'url' => $link_attribute_rewriter,
			),
			'vc_gitem_zone_b'    => array(
				'url' => $link_attribute_rewriter,
			),
			'vc_icon'            => array(
				'link' => $link_attribute_rewriter,
				'url'  => $link_attribute_rewriter,
			),
			'vc_images_carousel' => array(
				'custom_links' => $safe_attribute_rewriter,
			),
			'vc_posts_slider'    => array(
				'custom_links' => $safe_attribute_rewriter,
			),
			'vc_single_image'    => array(
				'url' => $link_attribute_rewriter,
			),
		);
	}

	/**
	 * Rewrite WPBakery Easy Tables cells without changing table delimiters.
	 *
	 * Easy Tables URL-encodes each cell while leaving `,` and `|` as table
	 * delimiters. Optional leading cell-style markers stay outside the encoded
	 * value and must be copied unchanged.
	 */
	private function rewrite_table_body( string $body ): string {
		$parts = preg_split( '/([,|])/', $body, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $body;
		}

		foreach ( $parts as $index => $cell ) {
			if ( ',' === $cell || '|' === $cell ) {
				continue;
			}

			$prefix_length = 0;
			if ( 1 === preg_match( '/\A(?:\[[^\]\r\n]*\])*/', $cell, $matches ) ) {
				$prefix_length = strlen( $matches[0] );
			}
			$encoded_content = substr( $cell, $prefix_length );
			if ( 1 !== preg_match( '/%[0-9A-Fa-f]{2}/', $encoded_content ) ) {
				continue;
			}

			$decoded_content   = rawurldecode( $encoded_content );
			$rewritten_content = ( $this->rewrite_block_markup )( $decoded_content );
			if ( $rewritten_content === $decoded_content ) {
				continue;
			}

			$parts[ $index ] = substr( $cell, 0, $prefix_length )
				. $this->encode_url_component( $rewritten_content );
		}

		return implode( '', $parts );
	}

	/**
	 * Rewrite either form of a WPBakery Raw HTML or Raw JS body.
	 *
	 * Both elements use textarea_raw_html, which stores Base64 content. Values
	 * saved by its editor control add URL encoding inside the Base64 layer. The
	 * rewritten body uses the same form.
	 */
	private function rewrite_raw_code_body( string $body ): string {
		$decoded_body = base64_decode( $body, true );
		if ( false === $decoded_body || base64_encode( $decoded_body ) !== $body ) {
			return $body;
		}

		$uses_url_encoding = 1 === preg_match(
			'/%(?:3c|3e|5b|5d)|https?%3a%2f%2f/i',
			$decoded_body
		);
		$decoded_content   = $uses_url_encoding
			? rawurldecode( $decoded_body )
			: $decoded_body;
		$rewritten_content = ( $this->rewrite_block_markup )( $decoded_content );
		if ( $rewritten_content === $decoded_content ) {
			return $body;
		}

		if ( $uses_url_encoding ) {
			$rewritten_content = $this->encode_url_component( $rewritten_content );
		}

		return base64_encode( $rewritten_content );
	}

	/**
	 * Rewrite a WPBakery textarea_safe or exploded_textarea_safe attribute.
	 *
	 * These controls store `#E-8_`, followed by Base64-encoded, URL-encoded
	 * content. The rewritten value keeps that wrapper and encoding order.
	 */
	private function rewrite_safe_attribute( string $value ): string {
		$prefix = '#E-8_';
		if ( 0 !== strncmp( $value, $prefix, strlen( $prefix ) ) ) {
			return $value;
		}

		$encoded_content = substr( $value, strlen( $prefix ) );
		$decoded_content = base64_decode( $encoded_content, true );
		if ( false === $decoded_content || base64_encode( $decoded_content ) !== $encoded_content ) {
			return $value;
		}

		$decoded_content   = rawurldecode( $decoded_content );
		$rewritten_content = ( $this->rewrite_block_markup )( $decoded_content );
		if ( $rewritten_content === $decoded_content ) {
			return $value;
		}

		return $prefix . base64_encode( $this->encode_url_component( $rewritten_content ) );
	}

	/**
	 * Rewrite the URL field in a WPBakery vc_link attribute.
	 *
	 * The control stores URL-encoded fields separated by pipes, for example
	 * `url:https%3A%2F%2Fexample.com|title:Read|target:%20_blank|`.
	 */
	private function rewrite_link_attribute( string $value ): string {
		$fields = explode( '|', $value );
		foreach ( $fields as $index => $field ) {
			if ( 0 !== strncmp( $field, 'url:', 4 ) ) {
				continue;
			}

			$encoded_url   = substr( $field, 4 );
			$decoded_url   = rawurldecode( $encoded_url );
			$rewritten_url = ( $this->rewrite_plain_text )( $decoded_url );
			if ( $rewritten_url === $decoded_url ) {
				continue;
			}

			$fields[ $index ] = 'url:' . $this->encode_url_component( $rewritten_url );
		}

		return implode( '|', $fields );
	}

	private function encode_url_component( string $value ): string {
		return str_replace(
			array( '%21', '%27', '%28', '%29', '%2A' ),
			array( '!', "'", '(', ')', '*' ),
			rawurlencode( $value )
		);
	}
}
