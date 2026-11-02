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
        $this->assertMatchesRegularExpression('/^Contributors: zieladam, wordpressdotorg$/m', $readme);
    }

    public function testDirectoryImagesUseTheRequiredDimensions(): void {
        foreach (['icon-128x128', 'icon-256x256', 'banner-772x250', 'banner-1544x500'] as $name) {
            $path = __DIR__ . '/../.wordpress-org/' . $name . '.png';
            $this->assertFileExists($path);
            preg_match('/([0-9]+)x([0-9]+)$/', $name, $dimensions);
            $image = getimagesize($path);
            $this->assertNotFalse($image);
            $width = (int) $dimensions[1];
            $height = (int) $dimensions[2];
            $this->assertSame([$width, $height, IMAGETYPE_PNG], array_slice($image, 0, 3));
        }
        $this->assertFileExists(__DIR__ . '/../.wordpress-org/icon.svg');
    }

    public function testReadmeLinksToHandbookPublicationRoutes(): void {
        $manifest = json_decode(file_get_contents(__DIR__ . '/../docs/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $base = 'https://developer.wordpress.org/reprint/';
        $routes = [];
        foreach ($manifest as $key => $page) {
            $routes[$key] = $key === 'handbook' ? $base : ( $page['parent'] === null ? $base : $routes[$page['parent']] ) . $page['slug'] . '/';
        }
        $readme = file_get_contents(__DIR__ . '/../reprint-server-wp/readme.txt');
        preg_match_all('/\[[^\]]+\]\(([^\s)]+)\)/', $readme, $links);
        foreach ($links[1] as $link) {
            $this->assertStringStartsWith('https://', $link);
            if (strpos($link, $base) === 0) {
                $this->assertContains($link, $routes);
            } elseif (strpos($link, 'https://github.com/') === 0) {
                $this->assertSame('https://github.com/WordPress/reprint', $link, 'Only source development links to GitHub.');
            }
        }
        $description = explode("\n\n", $readme)[1];
        $this->assertLessThanOrEqual(150, mb_strlen($description));
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
                '[Download reprint.phar](https://plugins.svn.wordpress.org/reprint/downloads/reprint.phar)'
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
