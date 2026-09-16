<?php
/** Checks PHP source reads and explicit failures before the cross-host migration. */
use WordPress\Reprint\Server\FileIndexProcessor;
use WordPress\Reprint\Server\FileTreeProducer;
use function WordPress\Reprint\Server\source_io_path;

require dirname(__DIR__, 3) . '/packages/reprint-server/src/export.php';
restore_error_handler();
restore_exception_handler();

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI fixture errors, not HTML.

if (PHP_OS !== 'WINNT' || extension_loaded('FFI')) {
    throw new RuntimeException('Windows migration checks require Windows PHP with the FFI extension absent.');
}
// This is a real PHP limitation, not a fabricated access failure. Ordinary stat
// aliases a trailing-dot file to its sibling, despite their different contents.
$reprint_root = 'D:/Reprint literal cases/Mixed Case';
$reprint_names = scandir($reprint_root);
foreach (['trailing', 'trailing.', 'trailing '] as $reprint_name) {
    if (!in_array($reprint_name, $reprint_names, true)) {
        throw new RuntimeException('The literal fixture is missing: ' . $reprint_name);
    }
}
if (file_get_contents(source_io_path($reprint_root . '/trailing')) !== 'ordinary sibling!') {
    throw new RuntimeException('Ordinary PHP source reads must work.');
}
foreach (['trailing.', 'trailing '] as $reprint_name) {
    try {
        source_io_path($reprint_root . '/' . $reprint_name);
        throw new LogicException('An unreadable Windows name reached ordinary PHP I/O.');
    } catch (RuntimeException $reprint_error) {
        if (strpos($reprint_error->getMessage(), 'Cannot read the exact Windows filename') === false) {
            throw $reprint_error;
        }
    }
}
// Start with a valid parent selection, then encounter the unreadable child in
// the real directory listing. Saving that error cursor must not skip the child.
$reprint_roots = resolve_file_index_roots(['directory' => [$reprint_root]]);
$reprint_processor = FileIndexProcessor::start($reprint_roots, $reprint_roots[0], false, '');
$reprint_first_error = null;
for ($reprint_attempt = 0; $reprint_attempt < 2; ++$reprint_attempt) {
    try {
        $reprint_steps = 0;
        while ($reprint_processor->next_index_step()) {
            if (++$reprint_steps > 100) {
                throw new LogicException('The index did not reach the unreadable child.');
            }
        }
        throw new LogicException('Indexing skipped an unreadable child.');
    } catch (RuntimeException $reprint_error) {
        if (strpos($reprint_error->getMessage(), 'Cannot read the exact Windows filename') === false) {
            throw $reprint_error;
        }
    }
    if ($reprint_first_error !== null && $reprint_first_error !== $reprint_error->getMessage()) {
        throw new RuntimeException('Resume skipped the first unreadable child.');
    }
    $reprint_first_error = $reprint_error->getMessage();
    $reprint_cursor = json_encode($reprint_processor->get_cursor());
    $reprint_processor->close();
    $reprint_processor = FileIndexProcessor::resume($reprint_roots, $reprint_cursor, false, '');
}
$reprint_processor->close();

// Resume the real PHP reader after one chunk, without a native stream wrapper.
$reprint_path = 'D:/Reprint chunk boundaries/readable';
$reprint_options = ['paths' => [$reprint_path], 'chunk_size' => 8192];
$reprint_producer = new FileTreeProducer(dirname($reprint_path), $reprint_options);
for ($reprint_number = 0; $reprint_number < 2; ++$reprint_number) {
    if (!$reprint_producer->next_chunk()) {
        throw new RuntimeException('PHP producer stopped before its next chunk.');
    }
    $reprint_chunk = $reprint_producer->get_current_chunk();
    if ($reprint_chunk['type'] !== 'file' || $reprint_chunk['data'] !== str_repeat('A', 8192) || $reprint_chunk['offset'] !== $reprint_number * 8192 || $reprint_chunk['size'] !== 16384) {
        unset($reprint_chunk['data']);
        throw new RuntimeException('PHP producer lost bytes or metadata across resume: ' . json_encode($reprint_chunk));
    }
    if ($reprint_number === 0) {
        $reprint_options['cursor'] = $reprint_producer->get_reentrancy_cursor();
        unset($reprint_producer);
        $reprint_producer = new FileTreeProducer(dirname($reprint_path), $reprint_options);
    }
}
if (!$reprint_chunk['is_last_chunk'] || $reprint_producer->next_chunk()) {
    throw new RuntimeException('An exact final chunk must complete without an extra read error.');
}
unset($reprint_producer);

// PHP itself enforces open_basedir; there is no second filesystem API.
ini_set('open_basedir', __DIR__);
if (@file_get_contents(source_io_path($reprint_path)) !== false) {
    throw new RuntimeException('Source file access bypassed open_basedir.');
}
echo "PASS: PHP reads, seek, resume, unreadable names, error cursors, and open_basedir without FFI.\n";
