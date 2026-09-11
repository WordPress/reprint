<?php
/** Records native Windows path behavior before choosing portable spellings. */
require __DIR__ . '/../../../packages/reprint-server/src/export.php';
$root = 'D:\\Reprint path probes';
mkdir($root);
$paths = [
    $root,
    str_replace('\\', '/', $root),
    lcfirst($root),
    'D:/Reprint path probes\\',
    'D:\\\\Reprint path probes',
    '\\\\?\\' . $root,
    '\\\\localhost\\D$\\Reprint path probes',
    '\\\\?\\UNC\\localhost\\D$\\Reprint path probes',
];
foreach ($paths as $path) {
    $record = ['path' => $path, 'realpath' => realpath($path), 'is_dir' => is_dir($path)];
    try {
        $record['resolved'] = resolve_directories(['directory' => $path]);
    } catch (Throwable $error) {
        $record['error'] = $error->getMessage();
    }
    echo 'PATH PROBE ' . json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
}
$names = [
    str_repeat('é', 125) . '.txt',
    str_repeat('é', 126) . '.txt',
    str_repeat('漢', 90) . '.txt',
    'café.txt',
    "cafe\u{0301}.txt",
    'trailing space ',
    'trailing dot.',
    ' leading space.txt',
    "[draft] #100% & dollar\$ 'quote'.txt",
];
foreach ($names as $name) {
    $path = $root . '\\' . $name;
    echo 'NAME PROBE ' . json_encode(['name' => $name, 'bytes' => strlen($name), 'write' => file_put_contents($path, $name), 'realpath' => realpath($path)], JSON_UNESCAPED_UNICODE) . "\n";
}
echo 'DIRECTORY PROBE ' . json_encode(scandir($root), JSON_UNESCAPED_UNICODE) . "\n";
