<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards the per-function loading contract of the exporter utility file.
 *
 * Two plugins on one WordPress.com site can each ship a copy of this package,
 * and the older one may load first. Every helper therefore carries its own
 * function_exists() guard, so an older copy only takes the helpers it actually
 * declares and this copy still supplies the rest. A single block-wide guard
 * would skip everything after the first sentinel and turn the first call to a
 * newer helper into a fatal.
 */
final class ExportUtilsLoadingTest extends TestCase
{
    private const UTILS_RELATIVE_PATH = '/../packages/reprint-server/src/utils.php';

    /** The guard an older copy of the package keys on. */
    private const SENTINEL_FUNCTION = 'build_pdo_dsn';

    private static function utilsPath(): string
    {
        $path = realpath(__DIR__ . self::UTILS_RELATIVE_PATH);
        self::assertNotFalse($path, 'utils.php must exist.');

        return $path;
    }

    /**
     * Reads the helper names declared in the WordPress\Reprint\Exporter block.
     *
     * @return list<string>
     */
    private static function declaredHelpers(): array
    {
        $source = file_get_contents(self::utilsPath());
        self::assertNotFalse($source, 'utils.php must be readable.');

        $namespace_offset = strpos($source, 'namespace WordPress\\Reprint\\Exporter {');
        self::assertNotFalse($namespace_offset, 'utils.php must declare the exporter namespace block.');

        preg_match_all(
            '/^function (\w+)\(/m',
            substr($source, $namespace_offset),
            $matches
        );

        self::assertNotEmpty($matches[1], 'The exporter namespace block must declare helpers.');

        return $matches[1];
    }

    public function testEveryHelperCarriesItsOwnFunctionExistsGuard(): void
    {
        $lines = file(self::utilsPath(), FILE_IGNORE_NEW_LINES);
        $this->assertNotFalse($lines, 'utils.php must be readable.');

        $unguarded = [];
        foreach ($lines as $index => $line) {
            if (!preg_match('/^function (\w+)\(/', $line, $match)) {
                continue;
            }
            $name = $match[1];
            $expected_guard = "if (!function_exists(__NAMESPACE__ . '\\\\{$name}')) {";

            // Walk back over the docblock that documents the function.
            $cursor = $index - 1;
            if ($cursor >= 0 && trim($lines[$cursor]) === '*/') {
                while ($cursor >= 0 && strpos($lines[$cursor], '/**') !== 0) {
                    --$cursor;
                }
                --$cursor;
            }

            if ($cursor < 0 || $lines[$cursor] !== $expected_guard) {
                $unguarded[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'These helpers are missing their own function_exists() guard: '
            . implode(', ', $unguarded)
            . '. A block-wide guard drops every helper an older co-resident copy does not declare.'
        );
    }

    public function testEveryHelperLoadsAfterAnOlderCopyDeclaredTheSentinel(): void
    {
        $helpers = self::declaredHelpers();
        $this->assertContains(
            self::SENTINEL_FUNCTION,
            $helpers,
            'The sentinel an older copy declares must still exist here.'
        );

        $script = <<<'PHP'
namespace WordPress\Reprint\Exporter {
    // Stands in for an older copy of this package that loaded first.
    function build_pdo_dsn(string $db_host, string $db_name): string {
        return 'older-copy';
    }
}

namespace {
    require $argv[1];

    $declared = [];
    foreach (explode(',', $argv[2]) as $name) {
        $declared[$name] = function_exists('WordPress\\Reprint\\Exporter\\' . $name);
    }
    echo json_encode($declared);
}
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, self::utilsPath(), implode(',', $helpers)],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'Failed to start the utility loader subprocess.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr ?: 'The utility loader subprocess failed.');

        $declared = json_decode($stdout, true);
        $this->assertIsArray($declared, "Subprocess output was not JSON: {$stdout}{$stderr}");

        $missing = array_keys(array_filter($declared, static function ($is_declared) {
            return !$is_declared;
        }));

        $this->assertSame(
            [],
            $missing,
            'An older copy declaring ' . self::SENTINEL_FUNCTION
            . '() suppressed these helpers: ' . implode(', ', $missing) . '.'
        );
    }

    public function testTheSentinelKeepsTheOlderCopysImplementation(): void
    {
        $script = <<<'PHP'
namespace WordPress\Reprint\Exporter {
    function build_pdo_dsn(string $db_host, string $db_name): string {
        return 'older-copy';
    }
}

namespace {
    require $argv[1];
    echo \WordPress\Reprint\Exporter\build_pdo_dsn('localhost', 'wordpress');
    echo '|';
    echo \WordPress\Reprint\Exporter\trim_right_slash('/srv/site/');
}
PHP;

        $process = proc_open(
            [PHP_BINARY, '-r', $script, self::utilsPath()],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'Failed to start the utility loader subprocess.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr ?: 'The utility loader subprocess failed.');
        $this->assertSame('older-copy|/srv/site', $stdout);
    }
}
