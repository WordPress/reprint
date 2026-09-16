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
// PHP realpath() returns backslashes. Index comparisons still use the shared
// Windows spelling, so a parent plus an explicit child must not walk it twice.
$reprint_roots = resolve_file_index_roots(['directory' => [
    'D:/Reprint namespace cases', 'D:/Reprint namespace cases/Mixed Case',
]]);
$reprint_processor = FileIndexProcessor::start($reprint_roots, $reprint_roots[0], false, '');
$reprint_paths = [];
while ($reprint_processor->next_index_step()) {
    foreach ($reprint_processor->get_index_entries() as $reprint_entry) {
        if (in_array($reprint_entry['path'], $reprint_paths, true)) {
            throw new RuntimeException('Overlapping Windows roots repeated a path: ' . $reprint_entry['path']);
        }
        $reprint_paths[] = $reprint_entry['path'];
    }
}
$reprint_processor->close();
if (!in_array('D:/Reprint namespace cases/Mixed Case/hello.txt', $reprint_paths, true)) {
    throw new RuntimeException('Overlapping Windows roots omitted the selected child.');
}
$reprint_roots = resolve_file_index_roots(['directory' => ['D:/Reprint link cases/junction'], 'follow_symlinks' => true]);
if ($reprint_roots[0]['type'] !== 'symlink') {
    throw new RuntimeException('PHP junction metadata must become a link, not a directory.');
}
$reprint_saw_junction = false;
$reprint_processor = FileIndexProcessor::start($reprint_roots, $reprint_roots[0], true, '');
while ($reprint_processor->next_index_step()) {
    foreach ($reprint_processor->get_index_entries() as $reprint_entry) {
        $reprint_saw_junction = $reprint_saw_junction || $reprint_entry['path'] === 'D:/Reprint link cases/junction';
        if (isset($reprint_entry['target']) && $reprint_entry['target'] !== 'D:/Reprint namespace cases/Mixed Case') {
            throw new RuntimeException('A Windows index target retained native separators: ' . $reprint_entry['target']);
        }
    }
}
$reprint_processor->close();

if (!$reprint_saw_junction) {
    throw new RuntimeException('The index omitted the selected junction.');
}

// Read failures can occur in a directory entry or a selected named link.
// Their cursors must retain the same entry, without skipping it on resume.
foreach ([
    'D:/Reprint literal cases/Mixed Case' => 'Cannot read the exact Windows filename',
    'D:/Reprint unreadable links' => 'PHP cannot read the Windows link target',
    'D:/Reprint link cases/target-forward-slash-directory' => 'PHP cannot read the Windows link target',
] as $reprint_selection => $reprint_expected_error) {
    $reprint_roots = resolve_file_index_roots(['directory' => [$reprint_selection], 'follow_symlinks' => true]);
    $reprint_processor = FileIndexProcessor::start($reprint_roots, $reprint_roots[0], true, '');
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
            if (strpos($reprint_error->getMessage(), $reprint_expected_error) === false) {
                throw $reprint_error;
            }
        }
        if ($reprint_first_error !== null && $reprint_first_error !== $reprint_error->getMessage()) {
            throw new RuntimeException('Resume skipped the first unreadable child.');
        }
        $reprint_first_error = $reprint_error->getMessage();
        $reprint_cursor = json_encode($reprint_processor->get_cursor());
        $reprint_processor->close();
        $reprint_processor = FileIndexProcessor::resume($reprint_roots, $reprint_cursor, true, '');
    }
    $reprint_processor->close();

}

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

// Ordinary UNC access fails beyond MAX_PATH on this PHP build. The PHP-supported
// device spelling must remain inside I/O calls, never in index paths or cursors.
$reprint_long_path = '\\\\LOCALHOST\\D$/Reprint reader UNC/' . str_repeat('a', 251) . '.txt';
$reprint_long_stat = \WordPress\Reprint\Server\source_lstat($reprint_long_path);
if ($reprint_long_stat === false || $reprint_long_stat['size'] !== 13
    || \WordPress\Reprint\Server\source_realpath($reprint_long_path) !== $reprint_long_path
    || file_get_contents(source_io_path($reprint_long_path)) !== 'long UNC file') {
    throw new RuntimeException('PHP must read the long UNC file without changing its shared path.');
}
$reprint_producer = new FileTreeProducer(dirname($reprint_long_path), ['paths' => [$reprint_long_path]]);
if (!$reprint_producer->next_chunk() || $reprint_producer->get_current_chunk()['data'] !== 'long UNC file') {
    throw new RuntimeException('The real file producer could not read the long UNC file through PHP.');
}
unset($reprint_producer);

// Ordinary allowed shares must still work under PHP's open_basedir policy.
$reprint_short_share_path = '\\\\LOCALHOST\\D$/Reprint reader UNC/readable.txt';
ini_set('open_basedir', __DIR__ . PATH_SEPARATOR . dirname($reprint_short_share_path));
if (file_get_contents(source_io_path($reprint_short_share_path)) !== 'short UNC file') {
    throw new RuntimeException('An allowed ordinary share stopped working under open_basedir.');
}
// PHP itself enforces open_basedir, including the long UNC I/O spelling.
ini_set('open_basedir', __DIR__);
foreach ([$reprint_path, $reprint_short_share_path, $reprint_long_path] as $reprint_denied_path) {
    if (@file_get_contents(source_io_path($reprint_denied_path)) !== false) {
        throw new RuntimeException('Source file access bypassed open_basedir.');
    }
}
echo "PASS: PHP reads, seek, resume, unreadable names, error cursors, long UNC paths, and open_basedir without FFI.\n";
