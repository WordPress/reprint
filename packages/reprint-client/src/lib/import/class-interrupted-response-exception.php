<?php

namespace Reprint\Importer;

use RuntimeException;

/**
 * Thrown when a streaming response loses its framing or ends before a valid
 * completion part.
 */
class InterruptedResponseException extends RuntimeException {

    /** @var array */
    private $response_details;

    /** @var array Keys always present in get_response_details(), defaulted to null. */
    private const DEFAULT_RESPONSE_DETAILS = [
        'response_bytes_received' => null,
        'http_code' => null,
        'curl_errno' => null,
        'curl_error' => null,
        'protocol' => null,
        'request_seconds' => null,
        'completion_seen' => null,
    ];

    /**
     * @param string $message          Exception message.
     * @param array  $response_details {
     *     Diagnostic details about last HTTP response seen before interrupt.
     *     Every key defaults to null when caller does not supply it.
     *
     *     @type int|null    $response_bytes_received Bytes received before interruption.
     *     @type int|null    $http_code               HTTP status code, or 0 when none.
     *     @type int|null    $curl_errno              cURL error number, or 0 when none.
     *     @type string|null $curl_error              cURL error message, or null when none.
     *     @type string|null $protocol                Wire protocol name.
     *     @type float|null  $request_seconds         Total time libcurl spent on request.
     *     @type bool|null   $completion_seen         Whether a completion chunk was parsed.
     * }
     */
    public function __construct(string $message = '', array $response_details = []) {
        parent::__construct($message);
        $this->response_details = array_merge(self::DEFAULT_RESPONSE_DETAILS, $response_details);
    }

    /** @return array Diagnostic details about last HTTP response seen before interrupt. */
    public function get_response_details(): array {
        return $this->response_details;
    }
}
