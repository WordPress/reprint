<?php
/** Checks Windows selections through the real HTTP endpoint, without WordPress or FFI. */
if (PHP_OS !== 'WINNT' || extension_loaded('FFI')) {
    throw new RuntimeException('Path-resolution checks require Windows PHP without FFI.');
}

$repository = dirname(__DIR__, 3);
$directory = sys_get_temp_dir() . '/reprint-path-resolution-' . bin2hex(random_bytes(6));
mkdir($directory);
$router = $directory . '/router.php';
// The source process supplies this directory. The requesting process stays in
// the checkout, so using the client's current directory would fail these cases.
file_put_contents($router, '<?php require ' . var_export($repository . '/packages/reprint-server/src/class-http-server.php', true)
    . '; file_put_contents(__DIR__ . "/requests.jsonl", json_encode($_POST) . chr(10), FILE_APPEND)'
    . '; chdir("D:/Reprint namespace cases"); \\WordPress\\Reprint\\Server\\HTTPServer::serve();');
$listener = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($listener, false);
fclose($listener);
$process = proc_open([PHP_BINARY, '-S', $address, $router], [
    0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a'],
], $pipes);
fclose($pipes[0]);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        $connection = @stream_socket_client('tcp://' . $address, $code, $message, 0.1);
        if ($connection) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    if (!$ready) {
        throw new RuntimeException('The Windows endpoint did not start: ' . file_get_contents($directory . '/server.log'));
    }
    $drive_directory = 'D:/Reprint namespace cases/Mixed Case';
    $share_directory = '\\\\LOCALHOST\\D$/Reprint namespace cases/Mixed Case';
    $cases = [
        ['D:\\Reprint namespace cases\\Mixed Case', $drive_directory],
        ['d:/reprint NAMESPACE cases/mixed CASE', $drive_directory],
        ['D:\\Reprint namespace cases//Mixed Case/', $drive_directory],
        ['.\\Mixed Case', $drive_directory],
        ['D:Mixed Case', $drive_directory],
        ['\\Reprint namespace cases\\Mixed Case', $drive_directory],
        ['Mixed Case/../Mixed Case', $drive_directory],
        ['\\\\?\\D:\\Reprint namespace cases\\Mixed Case', $drive_directory],
        ['\\\\.\\D:\\Reprint namespace cases\\Mixed Case', $drive_directory],
        ['//localhost/D$/Reprint namespace cases/Mixed Case', $share_directory],
        ['\\\\?\\UNC\\localhost\\D$\\Reprint namespace cases\\Mixed Case', $share_directory],
        ['\\\\.\\UNC\\localhost\\D$\\Reprint namespace cases\\Mixed Case', $share_directory],
        ['Mixed Case/missing/Child', $drive_directory . '/missing/Child'],
        ['\\\\?\\D:\\Reprint link cases\\junction', 'D:/Reprint link cases/junction'],
        ['D:/Reprint link cases/junction/hello.txt', 'D:/Reprint link cases/junction/hello.txt'],
        ['C:Mixed Case', null, 'relative to another Windows drive'],
        ['\\\\.\\PhysicalDrive0', null, 'Windows device names cannot select migration files'],
        ['\\\\?\\D:\\Reprint namespace cases\\.\\Mixed Case', null, 'must not contain dot-segments'],
        ['D:/Reprint literal cases/folder./..', null, 'Cannot read the exact Windows filename'],
        ['D:/Reprint literal cases/Mixed Case/trailing ', null, 'Cannot read the exact Windows filename'],
    ];
    $namespace_cases = json_decode(file_get_contents($repository . '/namespace-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['volume-guid', 'global-root'] as $name) {
        $cases[] = [$namespace_cases[$name]['source'], null, 'PHP cannot map this Windows volume namespace'];
    }
    foreach ($cases as $case) {
        $request = curl_init('http://' . $address . '/');
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'endpoint' => 'resolve_windows_path',
                'source_path_b64' => base64_encode($case[0]),
            ]),
        ]);
        $body = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        if ($case[1] === null) {
            // The standalone exporter reports handler exceptions as HTTP 500.
            // The WordPress plugin maps invalid arguments to HTTP 400 instead.
            if ($status !== 500 || strpos((string) $body, $case[2]) === false) {
                throw new RuntimeException('Expected rejection for ' . $case[0] . '; got HTTP ' . $status . ': ' . $body);
            }
        } else {
            $response = json_decode((string) $body, true);
            $path = isset($response['path_b64']) ? base64_decode($response['path_b64'], true) : null;
            if ($status !== 200 || empty($response['ok']) || $path !== $case[1]) {
                throw new RuntimeException('Expected ' . $case[1] . ' for ' . $case[0] . '; got HTTP ' . $status . ': ' . $body);
            }
        }
    }
    require $repository . '/packages/reprint-client/src/import.php';
    $client = new ImportClient('http://' . $address . '/', $directory . '/state', $directory . '/files');
    // This source-resolution test needs the slash-delimited destination format
    // used by the remap check. Windows realpath() returns backslashes.
    $client->filesystem_root = str_replace('\\', '/', $client->filesystem_root);
    $client->get_state()->set_preflight_record(['data' => [
        'path_format' => 'windows',
        'capabilities' => ['windows_path_resolution' => true],
        'database' => ['wp' => ['paths_urls' => [
            'content_dir' => 'D:/Reprint namespace cases/Mixed Case',
        ]]],
    ]]);
    file_put_contents($directory . '/requests.jsonl', '');
    $client->prepare_files_pull_options([
        'remap' => [
            ['D:Mixed Case', ':fs-root:/site'],
            ['D:/Reprint link cases/junction', ':fs-root:/linked'],
        ],
        'include' => ['D:Mixed Case', ':wp-content:/hello.txt'],
        'exclude' => [':wp-content:/missing/Child', 'Mixed Case/missing/Other'],
    ], false);
    $requests = file($directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES);
    if (count($requests) !== 1) {
        throw new RuntimeException('Expected one request for all remap, include, and exclude paths; got ' . count($requests) . '.');
    }
    echo 'PASS: ' . count($cases) . " Windows selections resolved or rejected over HTTP.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
