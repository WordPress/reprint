<?php

namespace Reprint\Importer;

/**
 * Context object passed to streaming callbacks.
 */
class StreamingContext {

    public $on_chunk = null;
    public $file_handle = null;
    /**
     * Rewrites the current CSS file and retains incomplete matches across body
     * callbacks. Null for files copied without CSS URL rewriting.
     *
     * @var \WordPress\DataLiberation\URL\CSSURLProcessor|null
     */
    public $css_url_rewriter = null;
    public $file_path = null;
    public $file_ctime = null;
    // Crash recovery: track bytes written for current file
    public $file_bytes_written = 0;
    // Last response stats from completion chunk
    public $response_stats = [];
    // Stream integrity
    public $saw_completion = false;
    // When true, skip writing the current file (preserve-local mode)
    public $skip_current_file = false;
}
