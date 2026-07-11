<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- The test namespace matches the existing ImportTests suite.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

/**
 * import.php requires the upload lib for every command, including pull on
 * PHP 7.4 — the push client's PHP 8.1 requirement is enforced at runtime in
 * its constructor, not at parse time. The direct session driver and journal
 * are required beside it. This scan fails when 8.x-only syntax sneaks into
 * any of those always-loaded files and would fatal 7.4 pull users at require time.
 * The repo-wide lint:php:compat covers the same files but currently fails on
 * unrelated pre-existing findings, so this narrow scan is the enforced
 * regression check.
 */
class PushClientPhpCompatibilityTest extends TestCase {

    public function testUploadLibStaysParseableOnPhp74(): void
    {
        $phpcs_path = realpath(__DIR__ . '/../../vendor/bin/phpcs');
        $upload_lib_path = realpath(__DIR__ . '/../../packages/reprint-importer/src/lib/upload');
        $push_driver_path = realpath(__DIR__ . '/../../packages/reprint-importer/src/lib/push/class-staged-apply-session-driver.php');
        $push_journal_path = realpath(__DIR__ . '/../../packages/reprint-importer/src/lib/push/class-push-journal.php');
        $this->assertNotFalse($phpcs_path, 'vendor/bin/phpcs is missing; run composer install');
        $this->assertNotFalse($upload_lib_path);
        $this->assertNotFalse($push_driver_path);
        $this->assertNotFalse($push_journal_path);

        exec(
            escapeshellarg($phpcs_path)
                . ' --standard=PHPCompatibility --runtime-set testVersion 7.4- -q '
                . escapeshellarg($upload_lib_path) . ' '
                . escapeshellarg($push_driver_path) . ' '
                . escapeshellarg($push_journal_path) . ' 2>&1',
            $scan_output,
            $scan_exit_code
        );

        $this->assertSame(0, $scan_exit_code, implode("\n", $scan_output));
    }
}
