<?php

namespace Reprint\Importer;

/**
 * Context object passed to streaming callbacks.
 */
class StreamingContext {

    public $on_chunk = null;
    public $file_handle = null;
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
