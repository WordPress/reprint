<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies that requiring export.php does not authenticate requests
 * or terminate the process — it prepares endpoint functions without dispatching.
 *
 * Tests run in subprocesses because export.php registers shutdown and
 * error handlers at module level.
 */
final class ExportLibraryLoadTest extends TestCase {
    private const EXPORT_PATH = __DIR__ . '/../packages/reprint-server/src/export.php';

    public function testRequiringExportPhpDoesNotRejectMissingSecretKey(): void
    {
        $script = <<<'PHP'
        $_GET['endpoint'] = 'preflight';
        PHP;

        $result = $this->runExportWith($script);

        $this->assertStringNotContainsString('Invalid secret key', $result['output']);
        $this->assertStringContainsString('endpoint-handlers-loaded', $result['output']);
    }

    public function testRequiringExportPhpDefinesEndpointHandlers(): void
    {
        $result = $this->runExportWith('');

        $this->assertStringContainsString('endpoint-handlers-loaded', $result['output']);
    }

    public function testRequiringExportPhpKeepsClassesLoadedByAnotherPackageCopy(): void
    {
        $export_path = realpath(self::EXPORT_PATH);
        $this->assertNotFalse($export_path, 'export.php must exist');

        $tmp_dir = sys_get_temp_dir() . '/reprint-server-class-loading-test-' . uniqid('', true);
        mkdir($tmp_dir, 0755, true);
        $first_copy_paths = [];
        foreach (['class-resource-budget.php', 'class-gzip-output-stream.php'] as $filename) {
            $source_path = realpath(__DIR__ . '/../packages/reprint-server/src/' . $filename);
            $this->assertNotFalse($source_path, "{$filename} must exist");
            $first_copy_paths[] = $tmp_dir . '/' . $filename;
            copy($source_path, $tmp_dir . '/' . $filename);
        }

        try {
            $result = $this->runPhpCode(
                "<?php\nrequire base64_decode('" . base64_encode($first_copy_paths[0]) . "', true);\n"
                . "require base64_decode('" . base64_encode($first_copy_paths[1]) . "', true);\n"
                . "require base64_decode('" . base64_encode($export_path) . "', true);\n"
                . "echo 'endpoint-handlers-loaded';\n"
            );

            $this->assertSame(0, $result['status'], $result['output']);
            $this->assertSame('endpoint-handlers-loaded', trim($result['output']));
        } finally {
            foreach ($first_copy_paths as $first_copy_path) {
                unlink($first_copy_path);
            }
            rmdir($tmp_dir);
        }
    }

    public function testServerClassesRelyOnComposerInsteadOfManualClassFileLoads(): void
    {
        $source_directory = __DIR__ . '/../packages/reprint-server/src';
        $manual_class_loads = [];

        foreach (glob($source_directory . '/*.php') ?: [] as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source, basename($path) . ' must be readable');
            $tokens = token_get_all($source);
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE], true)) {
                    continue;
                }

                $statement = '';
                for ($cursor = $index + 1; isset($tokens[$cursor]); ++$cursor) {
                    $statement_token = $tokens[$cursor];
                    $statement .= is_array($statement_token) ? $statement_token[1] : $statement_token;
                    if ($statement_token === ';') {
                        break;
                    }
                }
                if (strpos($statement, 'class-') !== false) {
                    $manual_class_loads[] = basename($path) . ':' . $token[2];
                }
            }
        }

        $this->assertSame(
            [],
            $manual_class_loads,
            'Server classes must resolve through Composer: ' . implode(', ', $manual_class_loads)
        );
    }

    public function testNormalizePathListKeepsTheFilesystemRoot(): void
    {
        $export_path = realpath(self::EXPORT_PATH);
        $this->assertNotFalse($export_path, 'export.php must exist');

        $result = $this->runPhpCode(
            "<?php\nrequire " . var_export($export_path, true) . ";\n"
            . "echo json_encode(normalize_path_list(['/']), JSON_UNESCAPED_SLASHES);\n"
        );

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame('["/"]', trim($result['output']));
    }

    public function testPreflightKeepsFilesystemRootInContentInventory(): void
    {
        $autoload_path = realpath(__DIR__ . '/../vendor/autoload.php');
        $this->assertNotFalse($autoload_path, 'Composer autoloader must exist');
        $export_path = realpath(self::EXPORT_PATH);
        $this->assertNotFalse($export_path, 'export.php must exist');

        $result = $this->runPhpCode(
            "<?php\nrequire " . var_export($autoload_path, true) . ";\n"
            . "require " . var_export($export_path, true) . ";\n"
            . "ob_start();\n"
            . "\$preflight = endpoint_preflight(['directory' => '/']);\n"
            . "ob_end_clean();\n"
            . "echo json_encode(\$preflight['stats']['wp_content']['roots'], JSON_UNESCAPED_SLASHES);\n"
        );

        $this->assertSame(0, $result['status'], $result['output']);
        $roots = json_decode(trim($result['output']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/', $roots[0]['root']);
    }

    public function testPluginRuntimeLoaderSkipsAutoloadAlreadyLoadedThroughSymlinkedPluginDirectory(): void
    {
        $tmp_dir = sys_get_temp_dir() . '/reprint-server-runtime-loader-test-' . uniqid('', true);
        $physical_plugin_directory = $tmp_dir . '/physical-plugin';
        $linked_plugin_directory = $tmp_dir . '/linked-plugin';
        $autoload_path = $physical_plugin_directory . '/vendor/autoload.php';
        $linked_autoload_path = $linked_plugin_directory . '/vendor/autoload.php';
        $compatibility_path = $physical_plugin_directory . '/vendor/wp-php-toolkit/reprint-server/src/compat.php';
        $export_path = $physical_plugin_directory . '/vendor/wp-php-toolkit/reprint-server/src/export.php';
        mkdir(dirname($export_path), 0755, true);
        file_put_contents($autoload_path, "<?php\n");
        file_put_contents($compatibility_path, "<?php\n");
        file_put_contents($export_path, "<?php\n");

        if (!symlink($physical_plugin_directory, $linked_plugin_directory)) {
            $this->removePath($tmp_dir);
            $this->markTestSkipped('Could not create a plugin directory symlink.');
        }

        $canonical_export_path = realpath($export_path);
        $this->assertNotFalse($canonical_export_path, 'export.php must exist');
        $canonical_autoload_path = realpath($autoload_path);
        $this->assertNotFalse($canonical_autoload_path, 'autoload.php must exist');
        $lib_path = realpath(__DIR__ . '/../reprint-server-wp/lib.php');
        $this->assertNotFalse($lib_path, 'lib.php must exist');
        $linked_autoload_path_encoded = base64_encode($linked_autoload_path);
        $linked_plugin_directory_encoded = base64_encode($linked_plugin_directory . '/');
        $lib_path_encoded = base64_encode($lib_path);

        try {
            $php_code = '<?php' . "\n"
                . 'require_once base64_decode(\'' . $linked_autoload_path_encoded . '\', true);' . "\n"
                . 'define(\'ABSPATH\', __DIR__ . \'/\');' . "\n"
                . 'define(\'WordPress\\Reprint\\Server\\Plugin\\PLUGIN_DIR\', base64_decode(\'' . $linked_plugin_directory_encoded . '\', true));' . "\n"
                . 'require base64_decode(\'' . $lib_path_encoded . '\', true);' . "\n"
                . 'echo \\WordPress\\Reprint\\Server\\Plugin\\load_server_runtime();' . "\n";

            $result = $this->runPhpCode($php_code);

            $this->assertSame(0, $result['status'], $result['output']);
            $this->assertSame($canonical_export_path, trim($result['output']));
        } finally {
            $this->removePath($tmp_dir);
        }
    }

    public function testDirectPluginLibraryLoadKeepsCanonicalAndReleasedSymbols(): void
    {
        $lib_path = realpath(__DIR__ . '/../reprint-server-wp/lib.php');
        $this->assertNotFalse($lib_path, 'lib.php must exist');
        $plugin_directory = dirname($lib_path) . '/';
        $php_code = '<?php' . "\n"
            . 'function plugin_dir_path(string $file): string {' . "\n"
            . '    return base64_decode(\'' . base64_encode($plugin_directory) . '\', true);' . "\n"
            . '}' . "\n"
            . 'define(\'ABSPATH\', __DIR__ . \'/\');' . "\n"
            . '$_GET[\'site-export-api\'] = true;' . "\n"
            . 'require base64_decode(\'' . base64_encode($lib_path) . '\', true);' . "\n"
            . 'echo json_encode([' . "\n"
            . '    \'canonical_function\' => function_exists(\'WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\handle_api_request\'),' . "\n"
            . '    \'released_function\' => function_exists(\'_site_export_handle_api_request\'),' . "\n"
            . '    \'canonical_option\' => constant(\'WordPress\\\\Reprint\\\\Server\\\\Plugin\\\\CONNECTION_TOKEN_OPTION\'),' . "\n"
            . '    \'released_option\' => constant(\'SITE_EXPORT_SECRET_OPTION\'),' . "\n"
            . '    \'canonical_query_added\' => isset($_GET[\'reprint-api\']),' . "\n"
            . '], JSON_THROW_ON_ERROR);' . "\n";

        $result = $this->runPhpCode($php_code);

        $this->assertSame(0, $result['status'], $result['output']);
        $symbols = json_decode(trim($result['output']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($symbols['canonical_function']);
        $this->assertTrue($symbols['released_function']);
        $this->assertSame('reprint_server_connection_token', $symbols['canonical_option']);
        $this->assertSame($symbols['canonical_option'], $symbols['released_option']);
        $this->assertFalse($symbols['canonical_query_added']);
    }

    public function testDirectPluginLibraryLoadMigratesLegacyStoredOptions(): void
    {
        $lib_path = realpath(__DIR__ . '/../reprint-server-wp/lib.php');
        $this->assertNotFalse($lib_path, 'lib.php must exist');
        $plugin_directory_encoded = base64_encode(dirname($lib_path) . '/');
        $lib_path_encoded = base64_encode($lib_path);

        // WordPress hook functions are defined so compat.php registers its
        // reprint_server_library_loaded listener, which calls the bootstrap a
        // second time with the canonical constants already in place.
        $php_code = <<<PHP
        <?php
        function plugin_dir_path(string \$file): string {
            return base64_decode('{$plugin_directory_encoded}', true);
        }
        define('ABSPATH', __DIR__ . '/');
        \$GLOBALS['hook_callbacks'] = [];
        \$GLOBALS['stored_options'] = [
            'site_export_secret' => 'legacy-token',
            'site_export_push_authorized_token_fingerprint' => 'legacy-fingerprint',
        ];
        function add_filter(string \$hook_name, \$callback, int \$priority = 10, int \$accepted_args = 1): void {
            \$GLOBALS['hook_callbacks'][\$hook_name][] = \$callback;
        }
        function add_action(string \$hook_name, \$callback, int \$priority = 10, int \$accepted_args = 1): void {
            add_filter(\$hook_name, \$callback, \$priority, \$accepted_args);
        }
        function apply_filters(string \$hook_name, \$value) {
            foreach (\$GLOBALS['hook_callbacks'][\$hook_name] ?? [] as \$callback) {
                \$value = \$callback(\$value);
            }
            return \$value;
        }
        function do_action(string \$hook_name): void {
            foreach (\$GLOBALS['hook_callbacks'][\$hook_name] ?? [] as \$callback) {
                \$callback();
            }
        }
        function get_option(string \$name, \$default = false) {
            return array_key_exists(\$name, \$GLOBALS['stored_options'])
                ? \$GLOBALS['stored_options'][\$name]
                : \$default;
        }
        function update_option(string \$name, \$value, \$autoload = null): bool {
            \$GLOBALS['stored_options'][\$name] = \$value;
            return true;
        }
        function delete_option(string \$name): bool {
            unset(\$GLOBALS['stored_options'][\$name]);
            return true;
        }
        require base64_decode('{$lib_path_encoded}', true);
        echo json_encode(\$GLOBALS['stored_options'], JSON_THROW_ON_ERROR);
        PHP;

        $result = $this->runPhpCode($php_code);

        $this->assertSame(0, $result['status'], $result['output']);
        $stored_options = json_decode(trim($result['output']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                'reprint_server_connection_token' => 'legacy-token',
                'reprint_server_push_authorized_token_fingerprint' => 'legacy-fingerprint',
            ],
            $stored_options
        );
    }

    /**
     * @return array {
     *     Export runner result.
     *
     *     @type string $output Captured output.
     * }
     * @phpstan-return array{output:string}
     */
    private function runExportWith(string $setup_script): array
    {
        $export_path = realpath(self::EXPORT_PATH);
        $this->assertNotFalse($export_path, 'export.php must exist');

        $php_code = <<<PHP
        <?php
        {$setup_script}
        require '{$export_path}';
        // If we got here, require() completed without die()ing.
        // Print a marker indicating endpoint functions are defined.
        echo function_exists('endpoint_preflight') ? 'endpoint-handlers-loaded' : 'handlers-missing';
        PHP;

        return ['output' => $this->runPhpCode($php_code)['output']];
    }

    /**
     * @return array {
     *     PHP runner result.
     *
     *     @type string $output Captured stdout and stderr.
     *     @type int    $status Process exit status.
     * }
     * @phpstan-return array{output:string,status:int}
     */
    private function runPhpCode(string $php_code): array
    {
        $tmp_dir = sys_get_temp_dir() . '/export-library-test-' . uniqid();
        mkdir($tmp_dir, 0755, true);

        try {
            file_put_contents($tmp_dir . '/run.php', $php_code);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(
                [PHP_BINARY, $tmp_dir . '/run.php'],
                $descriptors,
                $pipes,
                $tmp_dir
            );

            if (!is_resource($process)) {
                $this->fail('Failed to spawn PHP subprocess');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            return [
                'output' => ( $stdout ?: '' ) . ( $stderr ?: '' ),
                'status' => $status,
            ];
        } finally {
            array_map('unlink', glob($tmp_dir . '/*') ?: []);
            rmdir($tmp_dir);
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removePath($path . '/' . $entry);
        }

        rmdir($path);
    }
}
