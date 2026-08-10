<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * import.php requires the upload lib for every command, including pull on
 * PHP 7.4 — the push client's PHP 8.1 requirement is enforced at runtime in
 * its constructor, not at parse time. This scan fails when 8.x-only syntax
 * sneaks into the upload or push lib and would fatal 7.4 pull users at require time.
 * The repo-wide lint:php:compat covers the same files; this narrow scan keeps
 * PHP 7.4 parseability tied directly to the push regression suite.
 */
class PushClientPhpCompatibilityTest extends TestCase
{
    public function testPushLibsStayParseableOnPhp74(): void
    {
        $phpcs_path = realpath(__DIR__ . '/../../vendor/bin/phpcs');
        $process_lock_path = realpath(
            __DIR__ . '/../../packages/reprint-client/src/lib/class-reprint-process-lock.php'
        );
        $upload_lib_path = realpath(__DIR__ . '/../../packages/reprint-client/src/lib/upload');
        $push_lib_path = realpath(__DIR__ . '/../../packages/reprint-client/src/lib/push');
        $this->assertNotFalse($phpcs_path, 'vendor/bin/phpcs is missing; run composer install');
        $this->assertNotFalse($process_lock_path);
        $this->assertNotFalse($upload_lib_path);
        $this->assertNotFalse($push_lib_path);

        exec(
            escapeshellarg($phpcs_path)
                . ' --standard=PHPCompatibility --runtime-set testVersion 7.4- -q '
                . escapeshellarg($process_lock_path) . ' '
                . escapeshellarg($upload_lib_path) . ' '
                . escapeshellarg($push_lib_path) . ' 2>&1',
            $scan_output,
            $scan_exit_code
        );

        $this->assertSame(0, $scan_exit_code, implode("\n", $scan_output));
    }
}
