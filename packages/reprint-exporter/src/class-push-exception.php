<?php

/**
 * Reports a classified failure in the push and commit lifecycle.
 *
 * PHP reserves Throwable::getCode() for an integer. Push and commit failures use
 * stable, descriptive strings because the endpoint returns the same value in
 * its JSON `reason` field. Keeping that classification in a separate property
 * also prevents an unrelated RuntimeException with a coincidentally equal
 * native code from being presented as a recoverable protocol condition.
 *
 * Only failures which have a deliberate push or commit classification use this
 * exception. An ordinary RuntimeException remains unclassified and follows the
 * endpoint's `invalid_request` handling rather than leaking an accidental code.
 */
final class Site_Export_Push_Exception extends RuntimeException {

    /**
     * Stable machine-readable classification selected by the throwing class.
     *
     * This is distinct from the human-readable exception message. Endpoint
     * code uses it to select an HTTP status and copies it unchanged into the
     * authenticated response's `reason` field.
     *
     * @var string
     */
    private $error_code;

    /**
     * Protocol fields which describe the observed failure.
     *
     * These values are copied into the authenticated JSON response alongside
     * the stable reason. They are deliberately separate from the message so
     * callers can inspect structured details such as conflicting paths or
     * observed filesystem identities without parsing prose.
     *
     * @var array<string,mixed>
     */
    private $context;

    /**
     * Creates a push or commit failure with separate machine and human context.
     *
     * The machine-readable value comes first so a throw site names its recovery
     * class before giving instance-specific detail. It is retrieved with
     * get_error_code(), never Throwable::getCode().
     *
     * @param string $error_code Stable machine-readable push or commit reason.
     * @param string $message Human-readable statement of the violated condition.
     * @param array<string,mixed> $context Additional authenticated response
     *     fields. Common keys are operation, path_b64, conflict_path_b64,
     *     expected_docroot_types, observed_docroot_identity, work_device,
     *     document-root_device, work_type, and detail.
     */
    public function __construct(string $error_code, string $message, array $context = []) {
        parent::__construct($message);
        $this->error_code = $error_code;
        $this->context = $context;
    }

    /**
     * Returns the stable reason used to classify this push or commit failure.
     *
     * @return string Machine-readable error code suitable for a JSON `reason`.
     */
    public function get_error_code(): string {
        return $this->error_code;
    }

    /**
     * Returns structured details for the authenticated failure response.
     *
     * The array contains only values supplied by push or commit throw sites. It
     * may be empty for simple classified failures, but when present it names
     * the exact observed condition that made the request non-recoverable or
     * recoverable.
     *
     * @return array<string,mixed> Structured fields safe to copy into JSON.
     *     Common keys are operation, path_b64, conflict_path_b64,
     *     expected_docroot_types, observed_docroot_identity, work_device,
     *     document-root_device, work_type, and detail.
     */
    public function get_context(): array {
        return $this->context;
    }
}
