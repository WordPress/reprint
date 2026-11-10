<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class FilesListTest extends TestCase
{
    public function testFilesListFromSavedIndex(): void
    {
        $state_directory = sys_get_temp_dir() . '/reprint-files-list-' . uniqid('', true);
        mkdir($state_directory, 0755, true);
        $remote_url = 'https://example.invalid';
        $pull_directory = $state_directory . '/remotes/' . md5($remote_url) . '/pull';
        $content_directory = '/srv/site/wp-content';
        $plugins = [
            ['name' => 'akismet', 'type' => 'dir', 'size' => 30, 'files' => 2],
            ['name' => 'index.php', 'type' => 'file', 'size' => 4],
        ];
        $root = [
            ['name' => 'empty', 'type' => 'dir', 'size' => 0, 'files' => 0],
            ['name' => 'linked', 'type' => 'link', 'size' => 0],
            ['name' => 'plugins', 'type' => 'dir', 'size' => 34, 'files' => 3],
        ];
        // Both index choices, implicit directories, duplicate replacement, and
        // invalid directory selections exercise the real CLI without credentials.
        $cases = [
            [null, 'next', $root, null],
            [':wp-content:/plugins', 'next', $plugins, null],
            [$content_directory . '/plugins', 'retained', $plugins, null],
            [':wp-content:/empty', 'next', [], null],
            [':wp-content:/plugins/index.php', 'next', null, 'does not name a directory'],
            [':wp-content:/missing', 'next', null, 'does not name a directory'],
            ['/outside', 'next', null, 'outside the indexed roots'],
            [':wp-content:', 'none', null, 'run files-index first'],
        ];
        try {
            foreach ($cases as [$path, $index_choice, $expected_entries, $error]) {
                \write_current_pull_state(new \ImportClient($remote_url, $state_directory, $state_directory), [
                    'preflight' => ['data' => [
                        'database' => ['wp' => ['paths_urls' => ['content_dir' => $content_directory]]],
                        'wp_detect' => ['roots' => [['path' => '/srv/site']]],
                    ]],
                ]);
                foreach (['remote-index.next.jsonl', 'remote-index.jsonl'] as $index_name) {
                    if (is_file($pull_directory . '/' . $index_name)) {
                        unlink($pull_directory . '/' . $index_name);
                    }
                }
                $lines = [];
                foreach ([
                    ['/plugins/akismet/main.php', 'file', 100],
                    ['/empty', 'dir', 0],
                    ['/plugins/index.php', 'file', 4],
                    ['/plugins/akismet/sub/readme.txt', 'file', 20],
                    ['/linked', 'link', 0],
                    ['/plugins/akismet/main.php', 'file', 10],
                ] as [$suffix, $type, $size]) {
                    $lines[] = json_encode(['path' => base64_encode($content_directory . $suffix), 'type' => $type, 'size' => $size, 'ctime' => 1]);
                }
                if ($index_choice !== 'none') {
                    $index_name = $index_choice === 'next' ? 'remote-index.next.jsonl' : 'remote-index.jsonl';
                    file_put_contents($pull_directory . '/' . $index_name, implode("\n", $lines) . "\n");
                    if ($index_choice === 'next') {
                        file_put_contents($pull_directory . '/remote-index.jsonl', '');
                    }
                }
                $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../packages/reprint-client/bin/reprint-client')
                    . ' files-list ' . escapeshellarg($remote_url) . ' --state-dir=' . escapeshellarg($state_directory);
                if ($path !== null) {
                    $command .= ' --path=' . escapeshellarg($path);
                }
                $output = [];
                $stderr_file = $state_directory . '/stderr';
                exec($command . ' 2>' . escapeshellarg($stderr_file), $output, $exit_code);
                $output = implode("\n", $output);
                if ($error !== null) {
                    $this->assertNotSame(0, $exit_code, $output);
                    $this->assertStringContainsString($error, file_get_contents($stderr_file));
                } else {
                    $this->assertSame(0, $exit_code, $output);
                    $this->assertSame(['path' => $path ?? ':wp-content:', 'entries' => $expected_entries], json_decode($output, true), $output);
                }
            }
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($state_directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($state_directory);
        }
    }
}
