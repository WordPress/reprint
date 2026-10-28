<?php

use PHPUnit\Framework\TestCase;

class ReprintServerPluginPackageTest extends TestCase {
    private $project_directory;

    protected function setUp(): void {
        $this->project_directory = sys_get_temp_dir() . '/reprint-version-stamp-' . uniqid();
        mkdir($this->project_directory . '/bin', 0700, true);
        mkdir($this->project_directory . '/reprint-server-wp', 0700);
        copy(__DIR__ . '/../bin/stamp-plugin-version.sh', $this->project_directory . '/bin/stamp-plugin-version.sh');
        foreach (['index.php', 'lib.php', 'readme.txt'] as $plugin_file) {
            copy(__DIR__ . '/../reprint-server-wp/' . $plugin_file, $this->project_directory . '/reprint-server-wp/' . $plugin_file);
        }
    }

    protected function tearDown(): void {
        foreach (['index.php', 'lib.php', 'readme.txt'] as $plugin_file) {
            unlink($this->project_directory . '/reprint-server-wp/' . $plugin_file);
        }
        unlink($this->project_directory . '/bin/stamp-plugin-version.sh');
        rmdir($this->project_directory . '/bin');
        rmdir($this->project_directory . '/reprint-server-wp');
        rmdir($this->project_directory);
    }

    public function testPluginHeaderAndReadmeUseTheReprintDisplayName(): void {
        $plugin_directory = $this->project_directory . '/reprint-server-wp/';
        $this->assertStringContainsString(" * Plugin Name: Reprint\n", file_get_contents($plugin_directory . 'index.php'));
        $this->assertStringStartsWith("=== Reprint ===\n", file_get_contents($plugin_directory . 'readme.txt'));
    }

    /** Use the case-sensitive WordPress.org account name supplied in directory review. */
    public function testReadmeListsTheWordPressOrgContributor(): void {
        $readme = file_get_contents($this->project_directory . '/reprint-server-wp/readme.txt');
        $this->assertMatchesRegularExpression('/^Contributors: zieladam$/m', $readme);
    }

    /** @dataProvider versionProvider */
    public function testStampKeepsTheHeaderRuntimeAndReadmeVersionsTogether(string ...$versions): void {
        foreach ($versions as $version) {
            $output = [];
            exec('bash ' . escapeshellarg($this->project_directory . '/bin/stamp-plugin-version.sh') . ' ' . escapeshellarg($version) . ' 2>&1', $output, $exit_code);
            $this->assertSame(0, $exit_code, implode("\n", $output));
            $plugin_directory = $this->project_directory . '/reprint-server-wp/';
            $this->assertStringContainsString(' * Version: ' . $version . "\n", file_get_contents($plugin_directory . 'index.php'));
            $this->assertStringContainsString("define(__NAMESPACE__ . '\\\\VERSION', '" . $version . "');", file_get_contents($plugin_directory . 'lib.php'));
            $this->assertStringContainsString('Stable tag: ' . $version . "\n", file_get_contents($plugin_directory . 'readme.txt'));
            $this->assertSame(2, substr_count(
                file_get_contents($plugin_directory . 'readme.txt'),
                '[Download reprint.phar](https://plugins.svn.wordpress.org/reprint/downloads/' . $version . '/reprint.phar)'
            ));
        }
    }

    public static function versionProvider(): array {
        return [
            'stable release' => ['1.2.3'],
            'development bump' => ['1.2.4-dev'],
            'successive patch minor and major releases' => ['1.2.3', '1.2.4-dev', '1.2.4', '1.2.4', '1.3.0-dev', '1.3.0', '2.0.0-dev', '2.0.0'],
        ];
    }
}
