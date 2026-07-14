<?php

use PHPUnit\Framework\TestCase;

final class WordPressStagedOptionsTest extends TestCase {

    public function testWordPressWrapperRequiresCallerConfiguredDurableStaging(): void {
        $library = realpath(__DIR__ . '/../reprint-exporter-wp/lib.php');
        $this->assertNotFalse($library);
        $root = sys_get_temp_dir() . '/wordpress-staged-options-' . bin2hex(random_bytes(8));
        mkdir($root . '/plugin', 0700, true);
        $script = <<<'PHP'
function plugin_dir_path($path) { return dirname($path) . '/'; }
define('ABSPATH', $argv[2] . '/');
define('SITE_EXPORT_PLUGIN_DIR', $argv[2] . '/plugin/');
require $argv[1];
try {
    _site_export_staged_options([]);
} catch (InvalidArgumentException $exception) {
    if (strpos($exception->getMessage(), 'staging_dir') !== false) {
        exit(0);
    }
    fwrite(STDERR, $exception->getMessage());
    exit(2);
}
fwrite(STDERR, 'The WordPress wrapper silently selected staging storage.');
exit(3);
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script, $library, $root], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @rmdir($root . '/plugin');
        @rmdir($root);

        $this->assertSame(0, $exitCode, (string) $stdout . (string) $stderr);
    }

    public function testWordPressWrapperDerivesDeploymentRootsWhenExporterLivesOutsideTheTarget(): void {
        $library = realpath(__DIR__ . '/../reprint-exporter-wp/lib.php');
        $this->assertNotFalse($library);
        $root = sys_get_temp_dir() . '/wordpress-staged-roots-' . bin2hex(random_bytes(8));
        $outsidePlugin = $root . '-exporter';
        mkdir($root . '/custom-plugins', 0700, true);
        mkdir($root . '/custom-themes', 0700, true);
        mkdir($root . '/staging', 0700, true);
        mkdir($outsidePlugin, 0700, true);
        $script = <<<'PHP'
function plugin_dir_path($path) { return dirname($path) . '/'; }
function get_theme_root() { return $GLOBALS['test_theme_root']; }
define('ABSPATH', $argv[2] . '/');
define('SITE_EXPORT_PLUGIN_DIR', $argv[3] . '/');
define('WP_PLUGIN_DIR', $argv[2] . '/custom-plugins');
$GLOBALS['test_theme_root'] = $argv[2] . '/custom-themes';
require $argv[1];
$options = _site_export_staged_options(['staging_dir' => $argv[2] . '/staging']);
sort($options['apply_deployment_roots'], SORT_STRING);
if ($options['apply_deployment_roots'] !== ['custom-plugins', 'custom-themes']) {
    fwrite(STDERR, json_encode($options['apply_deployment_roots']));
    exit(1);
}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $script, $library, $root, $outsidePlugin], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        @rmdir($root . '/custom-plugins');
        @rmdir($root . '/custom-themes');
        @rmdir($root . '/staging');
        @rmdir($root);
        @rmdir($outsidePlugin);

        $this->assertSame(0, $exitCode, (string) $stdout . (string) $stderr);
    }
}
