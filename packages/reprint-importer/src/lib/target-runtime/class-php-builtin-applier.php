<?php
/**
 * Runtime applier for PHP's built-in development server.
 *
 * Writes:
 * 1. {output_dir}/runtime.php — constants, server vars, route handlers,
 *                                and CLI-server routing logic
 * 2. {output_dir}/start.sh    — shell script with the exact php -S command
 *
 * runtime.php is the router script passed to php -S. It defines constants,
 * runs route handlers, then handles request routing. PHP files are served
 * via require (not return false) so they execute in the same scope where
 * constants are already defined — no auto_prepend_file needed.
 *
 * The developer just runs: bash {output_dir}/start.sh
 */
class PhpBuiltinApplier implements RuntimeApplier
{
    public function apply(RuntimeManifest $manifest, string $filesystem_root, string $output_dir, array $options = []): array
    {
        $host = $options['host'] ?? 'localhost';
        $port = (int) ($options['port'] ?? 8881);

        $summary = [];

        // 1. Write runtime.php (base layers + CLI-server routing)
        $runtime_path = $output_dir . '/runtime.php';
        $runtime = generate_runtime_php($manifest, $filesystem_root);
        $runtime .= $this->generate_cli_server_routing($options);
        write_runtime_file($runtime_path, $runtime);
        $summary[] = "Wrote {$runtime_path}";

        // 2. Write start.sh
        $start_path = $output_dir . '/start.sh';
        $start_script = $this->generate_start_script($manifest, $filesystem_root, $runtime_path, $host, $port);
        write_runtime_file($start_path, $start_script);
        chmod($start_path, 0755);
        $summary[] = "Wrote {$start_path}";

        $summary[] = '';
        $summary[] = 'Start the server:';
        $summary[] = "  bash {$start_path}";
        $summary[] = '';
        $summary[] = "Then open http://{$host}:{$port} in your browser.";

        return $summary;
    }

    /**
     * Generate the CLI-server routing block that handles static files,
     * PHP file execution with PATH_INFO support, and WordPress
     * pretty-permalink fallback.
     *
     * This code only runs under php -S (guarded by php_sapi_name check).
     * It's appended to runtime.php after the base layers.
     */
    private function generate_cli_server_routing(array $options): string
    {
        $wordpress_index_php = $options['wordpress_index_php'] ?? '';
        $wp_core_dir = '';
        if ($wordpress_index_php !== '') {
            $real_wp_index = realpath($wordpress_index_php);
            $wp_core_dir = dirname($real_wp_index !== false ? $real_wp_index : $wordpress_index_php);
        }
        $escaped_wp_index = addslashes($wordpress_index_php);
        $escaped_wp_core_dir = addslashes($wp_core_dir);

        $lines = [];
        $lines[] = '// CLI-server routing (php -S only).';
        $lines[] = '// Handles static files, PHP execution, and WordPress pretty permalinks.';
        $lines[] = '$path = parse_url($_SERVER[\'REQUEST_URI\'] ?? \'/\', PHP_URL_PATH);';
        $lines[] = 'if (!is_string($path) || $path === \'\') {';
        $lines[] = '    $path = \'/\';';
        $lines[] = '}';
        $lines[] = '$decoded_path = rawurldecode($path);';
        $lines[] = 'if (strpos($decoded_path, "\0") !== false || preg_match(\'#(?:^|/)\\.\\.(?:/|$)#\', $decoded_path)) {';
        $lines[] = '    return false;';
        $lines[] = '}';
        $lines[] = '$document_root = rtrim($_SERVER[\'DOCUMENT_ROOT\'] ?? \'\', \'/\');';
        $lines[] = "\$wordpress_core_dir = '{$escaped_wp_core_dir}';";
        $lines[] = '$request_roots = [];';
        $lines[] = 'if ($document_root !== \'\') {';
        $lines[] = '    $request_roots[] = $document_root;';
        $lines[] = '}';
        $lines[] = 'if ($wordpress_core_dir !== \'\') {';
        $lines[] = '    $real_document_root = $document_root !== \'\' ? (realpath($document_root) ?: $document_root) : \'\';';
        $lines[] = '    if ($real_document_root !== $wordpress_core_dir && !in_array($wordpress_core_dir, $request_roots, true)) {';
        $lines[] = '        $request_roots[] = $wordpress_core_dir;';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '// Support PATH_INFO URLs like /file.php/extra/path.';
        $lines[] = '// Walk the path segments to find the first .php file that exists,';
        $lines[] = '// then split into SCRIPT_NAME and PATH_INFO. Document-root files';
        $lines[] = '// win over core files so wp-content remains site-specific.';
        $lines[] = '$script_file = null;';
        $lines[] = '$path_info = \'\';';
        $lines[] = 'if (preg_match(\'#\\.php(?=/|$)#\', $path)) {';
        $lines[] = '    $segments = explode(\'/\', $path);';
        $lines[] = '    $check = \'\';';
        $lines[] = '    foreach ($segments as $i => $seg) {';
        $lines[] = '        $check .= ($i > 0 ? \'/\' : \'\') . $seg;';
        $lines[] = '        if (!preg_match(\'/\\.php$/\', $check)) {';
        $lines[] = '            continue;';
        $lines[] = '        }';
        $lines[] = '        foreach ($request_roots as $root) {';
        $lines[] = '            $candidate = $root . $check;';
        $lines[] = '            if (is_file($candidate)) {';
        $lines[] = '                $script_file = $candidate;';
        $lines[] = '                $path_info = substr($path, strlen($check));';
        $lines[] = '                $_SERVER[\'SCRIPT_NAME\'] = $check;';
        $lines[] = '                $_SERVER[\'SCRIPT_FILENAME\'] = $candidate;';
        $lines[] = '                $_SERVER[\'PHP_SELF\'] = $check . ($path_info ?: \'\');';
        $lines[] = '                if ($path_info !== \'\') {';
        $lines[] = '                    $_SERVER[\'PATH_INFO\'] = $path_info;';
        $lines[] = '                } else {';
        $lines[] = '                    unset($_SERVER[\'PATH_INFO\']);';
        $lines[] = '                }';
        $lines[] = '                break 2;';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'if ($script_file) {';
        $lines[] = '    require $script_file;';
        $lines[] = '    return;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '$static_file = null;';
        $lines[] = '$file = $document_root . $path;';
        $lines[] = '';
        $lines[] = '// Existing non-PHP files: let php -S serve them as static.';
        $lines[] = 'if ($path !== \'/\' && file_exists($file) && is_file($file)) {';
        $lines[] = '    return false;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '// Core static files may live outside php -S\'s document root';
        $lines[] = '// on hosts where ABSPATH and DOCUMENT_ROOT are split.';
        $lines[] = 'if ($path !== \'/\') {';
        $lines[] = '    foreach ($request_roots as $root) {';
        $lines[] = '        if ($root === $document_root) {';
        $lines[] = '            continue;';
        $lines[] = '        }';
        $lines[] = '        $candidate = $root . $path;';
        $lines[] = '        if (is_file($candidate)) {';
        $lines[] = '            $static_file = $candidate;';
        $lines[] = '            break;';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '// Directory requests: look for index.php or index.html.';
        $lines[] = '$path_without_trailing_slash = $path === \'/\' ? \'\' : rtrim($path, \'/\');';
        $lines[] = 'foreach ($request_roots as $root) {';
        $lines[] = '    $dir = $root . $path_without_trailing_slash;';
        $lines[] = '    if (!is_dir($dir)) {';
        $lines[] = '        continue;';
        $lines[] = '    }';
        $lines[] = '    if (file_exists($dir . \'/index.php\')) {';
        $lines[] = '        $_SERVER[\'SCRIPT_NAME\'] = ($path_without_trailing_slash === \'\' ? \'\' : $path_without_trailing_slash) . \'/index.php\';';
        $lines[] = '        $_SERVER[\'SCRIPT_FILENAME\'] = $dir . \'/index.php\';';
        $lines[] = '        $_SERVER[\'PHP_SELF\'] = $_SERVER[\'SCRIPT_NAME\'];';
        $lines[] = '        unset($_SERVER[\'PATH_INFO\']);';
        $lines[] = '        require $dir . \'/index.php\';';
        $lines[] = '        return;';
        $lines[] = '    }';
        $lines[] = '    if (file_exists($dir . \'/index.html\')) {';
        $lines[] = '        if ($root === $document_root) {';
        $lines[] = '            return false;';
        $lines[] = '        }';
        $lines[] = '        $static_file = $dir . \'/index.html\';';
        $lines[] = '        break;';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'if ($static_file !== null) {';
        $lines[] = '    $mime_types = [';
        $lines[] = '        \'css\' => \'text/css; charset=UTF-8\',';
        $lines[] = '        \'js\' => \'application/javascript; charset=UTF-8\',';
        $lines[] = '        \'mjs\' => \'application/javascript; charset=UTF-8\',';
        $lines[] = '        \'json\' => \'application/json; charset=UTF-8\',';
        $lines[] = '        \'svg\' => \'image/svg+xml\',';
        $lines[] = '        \'png\' => \'image/png\',';
        $lines[] = '        \'jpg\' => \'image/jpeg\',';
        $lines[] = '        \'jpeg\' => \'image/jpeg\',';
        $lines[] = '        \'gif\' => \'image/gif\',';
        $lines[] = '        \'webp\' => \'image/webp\',';
        $lines[] = '        \'ico\' => \'image/x-icon\',';
        $lines[] = '        \'woff\' => \'font/woff\',';
        $lines[] = '        \'woff2\' => \'font/woff2\',';
        $lines[] = '        \'ttf\' => \'font/ttf\',';
        $lines[] = '        \'eot\' => \'application/vnd.ms-fontobject\',';
        $lines[] = '        \'map\' => \'application/json; charset=UTF-8\',';
        $lines[] = '    ];';
        $lines[] = '    $extension = strtolower(pathinfo($static_file, PATHINFO_EXTENSION));';
        $lines[] = '    if (isset($mime_types[$extension])) {';
        $lines[] = '        header(\'Content-Type: \' . $mime_types[$extension]);';
        $lines[] = '    } elseif (function_exists(\'mime_content_type\')) {';
        $lines[] = '        $mime_type = mime_content_type($static_file);';
        $lines[] = '        if (is_string($mime_type) && $mime_type !== \'\') {';
        $lines[] = '            header(\'Content-Type: \' . $mime_type);';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '    header(\'Content-Length: \' . filesize($static_file));';
        $lines[] = '    readfile($static_file);';
        $lines[] = '    return;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = '// Everything else: WordPress pretty permalinks via index.php.';
        $lines[] = '// WordPress index.php may live outside the document root (e.g.';
        $lines[] = '// WPCloud where ABSPATH differs from the document root).';
        $lines[] = '$_SERVER[\'SCRIPT_NAME\'] = \'/index.php\';';
        $lines[] = "\$_SERVER['SCRIPT_FILENAME'] = '{$escaped_wp_index}';";
        $lines[] = '$_SERVER[\'PHP_SELF\'] = \'/index.php\';';
        $lines[] = 'unset($_SERVER[\'PATH_INFO\']);';
        $lines[] = "require '{$escaped_wp_index}';";
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Generate a shell script that starts the built-in server.
     * runtime.php is the router — all PHP files are require'd through it
     * so they share the same scope with constants already defined.
     */
    private function generate_start_script(
        RuntimeManifest $manifest,
        string $filesystem_root,
        string $runtime_path,
        string $host,
        int $port
    ): string {
        $lines = [];
        $lines[] = '#!/usr/bin/env bash';
        $lines[] = '# Start the PHP built-in server for the imported site.';
        $lines[] = '# Generated by apply-runtime — do not edit.';
        $lines[] = '#';
        $lines[] = '# Source host: ' . $manifest->source;
        $lines[] = '';
        $lines[] = 'set -euo pipefail';
        $lines[] = '';

        $php_args = [];
        $php_args[] = 'php';

        foreach ($manifest->php_ini as $key => $value) {
            $php_args[] = "-d {$key}={$value}";
        }

        $php_args[] = '-S ' . $host . ':' . $port;
        $php_args[] = '-t ' . escapeshellarg($filesystem_root);
        $php_args[] = escapeshellarg($runtime_path);

        $lines[] = 'echo "Starting PHP built-in server..."';
        $lines[] = 'echo "  http://' . $host . ':' . $port . '"';
        $lines[] = 'echo ""';
        $lines[] = '';
        $lines[] = implode(" \\\n    ", $php_args);
        $lines[] = '';

        return implode("\n", $lines);
    }
}
