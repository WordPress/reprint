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
        $this->assertSame('/srv/site', \WordPress\Reprint\Server\Utils::trim_right_slash('/srv/site/'));
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
