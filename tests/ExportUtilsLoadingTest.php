<?php

use PHPUnit\Framework\TestCase;

/**
 * Guards the per-function loading contract of the utility file.
 *
 * Two plugins on one WordPress.com site can each ship a copy of this package,
 * and one of them declares these functions first. Every helper therefore
 * carries its own function_exists() guard, so the copy that loses the race
 * still supplies whatever the winner did not declare. A single block-wide
 * guard would skip everything after the first sentinel, which is fine only
 * while both copies declare exactly the same set — the moment one ships a
 * helper the other lacks, the first call to it is a fatal.
 */
final class ExportUtilsLoadingTest extends TestCase
{
    private const UTILS_RELATIVE_PATH = '/../packages/reprint-server/src/utils.php';

    /** The function a block-wide guard would be keyed on. */
    private const SENTINEL_FUNCTION = 'build_pdo_dsn';

    private static function utilsPath(): string
    {
        $path = realpath(__DIR__ . self::UTILS_RELATIVE_PATH);
        self::assertNotFalse($path, 'utils.php must exist.');

        return $path;
    }

    /**
     * Reads the helper names declared in the WordPress\Reprint\Server block.
     *
     * @return list<string>
     */
    private static function declaredHelpers(): array
    {
        $source = file_get_contents(self::utilsPath());
        self::assertNotFalse($source, 'utils.php must be readable.');

        $namespace_offset = strpos($source, 'namespace WordPress\\Reprint\\Server {');
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
            . '. A block-wide guard drops every helper a co-resident copy does not declare.'
        );
    }

    public function testEveryHelperLoadsAfterAnotherCopyDeclaredTheSentinel(): void
    {
        $helpers = self::declaredHelpers();
        $this->assertContains(
            self::SENTINEL_FUNCTION,
            $helpers,
            'The function a co-resident copy would declare first must still exist here.'
        );

        $script = <<<'PHP'
namespace WordPress\Reprint\Server {
    // Stands in for a co-resident copy of this package that loaded first.
    function build_pdo_dsn(string $db_host, string $db_name): string {
        return 'first-copy';
    }
}

namespace {
    require $argv[1];

    $declared = [];
    foreach (explode(',', $argv[2]) as $name) {
        $declared[$name] = function_exists('WordPress\\Reprint\\Server\\' . $name);
    }
    echo json_encode($declared);
}
PHP;

        $stdout = $this->runUtilityLoader($script, [self::utilsPath(), implode(',', $helpers)]);

        $declared = json_decode($stdout, true);
        $this->assertIsArray($declared, "Subprocess output was not JSON: {$stdout}");

        $missing = array_keys(array_filter($declared, static function ($is_declared) {
            return !$is_declared;
        }));

        $this->assertSame(
            [],
            $missing,
            'A co-resident copy declaring ' . self::SENTINEL_FUNCTION
            . '() suppressed these helpers: ' . implode(', ', $missing) . '.'
        );
    }

    public function testTheFirstDeclarationOfASharedHelperWins(): void
    {
        $script = <<<'PHP'
namespace WordPress\Reprint\Server {
    function build_pdo_dsn(string $db_host, string $db_name): string {
        return 'first-copy';
    }
}

namespace {
    require $argv[1];
    echo \WordPress\Reprint\Server\build_pdo_dsn('localhost', 'wordpress');
    echo '|';
    echo \WordPress\Reprint\Server\trim_right_slash('/srv/site/');
}
PHP;

        $stdout = $this->runUtilityLoader($script, [self::utilsPath()]);
        $this->assertSame('first-copy|/srv/site', $stdout);
    }

    public function testAPreV0100CopyCannotReachTheseHelpers(): void
    {
        $helpers = self::declaredHelpers();

        // wpcomsh is pinned to reprint-exporter v0.1.47, which declares its
        // helpers in WordPress\Reprint\Exporter. Nothing here uses that
        // namespace any more, so its build_pdo_dsn(), parse_size(),
        // json_encode_or_throw(), normalize_path() and assert_valid_path()
        // cannot win any of these names. Before the rename they could, and
        // this runtime would have called wpcomsh's implementations.
        $script = <<<'PHP'
namespace WordPress\Reprint\Exporter {
    // Stands in for reprint-exporter v0.1.47, loaded first by another plugin.
    function build_pdo_dsn(string $db_host, string $db_name): string { return 'v0.1.47'; }
    function parse_size(string $value): int { return -1; }
    function json_encode_or_throw($value, int $flags = 0): string { return 'v0.1.47'; }
    function normalize_path(string $path): string { return 'v0.1.47'; }
    function assert_valid_path(string $path, string $label = 'path'): void {}
}

namespace {
    require $argv[1];

    $declared = [];
    foreach (explode(',', $argv[2]) as $name) {
        $declared[$name] = function_exists('WordPress\\Reprint\\Server\\' . $name);
    }
    echo json_encode([
        'declared' => $declared,
        'dsn' => \WordPress\Reprint\Server\build_pdo_dsn('localhost', 'wordpress'),
        'size' => \WordPress\Reprint\Server\parse_size('64M'),
    ]);
}
PHP;

        $stdout = $this->runUtilityLoader($script, [self::utilsPath(), implode(',', $helpers)]);
        $report = json_decode($stdout, true);
        $this->assertIsArray($report, "Subprocess output was not JSON: {$stdout}");

        $missing = array_keys(array_filter($report['declared'], static function ($is_declared) {
            return !$is_declared;
        }));
        $this->assertSame([], $missing, 'These helpers were not declared: ' . implode(', ', $missing) . '.');

        $this->assertSame(
            'mysql:host=localhost;dbname=wordpress;charset=utf8mb4',
            $report['dsn'],
            'A pre-v0.10.0 copy in WordPress\\Reprint\\Exporter won build_pdo_dsn().'
        );
        $this->assertSame(
            64 * 1024 * 1024,
            $report['size'],
            'A pre-v0.10.0 copy in WordPress\\Reprint\\Exporter won parse_size().'
        );
    }

    /**
     * Runs a PHP snippet in a subprocess and returns its stdout.
     *
     * @param string $script PHP source, without the opening tag.
     * @param list<string> $arguments Values the snippet reads from $argv.
     */
    private function runUtilityLoader(string $script, array $arguments): string
    {
        $process = proc_open(
            array_merge([PHP_BINARY, '-r', $script], $arguments),
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

        return (string) $stdout;
    }
}
