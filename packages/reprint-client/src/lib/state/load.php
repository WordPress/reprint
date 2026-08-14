<?php
declare(strict_types=1);

/**
 * Typed pull-state objects.
 *
 * Reprint persists pull state as JSON, so these objects keep explicit
 * in-process property names while to_array()/from_array() define the current
 * on-disk schema.
 */

require_once __DIR__ . '/class-resumable-command-checkpoint-state.php';
require_once __DIR__ . '/class-database-table-index-state.php';
require_once __DIR__ . '/class-file-diff-progress-state.php';
require_once __DIR__ . '/class-remote-file-index-cursor-state.php';
require_once __DIR__ . '/class-fetch-list-progress-state.php';
require_once __DIR__ . '/class-files-pull-summary-state.php';
require_once __DIR__ . '/class-database-apply-command-state.php';
require_once __DIR__ . '/class-database-url-rewrite-command-state.php';
require_once __DIR__ . '/class-adaptive-tuning-state.php';
require_once __DIR__ . '/class-pull-pipeline-checkpoint-state.php';
require_once __DIR__ . '/class-pull-state.php';
