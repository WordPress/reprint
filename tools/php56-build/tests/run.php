<?php

declare(strict_types=1);

use WordPress\Reprint\Build\Php56SyntaxValidator;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$tool_root = dirname(__DIR__);
$fixture_root = __DIR__ . '/Fixture';
$temporary_root = sys_get_temp_dir() . '/reprint-php56-rector-' . getmypid();
if (!mkdir($temporary_root, 0700) && !is_dir($temporary_root)) {
    fail_test('Could not create the temporary fixture directory.');
}

try {
    $input = $fixture_root . '/php72-syntax.input.php';
    $generated = $temporary_root . '/php72-syntax.php';
    copy($input, $generated);

    $validator = new Php56SyntaxValidator();
    try {
        $validator->assertFile($generated);
        fail_test('The PHP 7.2 fixture unexpectedly passed the pre-downgrade syntax check.');
    } catch (RuntimeException $runtime_exception) {
        if (strpos($runtime_exception->getMessage(), 'return type declaration') === false) {
            throw $runtime_exception;
        }
    }

    run_rector($tool_root, $generated, true);
    $validator->assertFile($generated);

    $expected = $fixture_root . '/php72-syntax.expected.php';
    if (!is_file($expected)) {
        fail_test(sprintf('Missing expected Rector fixture %s.', $expected));
    }
    assert_same_file($expected, $generated);

    $input_output = run_php($input);
    $generated_output = run_php($generated);
    if ($input_output !== $generated_output) {
        fail_test(sprintf(
            "The generated fixture changed behavior.\nInput: %s\nGenerated: %s",
            $input_output,
            $generated_output
        ));
    }

    $unsupported = $temporary_root . '/unsupported-coalesce.php';
    copy($fixture_root . '/unsupported-coalesce.input.php', $unsupported);
    $unsupported_output = run_rector($tool_root, $unsupported, false);
    if (strpos($unsupported_output, 'Cannot safely downgrade the null-coalescing expression') === false) {
        fail_test("The unsupported null-coalescing fixture did not fail with the expected message.\n" . $unsupported_output);
    }

    echo "PHP 5.6 Rector fixtures passed.\n";
} finally {
    remove_tree($temporary_root);
}

function run_rector(string $tool_root, string $path, bool $must_succeed): string
{
    $command = escapeshellarg($tool_root . '/vendor/bin/rector')
        . ' process '
        . escapeshellarg($path)
        . ' --config '
        . escapeshellarg($tool_root . '/rector.php')
        . ' --no-progress-bar --clear-cache 2>&1';
    exec($command, $lines, $status);
    $output = implode("\n", $lines);
    if ($must_succeed && $status !== 0) {
        fail_test(sprintf("Rector failed with exit code %d.\n%s", $status, $output));
    }
    if (!$must_succeed && $status === 0) {
        fail_test('Rector unexpectedly accepted an unsafe null-coalescing expression.');
    }

    return $output;
}

function run_php(string $path): string
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1';
    exec($command, $lines, $status);
    $output = implode("\n", $lines);
    if ($status !== 0) {
        fail_test(sprintf("Fixture execution failed with exit code %d.\n%s", $status, $output));
    }

    return $output;
}

function assert_same_file(string $expected, string $actual): void
{
    $expected_contents = str_replace("\r\n", "\n", (string) file_get_contents($expected));
    $actual_contents = str_replace("\r\n", "\n", (string) file_get_contents($actual));
    if ($expected_contents === $actual_contents) {
        return;
    }

    fail_test(sprintf('Generated fixture does not match %s.', $expected));
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}
