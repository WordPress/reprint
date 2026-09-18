<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards lazy loading of shared utilities and client post-migration tasks.
 *
 * WordPress\Reprint\Server\Utils resolves through Composer's classmap. Nothing
 * may require a utility file by path, and neither package may add an
 * autoload.files entry, because Composer executes every such entry on each
 * consumer request.
 */
final class UtilsLoadingTest extends TestCase
{
    private const UTILS_CLASS = 'WordPress\\Reprint\\Server\\Utils';

    public function testUtilsClassResolvesThroughTheAutoloader(): void
    {
        $this->assertTrue(class_exists(self::UTILS_CLASS), self::UTILS_CLASS . ' must autoload.');
        $this->assertSame('/srv/site', \WordPress\Reprint\Server\Utils::trim_right_slash('/srv/site/', 'unix'));
    }

    public function testComposerDoesNotEagerLoadAnyFile(): void
    {
        foreach (['reprint-server', 'reprint-client'] as $package) {
            $composer_path = __DIR__ . '/../packages/' . $package . '/composer.json';
            $composer_json = file_get_contents($composer_path);
            $this->assertNotFalse($composer_json, $package . ' composer.json must be readable.');

            $composer = json_decode($composer_json, true);
            $this->assertIsArray($composer, $package . ' composer.json must contain valid JSON.');

            $this->assertSame(
                [],
                $composer['autoload']['files'] ?? [],
                'Composer executes every autoload.files entry on each consumer request. '
                . 'Consumers must call autoloaded classes instead.'
            );
        }
    }

    /** The test bootstrap loads Utils, so check lazy loading in a fresh process. */
    public function testFileRemovalHelpersAutoloadWithoutLoadingTheClient(): void
    {
        $directory = sys_get_temp_dir() . '/reprint-utils-' . bin2hex(random_bytes(6));
        mkdir($directory . '/plugin', 0700, true);
        file_put_contents($directory . '/plugin/index.php', '<?php');
        $code = <<<'PHP'
        use WordPress\Reprint\Server\Utils;
        require $argv[1];
        $already_loaded = class_exists(Utils::class, false);
        $removed = Utils::remove_local_files_and_directories(['plugin', 'absent'], $argv[2]);
        Utils::remove_directory_and_its_contents($argv[2]);
        echo json_encode([$already_loaded, $removed]);
        PHP;

        try {
            $process = proc_open(
                [PHP_BINARY, '-r', $code, __DIR__ . '/../vendor/autoload.php', $directory],
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes
            );
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $this->assertSame(0, proc_close($process), $output);
            $this->assertSame([false, ['plugin']], json_decode($output, true));
            $this->assertDirectoryDoesNotExist($directory);
        } finally {
            if (is_file($directory . '/plugin/index.php')) {
                unlink($directory . '/plugin/index.php');
            }
            if (is_dir($directory . '/plugin')) {
                rmdir($directory . '/plugin');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /** Class lookup must not run CLI setup or the recovery child's WordPress load. */
    public function testPostProcessAutoloadDoesNotStartTheClientOrWordPress(): void
    {
        $code = <<<'PHP'
        $loader = require $argv[1];
        $files_before = get_included_files();
        $functions_before = get_defined_functions()['user'];
        $constants_before = get_defined_constants(true)['user'] ?? [];
        $class = 'Reprint\\Importer\\PostProcess';
        $already_loaded = class_exists($class, false);
        $autoloaded = class_exists($class);
        echo json_encode([
            'already_loaded' => $already_loaded,
            'autoloaded' => $autoloaded,
            'new_files' => array_values(array_map('basename', array_diff(get_included_files(), $files_before))),
            'new_functions' => array_values(array_diff(get_defined_functions()['user'], $functions_before)),
            'new_constants' => array_keys(array_diff_key(get_defined_constants(true)['user'] ?? [], $constants_before)),
            'client_registered' => isset($loader->getClassMap()['ImportClient']),
            'client_loaded' => class_exists('ImportClient', false),
        ]);
        PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $code, __DIR__ . '/../vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes
        );
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertSame([
            'already_loaded' => false,
            'autoloaded' => true,
            'new_files' => ['class-post-process.php'],
            'new_functions' => [],
            'new_constants' => [],
            'client_registered' => false,
            'client_loaded' => false,
        ], json_decode($output, true));
    }

    public function testNoFileRequiresTheRemovedUtilityFile(): void
    {
        $root = dirname(__DIR__);
        $this->assertFileDoesNotExist($root . '/packages/reprint-server/src/utils.php');

        $offenders = [];
        foreach (['packages', 'reprint-server-wp', 'tests'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS),
                    static function (SplFileInfo $file): bool {
                        return !in_array($file->getFilename(), ['vendor', 'node_modules'], true);
                    }
                )
            );
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                if ($source !== false && preg_match('/require(?:_once)?\b[^;]*(?<![\w-])utils\.php/', $source)) {
                    $offenders[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'These files still load utils.php by path: ' . implode(', ', $offenders));
    }
}
