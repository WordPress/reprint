<?php

/**
 * A source-delivered result that reported a non-200 HTTP status.
 *
 * The transport reads the (small) error body and unwinds with it; the
 * ImportClient guard converts this with the same diagnosis the curl path
 * applies (diagnose_http_error + the server stack-trace append), so both
 * transports fail with identical operator-facing errors — including the
 * "HTTP error 4xx" message shapes that callers classify on.
 */
final class ReverseTransportHttpError extends RuntimeException
{
    /** @var int */
    public int $http_code;

    /** @var string The error response body (gunzipped, capped). */
    public string $body;

    public function __construct( int $http_code, string $body )
    {
        parent::__construct( "reverse-transport export request returned HTTP {$http_code}" );
        $this->http_code = $http_code;
        $this->body      = $body;
    }
}
