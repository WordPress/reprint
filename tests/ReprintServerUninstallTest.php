<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReprintServerUninstallTest extends TestCase
{
    public function testUninstallRemovesOnlyReprintSettings(): void
    {
        $this->assertSame('clean', $this->runUninstall(false));
    }

    public function testUninstallCleansEverySiteAndNetworkAcrossMultipleBatches(): void
    {
        $this->assertSame('clean', $this->runUninstall(true));
    }

    public function testDirectAccessCannotDeleteSettings(): void
    {
        $this->assertSame('', $this->runUninstall(false, false));
    }

    private function runUninstall(bool $multisite, bool $authorized = true): string
    {
        $script = <<<'PHP'
$multisite = $argv[2] === '1';
$current_site = 1;
$site_stack = [];
$settings = [
    'reprint_server_connection_token' => 'token',
    'reprint_server_push_authorized_token_fingerprint' => 'fingerprint',
    'reprint_server_public_keys' => [['key_id' => '0123456789abcdef']],
    'site_export_secret' => 'old-token',
    'site_export_push_authorized_token_fingerprint' => 'old-fingerprint',
    '_transient_reprint_server_activated' => 1,
    '_transient_timeout_reprint_server_activated' => time() + 30,
    '_transient_site_export_activated' => 1,
    '_transient_timeout_site_export_activated' => time() + 30,
];
$unrelated = ['reprint_server_connection_token_extra' => 'keep', 'another_plugin' => 'keep'];
$sites = array_fill(1, $multisite ? 205 : 1, $settings + $unrelated);
$networks = $multisite ? array_fill(1, 103, [
    'reprint_server_connection_token' => 'network-token',
    // Multisite stores enrolled keys in the network option.
    'reprint_server_public_keys' => [['key_id' => 'fedcba9876543210']],
] + $unrelated) : [];
function is_multisite() { return $GLOBALS['multisite']; }
function get_sites($args) { return array_slice(array_keys($GLOBALS['sites']), $args['offset'], $args['number']); }
function get_networks($args) { return array_slice(array_keys($GLOBALS['networks']), $args['offset'], $args['number']); }
function switch_to_blog($site_id) {
    $GLOBALS['site_stack'][] = $GLOBALS['current_site'];
    $GLOBALS['current_site'] = $site_id;
}
function restore_current_blog() { $GLOBALS['current_site'] = array_pop($GLOBALS['site_stack']); }
function delete_option($name) {
    if (!defined('WP_UNINSTALL_PLUGIN')) { throw new RuntimeException('Unauthorized cleanup.'); }
    unset($GLOBALS['sites'][$GLOBALS['current_site']][$name]);
}
function delete_transient($name) {
    delete_option('_transient_' . $name);
    delete_option('_transient_timeout_' . $name);
}
function delete_network_option($network_id, $name) { unset($GLOBALS['networks'][$network_id][$name]); }
if ($argv[3] === '1') { define('WP_UNINSTALL_PLUGIN', 'reprint-server-wp/index.php'); }
// WordPress has no uninstall work to run until the plugin supplies this file.
if (is_file($argv[1])) {
    include $argv[1];
    include $argv[1];
}
foreach (array_merge($sites, $networks) as $remaining) {
    if ($remaining !== $unrelated) { throw new RuntimeException('Plugin settings remain: ' . json_encode($remaining)); }
}
if ($current_site !== 1 || $site_stack !== []) { throw new RuntimeException('The original site was not restored.'); }
echo 'clean';
PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $script, __DIR__ . '/../reprint-server-wp/uninstall.php', $multisite ? '1' : '0', $authorized ? '1' : '0'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stderr);
        return $stdout;
    }
}
