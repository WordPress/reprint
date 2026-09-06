<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class ConfigEnvironmentTest extends TestCase {
    /** Comments, strings, methods, and dynamic names do not identify global getenv reads. */
    public function testOnlyLiteralEnvironmentReadsAreReportedWithoutValues(): void
    {
        $config = <<<'CONFIG'
<?php
// getenv('COMMENTED_SECRET');
$example = "getenv('QUOTED_SECRET')";
define('MEDIA_BUCKET', getenv('REPRINT_TEST_MISSING_BUCKET'));
$optional = \getenv /* comment */ ('REPRINT_TEST_OPTIONAL', true) ?: 'fallback';
$duplicate = getenv('REPRINT_TEST_MISSING_BUCKET');
$dynamic = getenv('PREFIX_' . $name);
$method = $object->getenv('METHOD_SECRET');
$static = Example::getenv('STATIC_SECRET');
$other_function = custom\getenv('CUSTOM_SECRET');
CONFIG;
        $this->assertSame(
            ['REPRINT_TEST_MISSING_BUCKET', 'REPRINT_TEST_OPTIONAL'],
            config_environment_names($config)
        );
    }

    /** Inspecting config must not execute site code. */
    public function testConfigIsReadAsTextAndNeverExecuted(): void
    {
        $config = '<?php throw new Exception("Do not execute this config"); getenv("MEDIA_BUCKET");';
        $this->assertSame(['MEDIA_BUCKET'], config_environment_names($config));
    }
    /** @dataProvider runtime_layouts */
    public function testRuntimeReportsMissingNamesWithoutCopyingOrPrintingSecrets(bool $flat): void
    {
        $root = sys_get_temp_dir() . '/reprint-config-' . bin2hex(random_bytes(6));
        $site = $root . '/files/site';
        mkdir($site, 0700, true);
        file_put_contents($site . '/index.php', '<?php');
        $config = '<?php throw new Exception("must not execute"); '
            . 'getenv("REPRINT_TEST_MISSING_BUCKET"); getenv("REPRINT_TEST_PRESENT_CONFIG"); '
            . '$secret = "source-secret-never-copy";';
        file_put_contents($site . '/wp-config.php', $config);
        $previous = getenv('REPRINT_TEST_PRESENT_CONFIG');
        putenv('REPRINT_TEST_PRESENT_CONFIG=target-secret-never-print');
        $client = new ImportClient('https://source.example', $root . '/state', $root . '/files');
        write_current_pull_state($client, ['preflight' => ['http_code' => 200, 'data' => [
            'ok' => true,
            'runtime' => ['document_root' => '/site'],
            'wp_detect' => ['roots' => [['wp_config_path' => '/site/wp-config.php']]],
        ]]]);
        try {
            $options = ['runtime' => 'php-builtin', 'output_dir' => $root . '/runtime'];
            if ($flat) {
                $options['flat_document_root'] = $site;
            }
            ob_start();
            try {
                $client->run_apply_runtime($options);
            } finally {
                ob_end_clean();
            }
            $log = file_get_contents($root . '/state/audit.log');
            $this->assertStringContainsString('reads REPRINT_TEST_MISSING_BUCKET', $log);
            $this->assertStringContainsString('may provide a fallback', $log);
            $this->assertStringNotContainsString('reads REPRINT_TEST_PRESENT_CONFIG', $log);
            $runtime = file_get_contents($root . '/runtime/runtime.php');
            $this->assertStringNotContainsString('source-secret-never-copy', $log . $runtime);
            $this->assertStringNotContainsString('target-secret-never-print', $log . $runtime);
            $this->assertSame($config, file_get_contents($site . '/wp-config.php'));
        } finally {
            putenv($previous === false ? 'REPRINT_TEST_PRESENT_CONFIG' : 'REPRINT_TEST_PRESENT_CONFIG=' . $previous);
            $this->remove_tree($root);
        }
    }

    /** Config lookup must work before and after flat-docroot. */
    public static function runtime_layouts(): array
    {
        return [[false], [true]];
    }

    /** Removes only this test's temporary site and generated runtime. */
    private function remove_tree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove_tree($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
