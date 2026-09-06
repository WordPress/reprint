<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-server/src/export.php';

class PreflightPluginHeadersTest extends TestCase {
    /** Only the public header crosses preflight; code and secret values do not. */
    public function testHeaderReadDoesNotExecuteOrExportPluginCode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reprint-header-');
        try {
            file_put_contents($path, "<?php\n/*\nPlugin Name: WP Engine System\n*/\nthrow new Exception('must-not-execute-secret');");
            $this->assertSame(['name' => 'WP Engine System'], reprint_read_preflight_plugin_headers($path));
            file_put_contents($path, "<?php\n/* Plugin Name: Customer checkout rules */\n");
            $this->assertSame(['name' => 'Customer checkout rules'], reprint_read_preflight_plugin_headers($path));
        } finally {
            unlink($path);
        }
    }

    /** A large plugin body does not extend the bounded public-header scan. */
    public function testHeadersPastWordpressHeaderLimitAreNotRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reprint-header-');
        try {
            file_put_contents($path, str_repeat(' ', 8192) . "\nPlugin Name: WP Engine System\n");
            $this->assertSame([], reprint_read_preflight_plugin_headers($path));
        } finally {
            unlink($path);
        }
    }
}
