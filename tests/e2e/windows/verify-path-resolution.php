<?php
/** Checks Windows selections through the real HTTP endpoint, without WordPress or FFI. */
if (PHP_OS !== 'WINNT' || extension_loaded('FFI')) {
    throw new RuntimeException('Path-resolution checks require Windows PHP without FFI.');
}

$repository = dirname(__DIR__, 3);
$directory = sys_get_temp_dir() . '/reprint-path-resolution-' . bin2hex(random_bytes(6));
mkdir($directory);
$router = $directory . '/router.php';
file_put_contents($directory . '/source-directory.txt', 'D:/Reprint namespace cases');
// The source process supplies this directory. The requesting process stays in
// the checkout, so using the client's current directory would fail these cases.
file_put_contents($router, '<?php require ' . var_export($repository . '/packages/reprint-server/src/class-http-server.php', true)
    . '; file_put_contents(__DIR__ . "/requests.jsonl", json_encode($_POST) . chr(10), FILE_APPEND)'
    . '; chdir(file_get_contents(__DIR__ . "/source-directory.txt")); \\WordPress\\Reprint\\Server\\HTTPServer::serve();');
$listener = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($listener, false);
fclose($listener);
$process = proc_open([PHP_BINARY, '-d', 'max_input_vars=1000', '-S', $address, $router], [
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
    $valid_cases = array_values(array_filter($cases, static function ($case) { return $case[1] !== null; }));
    $valid_inputs = array_column($valid_cases, 0);
    $expected_paths = array_column($valid_cases, 1);
    $batches = [[json_encode(array_map('base64_encode', $valid_inputs)), $expected_paths, null]];
    foreach ($cases as $case) {
        if ($case[1] === null) {
            // A late invalid path must reject the entire batch, not return the
            // successful paths before it or try another request for that path.
            $batches[] = [json_encode(array_map('base64_encode', array_merge($valid_inputs, [$case[0]]))), null, $case[2]];
        }
    }
    foreach ([null, 'not json', 'null', '[]', '{}', '{"0":"RA=="}', '{"name":"RA=="}', '["RA==",', 42] as $invalid) {
        $batches[] = [$invalid, null, 'source_paths_b64 must be a non-empty JSON list'];
    }
    foreach (['!', '', base64_encode("D:/bad\0name"), null, 42, []] as $invalid) {
        $batches[] = [json_encode([base64_encode($drive_directory), $invalid]), null, 'source_paths_b64 entry 1 must encode'];
    }
    foreach ($batches as [$input, $expected, $error]) {
        $request = curl_init('http://' . $address . '/');
        curl_setopt_array($request, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'endpoint' => 'resolve_windows_paths',
                'source_paths_b64' => $input,
            ]),
        ]);
        $body = curl_exec($request);
        $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        $response = json_decode((string) $body, true);
        if ($expected === null) {
            // The standalone exporter reports handler exceptions as HTTP 500.
            // The WordPress plugin maps invalid arguments to HTTP 400 instead.
            if ($status !== 500 || strpos((string) $body, $error) === false || isset($response['paths_b64'])) {
                throw new RuntimeException('Expected whole-batch rejection for ' . var_export($input, true) . '; got HTTP ' . $status . ': ' . $body);
            }
        } else {
            $paths = isset($response['paths_b64']) ? array_map('base64_decode', $response['paths_b64']) : null;
            if ($status !== 200 || empty($response['ok']) || $paths !== $expected) {
                throw new RuntimeException('Expected all resolved paths in request order; got HTTP ' . $status . ': ' . $body);
            }
        }
    }
    require $repository . '/packages/reprint-client/src/import.php';
    $preflight = [
        'path_format' => 'windows',
        'capabilities' => ['windows_path_resolution' => true],
        'database' => ['wp' => ['paths_urls' => ['content_dir' => $drive_directory]]],
    ];
    $many_paths = [];
    for ($index = 0; $index < 1205; ++$index) {
        $many_paths[] = 'Mixed Case/missing/file-' . $index;
    }
    $client_cases = [
        'remaps and selections' => [
            'options' => [
                'remap' => [['D:Mixed Case', ':fs-root:/site'], ['D:/Reprint link cases/junction', ':fs-root:/linked']],
                'include' => ['D:Mixed Case', ':wp-content:/hello.txt'],
                'exclude' => [':wp-content:/missing/Child', 'Mixed Case/missing/Other'],
            ],
            'inputs' => ['D:Mixed Case', 'D:/Reprint link cases/junction', $drive_directory . '/hello.txt', $drive_directory . '/missing/Child', 'Mixed Case/missing/Other'],
            'remap' => [$drive_directory => '/site', 'D:/Reprint link cases/junction' => '/linked'],
            'include' => [$drive_directory],
            'exclude' => [$drive_directory . '/missing/Child', $drive_directory . '/missing/Other'],
        ],
        'remap alone' => ['options' => ['remap' => [['D:Mixed Case', ':fs-root:/site']]], 'inputs' => ['D:Mixed Case'], 'remap' => [$drive_directory => '/site']],
        'include string' => ['options' => ['include' => 'D:Mixed Case'], 'inputs' => ['D:Mixed Case'], 'include' => [$drive_directory]],
        'only alias' => ['options' => ['only' => ['D:Mixed Case', 'D:Mixed Case']], 'inputs' => ['D:Mixed Case'], 'include' => [$drive_directory]],
        'exclude string' => ['options' => ['exclude' => 'D:Mixed Case'], 'inputs' => ['D:Mixed Case'], 'exclude' => [$drive_directory]],
        'essential filter' => ['options' => ['include' => 'D:Mixed Case'], 'filter' => 'essential-files', 'inputs' => ['D:Mixed Case', $drive_directory . '/uploads'], 'include' => [$drive_directory], 'exclude' => [$drive_directory . '/uploads']],
        'skipped filter' => ['options' => ['exclude' => 'Mixed Case/missing'], 'filter' => 'skipped-earlier', 'inputs' => [$drive_directory . '/uploads', 'Mixed Case/missing'], 'include' => [$drive_directory . '/uploads'], 'exclude' => [$drive_directory . '/missing']],
        'more than max_input_vars' => ['options' => ['include' => $many_paths], 'inputs' => $many_paths, 'include' => array_map(static function ($path) { return 'D:/Reprint namespace cases/' . $path; }, $many_paths)],
        'no paths' => ['options' => [], 'inputs' => []],
        'older Windows source' => ['options' => ['include' => ':wp-content:'], 'capability' => false, 'inputs' => [], 'include' => [$drive_directory]],
        'Unix backslashes' => ['options' => ['include' => '/site/workspace\\group\\user/www'], 'path_format' => 'unix', 'inputs' => [], 'include' => ['/site/workspace\\group\\user/www']],
        'empty include' => ['options' => ['include' => ''], 'inputs' => [], 'error' => '--include source cannot be empty'],
        'unknown token' => ['options' => ['include' => ':abspath:'], 'inputs' => [], 'error' => 'not available in preflight'],
        'one rejected path' => [
            'options' => ['remap' => [['D:Mixed Case', ':fs-root:/site']], 'include' => ['D:Mixed Case'], 'exclude' => ['C:Mixed Case']],
            'inputs' => ['D:Mixed Case', 'C:Mixed Case'],
            'error' => 'relative to another Windows drive',
        ],
    ];
    foreach ($client_cases as $name => $case) {
        $client = new ImportClient('http://' . $address . '/', $directory . '/state', $directory . '/files');
        // This source-resolution test needs the slash-delimited destination format
        // used by the remap check. Windows realpath() returns backslashes.
        $client->filesystem_root = str_replace('\\', '/', $client->filesystem_root);
        $data = $preflight;
        $data['path_format'] = $case['path_format'] ?? 'windows';
        if ($data['path_format'] === 'unix') {
            $data['database']['wp']['paths_urls']['content_dir'] = '/site/wp-content';
        }
        $data['capabilities']['windows_path_resolution'] = $case['capability'] ?? true;
        $client->get_state()->set_preflight_record(['data' => $data]);
        $reflection = new ReflectionClass($client);
        $reflection->getProperty('filter')->setValue($client, $case['filter'] ?? 'none');
        file_put_contents($directory . '/requests.jsonl', '');
        $failure = null;
        try {
            $client->prepare_files_pull_options($case['options'], false);
        } catch (Exception $exception) {
            $failure = $exception->getMessage();
        }
        if (isset($case['error']) ? $failure === null || strpos($failure, $case['error']) === false : $failure !== null) {
            throw new RuntimeException($name . ': unexpected resolution error: ' . var_export($failure, true));
        }
        $requests = file($directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES);
        $expected_count = $case['inputs'] === [] ? 0 : 1;
        if (count($requests) !== $expected_count) {
            throw new RuntimeException($name . ': expected ' . $expected_count . ' request for all remap, include, and exclude paths; got ' . count($requests) . '.');
        }
        if ($expected_count === 1) {
            $request = json_decode($requests[0], true);
            $inputs = array_map('base64_decode', json_decode($request['source_paths_b64'], true));
            if ($request['endpoint'] !== 'resolve_windows_paths' || $inputs !== $case['inputs']) {
                throw new RuntimeException($name . ': the request must contain only the unique source inputs, with tokens expanded.');
            }
        }
        $expected_remap = [];
        foreach ($case['remap'] ?? [] as $source => $target) {
            $expected_remap[$source] = $client->filesystem_root . $target;
        }
        foreach ([
            'resolved_path_mappings' => $expected_remap,
            'pull_only_files_with_path_prefixes' => $case['include'] ?? [],
            'pull_excluded_files_with_path_prefixes' => $case['exclude'] ?? [],
        ] as $property => $expected) {
            $actual = $reflection->getProperty($property)->getValue($client);
            if ($actual !== $expected) {
                throw new RuntimeException($name . ': incorrect ' . $property . ': ' . var_export($actual, true));
            }
        }
    }
    // Every case below starts another PHP process. Only state.json can carry
    // resolutions from one process to the next; no in-memory cache can pass.
    $cache_options = $client_cases['remaps and selections']['options'];
    $more_options = $cache_options;
    $more_options['include'][] = 'Mixed Case/missing/Added';
    $invalid_options = $more_options;
    $invalid_options['exclude'][] = 'C:Mixed Case';
    $changed_target = $cache_options;
    $changed_target['remap'][0][1] = ':fs-root:/different-target';
    $changed_tokens = $preflight;
    $changed_tokens['database']['wp']['paths_urls']['content_dir'] = 'D:/Reprint namespace cases/Other content';
    $unix_preflight = ['path_format' => 'unix'];
    $cache_cases = [
        'first pull' => ['requests' => 1],
        'another process' => ['requests' => 0],
        'fresh preflight' => ['requests' => 0, 'timestamp' => 2],
        'command reset' => ['requests' => 0, 'reset' => true],
        'local target changed' => ['requests' => 0, 'options' => $changed_target],
        'selection changed' => ['requests' => 1, 'options' => $more_options],
        'changed selection reused' => ['requests' => 0, 'options' => $more_options],
        'rejected replacement' => ['requests' => 1, 'options' => $invalid_options, 'error' => 'relative to another Windows drive'],
        'previous batch after rejection' => ['requests' => 0, 'options' => $more_options],
        'token value changed' => ['requests' => 1, 'options' => $more_options, 'preflight' => $changed_tokens],
        'new token value reused' => ['requests' => 0, 'options' => $more_options, 'preflight' => $changed_tokens],
        'Unix source ignores Windows results' => ['requests' => 0, 'options' => ['include' => 'D:Mixed Case'], 'preflight' => $unix_preflight, 'error' => 'must be an absolute path'],
        'empty selection' => ['requests' => 0, 'options' => []],
        'Windows results retained' => ['requests' => 0, 'options' => $more_options, 'preflight' => $changed_tokens],
        'source working directory changed' => ['requests' => 0, 'options' => $more_options, 'preflight' => $changed_tokens, 'source_directory' => 'D:/Reprint link cases'],
    ];
    $state_file = $directory . '/cache-state/remotes/' . md5('http://' . $address . '/') . '/pull/state.json';
    $previous_state = null;
    foreach ($cache_cases as $name => $case) {
        if (isset($case['source_directory'])) {
            file_put_contents($directory . '/source-directory.txt', $case['source_directory']);
        }
        $options = $case['options'] ?? $cache_options;
        $data = $case['preflight'] ?? $preflight;
        $input = [
            'url' => 'http://' . $address . '/',
            'state_dir' => $directory . '/cache-state',
            'files_dir' => $directory . '/cache-files',
            'options' => $options,
            'preflight' => $data,
            'timestamp' => $case['timestamp'] ?? 1,
            'reset' => $case['reset'] ?? false,
        ];
        file_put_contents($directory . '/client-input.json', json_encode($input, JSON_THROW_ON_ERROR));
        file_put_contents($directory . '/requests.jsonl', '');
        $worker = proc_open([PHP_BINARY, __DIR__ . '/path-cache-client.php', $directory . '/client-input.json'], [
            0 => ['pipe', 'r'], 1 => ['file', $directory . '/client-result.json', 'w'], 2 => ['file', $directory . '/client-errors.log', 'w'],
        ], $pipes);
        fclose($pipes[0]);
        if (proc_close($worker) !== 0) {
            throw new RuntimeException($name . ': client failed: ' . file_get_contents($directory . '/client-errors.log'));
        }
        $result = json_decode(file_get_contents($directory . '/client-result.json'), true, 512, JSON_THROW_ON_ERROR);
        $requests = file($directory . '/requests.jsonl', FILE_IGNORE_NEW_LINES);
        if (count($requests) !== $case['requests']) {
            throw new RuntimeException($name . ': expected ' . $case['requests'] . ' resolver requests after loading pull state; got ' . count($requests) . '.');
        }
        if (isset($case['error'])) {
            if (strpos($result['error'] ?? '', $case['error']) === false || file_get_contents($state_file) !== $previous_state) {
                throw new RuntimeException($name . ': the rejected batch must leave saved state unchanged.');
            }
        } else {
            if ($result['error'] !== null) {
                throw new RuntimeException($name . ': ' . $result['error']);
            }
            if ($options !== []) {
                $content = $data['database']['wp']['paths_urls']['content_dir'];
                $expected_include = $content === $drive_directory ? [$drive_directory] : [$drive_directory, $content . '/hello.txt'];
                $target = str_replace('\\', '/', realpath($directory . '/cache-files')) . substr($options['remap'][0][1], strlen(':fs-root:'));
                if ($result['include'] !== $expected_include || ($result['remap'][$drive_directory] ?? null) !== $target
                    || $result['exclude'] !== [$content . '/missing/Child', $drive_directory . '/missing/Other']) {
                    throw new RuntimeException($name . ': saved results changed the remap or selected paths: ' . json_encode($result));
                }
            }
        }
        $previous_state = file_get_contents($state_file);
    }
    echo 'PASS: ' . count($cache_cases) . " pull-state cache cases across separate client processes.\n";
    echo 'PASS: ' . count($cases) . ' Windows path cases, ' . count($batches) . ' endpoint batches, and ' . count($client_cases) . " client request-count cases.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
