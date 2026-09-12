<?php
/** Records native PHP behavior before choosing the Windows filesystem calls. */
$cases = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
printf("Windows PHP %s: FFI class=%s, ffi.enable=%s, cwd=%s\n", PHP_VERSION, class_exists('FFI') ? 'yes' : 'no', ini_get('ffi.enable'), getcwd());
foreach ($cases as $name => $case) {
    if (isset($case['error'])) {
        continue;
    }
    $path = str_replace('/', '\\', $case['source']);
    if (substr($case['destination'], -10) === '/hello.txt') {
        $path .= '\\hello.txt';
    }
    clearstatcache();
    $stat = @lstat($path);
    printf("NATIVE: %s\n", json_encode([
        'case' => $name,
        'realpath' => @realpath($path),
        'stat' => $stat === false ? false : ['mode' => $stat['mode'], 'size' => $stat['size']],
        'read_matches' => @file_get_contents($path) === $case['content'],
    ], JSON_UNESCAPED_SLASHES));
}
