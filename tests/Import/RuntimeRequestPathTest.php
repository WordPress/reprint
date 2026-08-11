<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/host/class-runtime-manifest.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/target-runtime/load.php';

class RuntimeRequestPathTest extends TestCase
{
    private string $temporary_directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporary_directory = sys_get_temp_dir()
            . '/runtime-request-path-'
            . bin2hex(random_bytes(6));
        mkdir($this->temporary_directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->temporary_directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->temporary_directory . '/' . $entry);
            }
        }
        @rmdir($this->temporary_directory);

        parent::tearDown();
    }

    /**
     * @dataProvider provideRequestPaths
     */
    public function testRuntimeRequestPathPreservesSafeRawPaths(
        string $request_uri,
        ?string $expected_path
    ): void {
        $this->assertSame(
            $expected_path,
            $this->run_generated_runtime_helper('reprint_runtime_request_path', $request_uri)
        );
    }

    public static function provideRequestPaths(): array
    {
        return [
            'path and query' => [
                '/wp-content/uploads/2026/photo.jpg?size=large',
                '/wp-content/uploads/2026/photo.jpg',
            ],
            'empty request target' => ['', '/'],
            'encoded parent component' => ['/wp-content/%2e%2e/wp-config.php', null],
            'encoded NUL byte' => ['/wp-content/uploads/%00photo.jpg', null],
        ];
    }

    /**
     * @dataProvider provideUploadsRequestPaths
     */
    public function testRuntimeUploadsRequestPathReturnsTheUploadsSuffix(
        string $request_uri,
        ?string $expected_path
    ): void {
        $this->assertSame(
            $expected_path,
            $this->run_generated_runtime_helper(
                'reprint_runtime_uploads_request_relative_path',
                $request_uri
            )
        );
    }

    public static function provideUploadsRequestPaths(): array
    {
        return [
            'site root upload' => [
                '/wp-content/uploads/2026/photo.jpg?size=large',
                '2026/photo.jpg',
            ],
            'subdirectory upload' => [
                '/site/wp-content/uploads/photo.jpg',
                'photo.jpg',
            ],
            'uploads directory' => ['/wp-content/uploads/', null],
            'outside uploads' => ['/wp-content/themes/style.css', null],
            'encoded parent component' => ['/wp-content/uploads/%2e%2e/wp-config.php', null],
        ];
    }

    private function run_generated_runtime_helper(string $function_name, string $request_uri): ?string
    {
        $runtime_path = $this->temporary_directory . '/runtime.php';
        $manifest = new \RuntimeManifest('other');
        $runtime = \generate_runtime_php($manifest, $this->temporary_directory);
        file_put_contents(
            $runtime_path,
            $runtime
            . "\n\$_SERVER['REQUEST_URI'] = " . var_export($request_uri, true) . ";\n"
            . "echo json_encode({$function_name}(), JSON_THROW_ON_ERROR);\n"
        );

        $process = proc_open(
            [PHP_BINARY, $runtime_path],
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
        $this->assertIsString($stdout);

        $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue(is_string($result) || $result === null);

        return $result;
    }
}
