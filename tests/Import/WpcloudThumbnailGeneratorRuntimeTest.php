<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match existing importer test class style.
// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quote values in a generated runtime script.

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

class WpcloudThumbnailGeneratorRuntimeTest extends TestCase
{
    private string $temporary_directory = '';
    private string $content_directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The WP Cloud thumbnail runtime test requires the GD extension.');
        }

        $this->temporary_directory = sys_get_temp_dir()
            . '/wpcloud-thumbnail-runtime-'
            . bin2hex(random_bytes(6));
        $this->content_directory = $this->temporary_directory . '/wp-content';
        mkdir($this->content_directory . '/uploads/2026', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove_tree($this->temporary_directory);

        parent::tearDown();
    }

    public function testGeneratesAThumbnailBeneathUploadsForASubdirectoryRequest(): void
    {
        $original_path = $this->content_directory . '/uploads/2026/photo.png';
        $thumbnail_path = $this->content_directory . '/uploads/2026/photo-10x10.png';
        $this->write_png($original_path, 20, 20);

        $result = $this->run_runtime('/site/wp-content/uploads/2026/photo-10x10.png');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertFileExists($thumbnail_path);
        $dimensions = getimagesize($thumbnail_path);
        $this->assertIsArray($dimensions);
        $this->assertSame(10, $dimensions[0]);
        $this->assertSame(10, $dimensions[1]);
    }

    public function testDoesNotMapAParentComponentOutsideUploads(): void
    {
        $original_path = $this->content_directory . '/outside.png';
        $thumbnail_path = $this->content_directory . '/outside-10x10.png';
        $this->write_png($original_path, 20, 20);

        $result = $this->run_runtime('/site/wp-content/uploads/../outside-10x10.png');

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertFileDoesNotExist($thumbnail_path);
    }

    /** @return array{exit:int,stderr:string} */
    private function run_runtime(string $request_uri): array
    {
        $manifest = new \RuntimeManifest('wpcloud');
        $manifest->routes[] = [
            'handler' => 'wpcloud-thumbnail-generator',
            'path_pattern' => '/wp-content/uploads/.*-\\d+x\\d+\\.\\w+$',
            'condition' => 'file_not_found',
            'description' => 'Generate missing WordPress thumbnail sizes from originals using GD',
        ];
        $runtime = \generate_runtime_php($manifest, $this->temporary_directory);
        $script_path = $this->temporary_directory . '/runtime.php';
        $bootstrap = "<?php\n"
            . 'define(' . var_export('WP_CONTENT_DIR', true) . ', '
            . var_export($this->content_directory, true) . ");\n"
            . "\$_SERVER['REQUEST_URI'] = " . var_export($request_uri, true) . ";\n";
        $this->assertStringStartsWith('<?php', $runtime);
        file_put_contents($script_path, $bootstrap . substr($runtime, strlen('<?php')));

        $process = proc_open(
            [PHP_BINARY, $script_path],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertIsString($stderr);

        return [
            'exit' => proc_close($process),
            'stderr' => $stderr,
        ];
    }

    private function write_png(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 35, 90, 180);
        $this->assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        $this->assertTrue(imagepng($image, $path));
        imagedestroy($image);
    }

    private function remove_tree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove_tree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
