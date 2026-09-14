<?php
/** Checks the actual reader before running the cross-host migration. */
use WordPress\Reprint\Server\WindowsFilesystem;
use WordPress\Reprint\Server\FileTreeProducer;
use function WordPress\Reprint\Server\source_io_path;

require dirname(__DIR__, 3) . '/packages/reprint-server/src/utils.php';
require dirname(__DIR__, 3) . '/packages/reprint-server/src/class-windows-filesystem.php';
require dirname(__DIR__, 3) . '/packages/reprint-server/src/class-file-tree-producer.php';

if (($argv[1] ?? '') === '--without-ffi') {
    if (WindowsFilesystem::available()) {
        throw new RuntimeException('Disabled FFI must not register native file access.');
    }
    $ordinary = 'D:/Reprint namespace cases/Mixed Case/trailing';
    if (source_io_path($ordinary) !== $ordinary || file_get_contents($ordinary) !== 'ordinary sibling!') {
        throw new RuntimeException('Ordinary source reads must still work without FFI.');
    }
    try {
        source_io_path($ordinary . '.');
        throw new LogicException('A literal source name silently fell back to ordinary PHP normalization.');
    } catch (RuntimeException $error) {
        if (strpos($error->getMessage(), 'Cannot read the exact Windows filename') === false) {
            throw $error;
        }
    }
    echo "PASS: ordinary paths work without FFI; literal suffixes fail rather than aliasing.\n";
    exit;
}

if (!WindowsFilesystem::available()) {
    throw new RuntimeException('The Windows CI host must provide the native source reader.');
}
$path = WindowsFilesystem::resolve_input('\\\\?\\D:\\Reprint namespace cases\\Mixed Case\\trailing.');
$uri = source_io_path($path);
$stat = lstat($uri);
if ($stat['size'] !== 17 || ($stat['mode'] & 0170000) !== 0100000) {
    throw new RuntimeException('Native stat must describe the literal file.');
}
$file = fopen($uri, 'rb');
$first = fread($file, 8);
$offset = ftell($file);
$seek = fseek($file, 3);
$tail = fread($file, 14);
if ($first !== 'literal ' || $offset !== 8 || $seek !== 0 || $tail !== 'eral trailing.') {
    throw new RuntimeException('Native read/seek mismatch: ' . json_encode(compact('path', 'first', 'offset', 'seek', 'tail')));
}
fclose($file);
$before = hash_file('sha256', $uri);
foreach (['w', 'a', 'r+'] as $mode) {
    try {
        fopen($uri, $mode);
        throw new LogicException('The native source reader accepted a write mode: ' . $mode);
    } catch (InvalidArgumentException $error) {
        if (strpos($error->getMessage(), 'read-only') === false) {
            throw $error;
        }
    }
}
if (hash_file('sha256', $uri) !== $before) {
    throw new RuntimeException('Rejected write modes changed the source file.');
}
$names = scandir(source_io_path(dirname($path)));
foreach (['trailing', 'trailing.', 'trailing ', 'NUL.txt', 'COM1.txt', 'COM¹.txt'] as $name) {
    if (!in_array($name, $names, true)) {
        throw new RuntimeException('Native directory search omitted ' . $name);
    }
}
foreach (['\\\\.\\PhysicalDrive0', '\\\\.\\pipe\\reprint-test'] as $device) {
    try {
        WindowsFilesystem::resolve_input($device);
        throw new LogicException('The source reader accepted a device: ' . $device);
    } catch (InvalidArgumentException $error) {
        if (strpos($error->getMessage(), 'Windows device names cannot select migration files') === false) {
            throw $error;
        }
    }
}

// Resume the real producer after one chunk. A short ordinary sibling must not
// replace the literal file's size when restoring its cursor.
$path = 'D:/Reprint chunk boundaries/literal.';
$options = ['paths' => [$path], 'chunk_size' => 8192];
$producer = new FileTreeProducer(dirname($path), $options);
for ($number = 0; $number < 2; ++$number) {
    if (!$producer->next_chunk()) {
        throw new RuntimeException('Native producer stopped before its next chunk.');
    }
    $chunk = $producer->get_current_chunk();
    if ($chunk['type'] !== 'file' || $chunk['data'] !== str_repeat('A', 8192) || $chunk['offset'] !== $number * 8192 || $chunk['size'] !== 16384) {
        unset($chunk['data']);
        throw new RuntimeException('Native producer lost bytes or metadata across resume: ' . json_encode($chunk));
    }
    if ($number === 0) {
        $options['cursor'] = $producer->get_reentrancy_cursor();
        unset($producer);
        $producer = new FileTreeProducer(dirname($path), $options);
    }
}
if (!$chunk['is_last_chunk'] || $producer->next_chunk()) {
    throw new RuntimeException('An exact final native chunk must complete without an extra read error.');
}
unset($producer);

// A host may tighten open_basedir after initialization. Retained registration
// must not make a later native open escape that PHP restriction.
ini_set('open_basedir', __DIR__);
if (WindowsFilesystem::available()) {
    throw new RuntimeException('Native source reads must be disabled under open_basedir.');
}
try {
    WindowsFilesystem::resolve_input('D:/');
    throw new LogicException('Native path resolution bypassed open_basedir.');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'open_basedir unset') === false) {
        throw $error;
    }
}
echo "PASS: native stat, bounded reads, seek, resume, exact names, read-only modes, device rejection, and open_basedir.\n";
