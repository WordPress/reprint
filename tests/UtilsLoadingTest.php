<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards the loading contract of the shared utility class.
 *
 * WordPress\Reprint\Server\Utils resolves through Composer's classmap. Nothing
 * may require a utility file by path, and the server package must not add an
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
        $composer_path = __DIR__ . '/../packages/reprint-server/composer.json';
        $composer_json = file_get_contents($composer_path);
        $this->assertNotFalse($composer_json, 'reprint-server composer.json must be readable.');

        $composer = json_decode($composer_json, true);
        $this->assertIsArray($composer, 'reprint-server composer.json must contain valid JSON.');

        $this->assertSame(
            [],
            $composer['autoload']['files'] ?? [],
            'Composer executes every autoload.files entry on each consumer request. '
            . 'Utility consumers must call the autoloaded Utils class instead.'
        );
    }

    /** The test bootstrap loads Utils, so check lazy loading in a fresh process. */
    public function testCleanupHelpersAutoloadWithoutLoadingTheClient(): void
    {
        $directory = sys_get_temp_dir() . '/reprint-utils-' . bin2hex(random_bytes(6));
        mkdir($directory . '/plugin', 0700, true);
        file_put_contents($directory . '/plugin/index.php', '<?php');
        $code = <<<'PHP'
        use WordPress\Reprint\Server\Utils;
        require $argv[1];
        $already_loaded = class_exists(Utils::class, false);
        $removed = Utils::remove_host_plugin_paths(['plugin', 'absent'], $argv[2]);
        Utils::rmdir_recursive($argv[2]);
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
