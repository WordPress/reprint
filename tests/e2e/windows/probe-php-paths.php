<?php
/** Records how the installed Windows PHP reads the real path fixtures without FFI. */
if (PHP_OS !== 'WINNT' || extension_loaded('FFI')) {
    throw new RuntimeException('This probe requires Windows PHP without the FFI extension.');
}
$root = 'D:\\Reprint namespace cases\\Mixed Case\\';
foreach (['trailing', 'trailing.', 'trailing ', 'NUL.txt', 'COM1.txt', 'COM¹.txt'] as $name) {
    foreach ([$root . $name, '\\\\?\\' . $root . $name, 'file://' . '\\\\?\\' . $root . $name] as $path) {
        error_clear_last();
        $contents = @file_get_contents($path);
        echo json_encode(['path' => $path, 'contents' => $contents, 'error' => error_get_last()['message'] ?? null,
            'realpath' => realpath($path), 'stat' => @lstat($path)], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
$cases = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $name => $case) {
    if ($name === 'physical-device') {
        continue;
    }
    foreach ((array) $case['source'] as $path) {
        error_clear_last();
        $resolved = @realpath($path);
        $stat = @lstat($path);
        echo json_encode(['case' => $name, 'path' => $path, 'realpath' => $resolved,
            'type' => $stat === false ? null : ($stat['mode'] & 0170000),
            'target' => is_link($path) ? readlink($path) : null, 'error' => error_get_last()['message'] ?? null], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
