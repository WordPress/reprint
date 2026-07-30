<?php
/**
 * WordPress.com API response handling for Reprint export provisioning.
 */

// Runtime exception messages are not rendered as HTML in this library.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Selects the Reprint control route and direct export query parameter exposed
 * by a site.
 *
 * The Jetpack route probe is the capability check: a missing route is the only
 * condition which falls back to wpcomsh. Other failures must remain visible.
 *
 * @param array $response {
 *     Response from wpcom_post().
 *
 *     @type int          $status Outer HTTP status.
 *     @type string|false $body   Raw response body.
 *     @type mixed        $json   Decoded response body.
 * }
 * @return array {
 *     Selected export API.
 *
 *     @type string $surface            `jetpack` or `wpcomsh`.
 *     @type string $rotate_secret_path REST route which rotates the HMAC secret.
 *     @type string $query_parameter    Query parameter which serves direct exports.
 * }
 * @phpstan-return array{
 *     surface:'jetpack'|'wpcomsh',
 *     rotate_secret_path:string,
 *     query_parameter:string
 * }
 */
function reprint_wpcom_export_api( array $response ): array {
    [$status, ] = reprint_wpcom_bridge_response( $response );

    if ( $status === 200 ) {
        return [
            'surface' => 'jetpack',
            'rotate_secret_path' => '/jetpack/v4/reprint/rotate-export-secret',
            'query_parameter' => 'reprint-api-jetpack',
        ];
    }
    if ( $status === 404 ) {
        return [
            'surface' => 'wpcomsh',
            'rotate_secret_path' => '/wpcomsh/v1/reprint/rotate-export-secret',
            'query_parameter' => 'reprint-api',
        ];
    }

    throw new RuntimeException(
        "The Jetpack enable-export probe reported status {$status}: " . substr( (string) ( $response['body'] ?? '' ), 0, 300 )
    );
}

/**
 * Extracts the HMAC secret from a successful Jetpack bridge response.
 *
 * Jetpack returns `{secret}` while wpcomsh returns `{data:{secret}}`.
 *
 * @param array $response {
 *     Response from wpcom_post().
 *
 *     @type int          $status Outer HTTP status.
 *     @type string|false $body   Raw response body.
 *     @type mixed        $json   Decoded response body.
 * }
 */
function reprint_wpcom_export_secret( array $response ): string {
    [$status, $body] = reprint_wpcom_bridge_response( $response );

    if ( $status !== 200 ) {
        throw new RuntimeException(
            "The rotate-export-secret request reported status {$status}: " . substr( (string) ( $response['body'] ?? '' ), 0, 300 )
        );
    }

    $secret = is_array( $body ) ? ( $body['secret'] ?? $body['data']['secret'] ?? null ) : null;
    if ( ! is_string( $secret ) || $secret === '' ) {
        throw new RuntimeException(
            'The rotate-export-secret response did not contain a non-empty secret: ' .
            substr( (string) ( $response['body'] ?? '' ), 0, 400 )
        );
    }

    return $secret;
}

/**
 * Reads the inner status and body from a Jetpack bridge HTTP envelope.
 *
 * The bridge may JSON-encode its inner body. A missing REST route may also use
 * `rest_no_route` inside that body instead of an envelope-level 404.
 *
 * @param array $response {
 *     Response from wpcom_post().
 *
 *     @type int          $status Outer HTTP status.
 *     @type string|false $body   Raw response body.
 *     @type mixed        $json   Decoded response body.
 * }
 * @return array {
 *     Parsed bridge response.
 *
 *     @type int        $0 Inner HTTP status.
 *     @type array|null $1 Decoded inner response body.
 * }
 * @phpstan-return array{0:int,1:array<mixed>|null}
 */
function reprint_wpcom_bridge_response( array $response ): array {
    $outer_status = (int) ( $response['status'] ?? 0 );
    if ( $outer_status !== 0 && ( $outer_status < 200 || $outer_status >= 300 ) ) {
        return [ $outer_status, null ];
    }

    $envelope = $response['json'] ?? null;
    if ( ! is_array( $envelope ) ) {
        return [ 0, null ];
    }

    $body = $envelope['body'] ?? null;
    if ( is_string( $body ) ) {
        $body = json_decode( $body, true );
    }
    if ( ! is_array( $body ) ) {
        $body = null;
    }

    if ( ( $body['code'] ?? null ) === 'rest_no_route' ) {
        return [ 404, $body ];
    }

    return [ (int) ( $envelope['code'] ?? 0 ), $body ];
}

// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
