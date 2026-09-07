<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-server/src/export.php';

class PreflightPluginHeadersTest extends TestCase {
    /** Extracting Plugin Name must neither execute the plugin nor return its other contents. */
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

    /** Invalid UTF-8 names must be omitted so one plugin cannot break preflight JSON encoding. */
    public function testNonUtf8NamesAreOmittedButUtf8NamesRemainReadable(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'reprint-header-');
        try {
            file_put_contents($path, "<?php\n/* Plugin Name: Caf\xe9 customer tools */\n");
            $this->assertSame([], reprint_read_preflight_plugin_headers($path));
            file_put_contents($path, "<?php\n/* Plugin Name: Café customer tools */\n");
            $this->assertSame(['name' => 'Café customer tools'], reprint_read_preflight_plugin_headers($path));
            // Even a UTF-8 file can yield an invalid name when the 8 KiB read
            // stops between the two bytes of its final character.
            file_put_contents($path, "Plugin Name:" . str_repeat(' ', 8179) . "é\n");
            $this->assertSame([], reprint_read_preflight_plugin_headers($path));
        } finally {
            unlink($path);
        }
    }

    /** Plugin Name beyond WordPress's first 8 KiB is not metadata and must not extend the scan. */
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
