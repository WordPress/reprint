<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The target may run PHP 7.2 even when the sending CLI runs a newer PHP.
 * Repo-wide compatibility lint currently reports unrelated vendor-patched
 * code, so keep an enforced scan over every direct-push exporter file loaded
 * by the HTTP server or WordPress wrapper.
 */
final class ExporterPushPhpCompatibilityTest extends TestCase {

    public function testDirectPushTargetStaysParseableOnPhp72(): void {
        $phpcs_path = realpath(__DIR__ . '/../vendor/bin/phpcs');
        self::assertNotFalse($phpcs_path, 'vendor/bin/phpcs is missing; run composer install');

        $relative_paths = [
            '../packages/reprint-exporter/src/class-staged-push-stream-protocol.php',
            '../packages/reprint-exporter/src/class-staged-apply.php',
            '../packages/reprint-exporter/src/class-staged-endpoints.php',
            '../packages/reprint-exporter/src/class-http-server.php',
            '../reprint-exporter-wp/lib.php',
        ];
        $paths = [];
        foreach ($relative_paths as $relative_path) {
            $path = realpath(__DIR__ . '/' . $relative_path);
            self::assertNotFalse($path, 'Direct-push compatibility input is missing: ' . $relative_path);
            $paths[] = escapeshellarg($path);
        }

        exec(
            escapeshellarg($phpcs_path)
                . ' --standard=PHPCompatibility --runtime-set testVersion 7.2- -q '
                . implode(' ', $paths) . ' 2>&1',
            $scan_output,
            $scan_exit_code
        );

        self::assertSame(0, $scan_exit_code, implode("\n", $scan_output));
    }
}
