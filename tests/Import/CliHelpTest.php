<?php

namespace ImportTests;

use ImportClient;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

class CliHelpTest extends TestCase
{
    private function runHelp(string $command): string
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry) . ' ' . escapeshellarg($command) . ' --help';
        return shell_exec($cmd . ' 2>&1') ?? '';
    }

    public function testPullFilesHelpShowsRequiredAndFileSelectionOptions(): void
    {
        $output = $this->runHelp('pull-files');

        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--remap SOURCE TARGET', $output);
        $this->assertStringContainsString('--include=SOURCE', $output);
        $this->assertStringNotContainsString('--only', $output);
        $this->assertStringContainsString('--exclude=SOURCE', $output);
    }

    public function testFilesIndexHelpNamesTheNextRemoteIndexFile(): void
    {
        $output = $this->runHelp('files-index');

        $this->assertStringContainsString('pull/remote-index.next.jsonl', $output);
    }

    public function testFilesPullHelpNamesTheRemoteIndexFiles(): void
    {
        $output = $this->runHelp('files-pull');

        $this->assertStringContainsString('pull/remote-index.jsonl', $output);
        $this->assertStringContainsString('pull/remote-index.next.jsonl', $output);
        $this->assertStringContainsString('local_index.jsonl', $output);
        $this->assertStringContainsString(
            'completed pull mutations',
            $output
        );
        $this->assertStringContainsString('Remote index', $output);
        $this->assertStringContainsString('Next remote index', $output);
    }

    public function testFilterOptionIsHiddenFromCommandHelp(): void
    {
        foreach (array('pull', 'pull-files', 'files-pull') as $command) {
            $this->assertStringNotContainsString(
                '--filter',
                $this->runHelp($command),
                $command
            );
        }
    }

    public function testPullDbHelpShowsRequiredAndDatabaseOptions(): void
    {
        $output = $this->runHelp('pull-db');

        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--max-allowed-packet=SIZE', $output);
        $this->assertStringContainsString('--target-engine=ENGINE', $output);
        $this->assertStringContainsString('--new-site-url=URL', $output);
    }

    public function testDatabaseUrlRewriteHelpDescribesTheBoundedLiveDatabaseOperation(): void
    {
        $output = $this->runHelp('db-rewrite-urls');

        $this->assertStringContainsString('live MySQL or SQLite database', $output);
        $this->assertStringContainsString('--rewrite-url FROM TO', $output);
        $this->assertStringContainsString('--target-engine=ENGINE', $output);
        $this->assertStringContainsString('--abort', $output);
        $this->assertStringContainsString('one primary-keyed record at a time', $output);
        $this->assertStringContainsString('Resumes from the last saved record cursor', $output);
        $this->assertStringContainsString(
            'Usage: reprint db-rewrite-urls [<remote-reprint-api-url>] --state-dir=DIR',
            $output
        );
        $this->assertStringContainsString('database recorded by db-apply', $output);
        $this->assertStringNotContainsString('--fs-root=DIR', $output);
    }

    public function testDatabaseUrlRewriteRequiresAUrlToChooseAmongSavedRemotes(): void
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $state_directory =
            sys_get_temp_dir() . '/db-rewrite-multiple-remotes-' . uniqid('', true);
        foreach (['first', 'second'] as $remote_directory) {
            $pull_directory =
                $state_directory . '/remotes/' . $remote_directory . '/pull';
            mkdir($pull_directory, 0755, true);
            file_put_contents($pull_directory . '/state.json', '{}');
        }
        $command =
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($entry)
            . ' db-rewrite-urls --state-dir='
            . escapeshellarg($state_directory)
            . ' --rewrite-url https://old.example https://new.example 2>&1';

        try {
            $output = shell_exec($command) ?? '';
            $this->assertStringContainsString(
                '--state-dir contains more than one saved remote',
                $output
            );
        } finally {
            foreach (['first', 'second'] as $remote_directory) {
                unlink(
                    $state_directory . '/remotes/' . $remote_directory . '/pull/state.json'
                );
                rmdir($state_directory . '/remotes/' . $remote_directory . '/pull');
                rmdir($state_directory . '/remotes/' . $remote_directory);
            }
            rmdir($state_directory . '/remotes');
            rmdir($state_directory);
        }
    }

    public function testDatabaseUrlRewriteRejectsAnUnusedFilesystemRoot(): void
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $state_directory =
            sys_get_temp_dir() . '/db-rewrite-fs-root-' . uniqid('', true);
        $command =
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($entry)
            . ' db-rewrite-urls --state-dir='
            . escapeshellarg($state_directory)
            . ' --fs-root='
            . escapeshellarg($state_directory . '/files')
            . ' 2>&1';
        $output = shell_exec($command) ?? '';

        $this->assertStringContainsString(
            'db-rewrite-urls does not accept --fs-root',
            $output
        );
        $this->assertDirectoryDoesNotExist($state_directory);
    }

    public function testImportMetadataAliasShowsPullMetadataHelp(): void
    {
        $output = $this->runHelp('import-metadata');

        $this->assertStringContainsString(
            'Usage: reprint pull-metadata <remote-reprint-api-url> --state-dir=DIR',
            $output
        );
    }

    public function testApplyRuntimeHelpRequiresTheRemoteReprintApiUrlWhichSelectsState(): void
    {
        $output = $this->runHelp('apply-runtime');

        $this->assertStringContainsString(
            'Usage: reprint apply-runtime <remote-reprint-api-url>',
            $output
        );
        $this->assertStringContainsString('no network calls are made', $output);
    }

    public function testApplyRuntimeHelpListsTheDatabaseTargetOptions(): void
    {
        $output = $this->runHelp('apply-runtime');

        $this->assertStringContainsString('--target-engine=ENGINE', $output);
        $this->assertStringContainsString('--target-sqlite-path=PATH', $output);
        $this->assertStringContainsString('--target-db=NAME', $output);
        $this->assertStringContainsString('--target-host=HOST', $output);
        $this->assertStringContainsString('--target-port=PORT', $output);
        $this->assertStringContainsString('--target-user=USER', $output);
        $this->assertStringContainsString('--target-pass=PASS', $output);
    }

    public function testPullMetadataRejectsAnInvocationWithoutARemoteReprintApiUrl(): void
    {
        $entry = __DIR__ . '/../../packages/reprint-client/bin/reprint-client';
        $stateDirectory =
            sys_get_temp_dir() . '/pull-metadata-missing-remote-' . uniqid('', true);
        $command =
            escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($entry)
            . ' pull-metadata --state-dir='
            . escapeshellarg($stateDirectory)
            . ' 2>&1';
        $output = shell_exec($command) ?? '';

        $this->assertStringContainsString(
            'Error: <remote-reprint-api-url> is required',
            $output
        );
        $this->assertDirectoryDoesNotExist($stateDirectory);
    }

    public function testFilesPushHelpShowsOnlyItsCommandOptions(): void
    {
        $output = $this->runHelp('files-push');

        $this->assertStringContainsString('Usage: reprint files-push <remote-reprint-api-url>', $output);
        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--secret=TOKEN', $output);
        $this->assertStringContainsString('--force-http', $output);
        $this->assertStringContainsString('--progress=MODE', $output);
        $this->assertStringContainsString('auto, tty, or jsonl', $output);
        $this->assertStringContainsString('--verbose, -v', $output);
        $this->assertStringContainsString('low-level, files-only command', $output);
        $this->assertStringContainsString("document root's local tree beneath --fs-root", $output);
        $this->assertStringContainsString('requires saved preflight data', $output);
        $this->assertStringContainsString('read or modify', $output);
        $this->assertStringNotContainsString('--abort', $output);
        $this->assertStringNotContainsString('--filter', $output);
        $this->assertStringNotContainsString('--remap', $output);
        $this->assertStringNotContainsString('--only', $output);
    }

    public function testFilesDiffHelpShowsOnlyItsLocalCommandOptions(): void
    {
        $output = $this->runHelp('files-diff');

        $this->assertStringContainsString('Usage: reprint files-diff <remote-reprint-api-url>', $output);
        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--progress=MODE', $output);
        $this->assertStringContainsString('auto|tty|jsonl', $output);
        $this->assertStringNotContainsString('--jsonl', $output);
        $this->assertStringContainsString('local index', $output);
        $this->assertStringContainsString('files-pull advances', $output);
        $this->assertStringContainsString('target confirms', $output);
        $this->assertStringContainsString('push operation plan', $output);
        $this->assertStringContainsString('default-skipped paths', $output);
        $this->assertStringContainsString('With --progress=auto', $output);
        $this->assertStringContainsString('red status lines', $output);
        $this->assertStringContainsString('redirected stdout gets JSONL', $output);
        $this->assertStringContainsString('No network calls', $output);
        $this->assertStringContainsString('complete diff from the beginning', $output);
        $this->assertStringNotContainsString('--runtime', $output);
        $this->assertStringNotContainsString('--secret', $output);
        $this->assertStringNotContainsString('--force-http', $output);
        $this->assertStringNotContainsString('--filter', $output);
        $this->assertStringNotContainsString('--remap', $output);
        $this->assertStringNotContainsString('--only', $output);
    }

    public function testMainHelpDescribesFilesPushWithoutApplyingPullOnlyContractsGlobally(): void
    {
        $output = $this->runHelp('--help');

        $this->assertStringContainsString('files-push', $output);
        $this->assertStringContainsString('files-diff', $output);
        $this->assertStringContainsString('--progress=MODE', $output);
        $this->assertStringContainsString('Low-level commands:', $output);
        $this->assertStringNotContainsString('Low-level commands (used by pull internally):', $output);
        $this->assertStringNotContainsString('State is stored in --state-dir/pull/state.json', $output);
        $this->assertStringNotContainsString('Use --abort to abort the current', $output);
    }

    public function testProgressOutputModeAppearsInEveryCommandHelp(): void
    {
        foreach (ImportClient::COMMANDS as $command) {
            $this->assertStringContainsString(
                '--progress=MODE',
                $this->runHelp($command),
                $command
            );
        }
    }
}
