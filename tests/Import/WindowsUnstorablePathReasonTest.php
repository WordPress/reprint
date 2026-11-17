<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match importer test style.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

use function Reprint\Importer\windows_unstorable_path_reason;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/import/functions.php';

/** The name rules are plain string checks, so every platform runs them. */
final class WindowsUnstorablePathReasonTest extends TestCase
{
    /** @dataProvider storable_paths */
    public function testWindowsCanStorePath(string $relative_path): void
    {
        $this->assertNull(windows_unstorable_path_reason($relative_path));
    }

    /** @return array<string,list<string>> Paths Windows stores under the same name. */
    public static function storable_paths(): array
    {
        return [
            'plain file' => ['wp-content/uploads/photo.jpg'],
            'spaces inside a name' => ['test-data/file with spaces.txt'],
            'accented name' => ["test-data/caf\u{e9}.txt"],
            'Chinese name' => ["test-data/\u{4e2d}\u{6587}.txt"],
            'emoji name' => ["test-data/\u{1F525}\u{1F680}.txt"],
            'leading dot' => ['.htaccess'],
            'device name inside a longer name' => ['console.txt'],
            'device name as a suffix' => ['my-aux.txt'],
            'device name as an extension' => ['notes.con'],
            'two-digit port number' => ['COM10'],
            'empty path' => [''],
        ];
    }

    /** @dataProvider unstorable_paths */
    public function testWindowsCannotStorePath(string $relative_path, string $expected_reason): void
    {
        $this->assertSame($expected_reason, windows_unstorable_path_reason($relative_path));
    }

    /** @return array<string,array{string,string}> Paths and the reason naming their first unstorable component. */
    public static function unstorable_paths(): array
    {
        $paths = [
            'newline' => ["test-data/file\nwith\nnewlines.txt", 'the name "file\nwith\nnewlines.txt" contains the control character 0x0A'],
            'tab' => ["a\tb", 'the name "a\tb" contains the control character 0x09'],
            'unit separator' => ["a\x1Fb", 'the name "a\u001fb" contains the control character 0x1F'],
            'invalid UTF-8' => ["test-data/invalid\xFF\xFEutf8.txt", 'the name "invalid��utf8.txt" is not valid UTF-8'],
            'trailing dot' => ['readme.', 'the name "readme." ends in a dot'],
            'trailing space' => ['readme ', 'the name "readme " ends in a space'],
            'directory ending in a dot' => ['photos./a.jpg', 'the name "photos." ends in a dot'],
            'device name' => ['CON', 'the name "CON" uses the reserved device name CON'],
            'lowercase device name' => ['nul', 'the name "nul" uses the reserved device name NUL'],
            'device name with an extension' => ['notes/aux.txt', 'the name "aux.txt" uses the reserved device name AUX'],
            'device name with two extensions' => ['Prn.tar.gz', 'the name "Prn.tar.gz" uses the reserved device name PRN'],
            'device name with spaces before the extension' => ['con .txt', 'the name "con .txt" uses the reserved device name CON'],
            'serial port' => ['COM1', 'the name "COM1" uses the reserved device name COM1'],
            'parallel port' => ['lpt9.log', 'the name "lpt9.log" uses the reserved device name LPT9'],
            'superscript port' => ["COM\u{b9}", "the name \"COM\u{b9}\" uses the reserved device name COM\u{b9}"],
            'device directory' => ['wp-content/con/index.php', 'the name "con" uses the reserved device name CON'],
            'backslash makes a device component' => ['dir\\NUL', 'the name "NUL" uses the reserved device name NUL'],
        ];
        foreach (['<', '>', ':', '"', '|', '?', '*'] as $character) {
            $paths["character {$character}"] = [
                "uploads/a{$character}b.txt",
                'the name ' . json_encode("a{$character}b.txt", JSON_UNESCAPED_SLASHES) . ' contains the character ' . json_encode($character),
            ];
        }
        return $paths;
    }

    /** Skipping a directory skips its descendants, so the reason names the directory. */
    public function testReasonNamesTheFirstUnstorableComponent(): void
    {
        $this->assertSame(
            'the name "bad:dir" contains the character ":"',
            windows_unstorable_path_reason('site/bad:dir/inner?.txt')
        );
    }
}
