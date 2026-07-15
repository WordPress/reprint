<?php

/**
 * Reports a classified failure in the staged-apply lifecycle.
 *
 * PHP reserves Throwable::getCode() for an integer. Staged apply failures use
 * stable, descriptive strings because the endpoint returns the same value in
 * its JSON `reason` field. Keeping that classification in a separate property
 * also prevents an unrelated RuntimeException with a coincidentally equal
 * native code from being presented as a recoverable protocol condition.
 *
 * Only failures which have a deliberate staged-apply classification use this
 * exception. An ordinary RuntimeException remains unclassified and is exposed
 * by the endpoint as `session_rejected` rather than leaking an accidental code.
 */
final class Site_Export_Staged_Apply_Exception extends RuntimeException {

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

    /** @var array<string,mixed> Protocol fields which describe the observed failure. */
    private $context;

    /**
     * Creates a staged-apply failure with separate machine and human context.
     *
     * The machine-readable value comes first so a throw site names its recovery
     * class before giving instance-specific detail. It is retrieved with
     * get_error_code(), never Throwable::getCode().
     *
     * @param string $error_code Stable machine-readable staged-apply reason.
     * @param string $message Human-readable statement of the violated condition.
     * @param array<string,mixed> $context Additional authenticated response fields.
     */
    public function __construct(string $error_code, string $message, array $context = []) {
        parent::__construct($message);
        $this->error_code = $error_code;
        $this->context = $context;
    }

    /**
     * Returns the stable reason used to classify this staged-apply failure.
     *
     * @return string Machine-readable error code suitable for a JSON `reason`.
     */
    public function get_error_code(): string {
        return $this->error_code;
    }

    /** @return array<string,mixed> Structured fields safe to copy into the JSON response. */
    public function get_context(): array {
        return $this->context;
    }
}
