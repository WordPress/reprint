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

    /** @dataProvider versionProvider */
    public function testStampKeepsTheHeaderRuntimeAndReadmeVersionsTogether(string $version): void {
        exec('bash ' . escapeshellarg($this->project_directory . '/bin/stamp-plugin-version.sh') . ' ' . escapeshellarg($version) . ' 2>&1', $output, $exit_code);
        $this->assertSame(0, $exit_code, implode("\n", $output));
        $plugin_directory = $this->project_directory . '/reprint-server-wp/';
        $this->assertStringContainsString(' * Version: ' . $version . "\n", file_get_contents($plugin_directory . 'index.php'));
        $this->assertStringContainsString("define(__NAMESPACE__ . '\\\\VERSION', '" . $version . "');", file_get_contents($plugin_directory . 'lib.php'));
        $this->assertStringContainsString('Stable tag: ' . $version . "\n", file_get_contents($plugin_directory . 'readme.txt'));
    }

    public static function versionProvider(): array {
        return [
            'stable release' => ['1.2.3'],
            'development bump' => ['1.2.4-dev'],
        ];
    }
}
