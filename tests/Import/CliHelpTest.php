<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

class CliHelpTest extends TestCase
{
    private function runHelp(string ...$command): string
    {
        $entry = __DIR__ . '/../../importer/import.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($entry);
        foreach ($command as $word) {
            $cmd .= ' ' . escapeshellarg($word);
        }
        $cmd .= ' --help';
        return shell_exec($cmd . ' 2>&1') ?? '';
    }

    public function testPullFilesHelpShowsRequiredAndFileSelectionOptions(): void
    {
        $output = $this->runHelp('pull-files');

        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--filter=MODE', $output);
        $this->assertStringContainsString('--remap SOURCE TARGET', $output);
        $this->assertStringContainsString('--only=SOURCE', $output);
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

    public function testFilesPushHelpShowsOnlyItsCommandOptions(): void
    {
        $output = $this->runHelp('files-push');

        $this->assertStringContainsString('Usage: reprint files-push <target-url>', $output);
        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('--secret=TOKEN', $output);
        $this->assertStringContainsString('--force-http', $output);
        $this->assertStringContainsString('--verbose, -v', $output);
        $this->assertStringContainsString('low-level, files-only command', $output);
        $this->assertStringContainsString('existing local tree at --fs-root', $output);
        $this->assertStringContainsString('read or modify', $output);
        $this->assertStringNotContainsString('--abort', $output);
        $this->assertStringNotContainsString('--filter', $output);
        $this->assertStringNotContainsString('--remap', $output);
        $this->assertStringNotContainsString('--only', $output);
    }

    public function testFilesDiffHelpShowsOnlyItsLocalCommandOptions(): void
    {
        $output = $this->runHelp('files-diff');

        $this->assertStringContainsString('Usage: reprint files-diff <target-url>', $output);
        $this->assertStringContainsString('--state-dir=DIR', $output);
        $this->assertStringContainsString('--fs-root=DIR', $output);
        $this->assertStringContainsString('previous local index', $output);
        $this->assertStringContainsString('completed files-push', $output);
        $this->assertStringContainsString('push operation plan', $output);
        $this->assertStringContainsString('default-skipped paths', $output);
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
        $this->assertStringContainsString('Low-level commands:', $output);
        $this->assertStringNotContainsString('Low-level commands (used by pull internally):', $output);
        $this->assertStringNotContainsString('State is stored in --state-dir/.import-state.json', $output);
        $this->assertStringNotContainsString('Use --abort to abort the current', $output);
    }

    public function testMainHelpListsOnlyPreferredCommandSpellings(): void
    {
        $output = $this->runHelp('--help');

        foreach ([
            'source inspect',
            'source check',
            'files pull',
            'files stats',
            'database dump',
            'database index',
            'database domains',
            'database apply',
            'layout flatten',
            'runtime prepare',
            'status',
        ] as $command) {
            $this->assertMatchesRegularExpression(
                '/^  ' . preg_quote($command, '/') . '\s{2,}/m',
                $output,
            );
        }

        foreach ([
            'preflight',
            'preflight-assert',
            'files-pull',
            'files-sync',
            'files-stats',
            'db-pull',
            'db-sync',
            'db-index',
            'db-domains',
            'db-apply',
            'import-metadata',
            'flat-docroot',
            'flat-document-root',
            'flatten-docroot',
            'apply-runtime',
        ] as $command) {
            $this->assertDoesNotMatchRegularExpression(
                '/^  ' . preg_quote($command, '/') . '\s{2,}/m',
                $output,
            );
        }
    }

    public function testCommandGroupHelpListsItsPreferredActions(): void
    {
        $output = $this->runHelp('database');

        $this->assertStringContainsString('Usage: reprint database <command>', $output);
        $this->assertMatchesRegularExpression('/^  dump\s{2,}/m', $output);
        $this->assertMatchesRegularExpression('/^  index\s{2,}/m', $output);
        $this->assertMatchesRegularExpression('/^  domains\s{2,}/m', $output);
        $this->assertMatchesRegularExpression('/^  apply\s{2,}/m', $output);
        $this->assertStringNotContainsString('db-pull', $output);
        $this->assertStringNotContainsString('db-index', $output);
        $this->assertStringNotContainsString('db-apply', $output);
    }

    public function testPreferredCommandHelpDoesNotAdvertiseHiddenInvocations(): void
    {
        $output = '';
        foreach (self::preferredCommandHelpProvider() as [$command]) {
            $output .= $this->runHelp(...$command);
        }

        foreach ([
            'preflight',
            'preflight-assert',
            'files-pull',
            'files-sync',
            'files-stats',
            'db-pull',
            'db-sync',
            'db-index',
            'db-domains',
            'db-apply',
            'import-metadata',
            'flat-docroot',
            'flat-document-root',
            'flatten-docroot',
            'apply-runtime',
        ] as $hidden_command) {
            $this->assertDoesNotMatchRegularExpression(
                '/\breprint\s+' . preg_quote($hidden_command, '/') . '(?:\s|$)/',
                $output,
            );
        }
    }

    /**
     * @dataProvider preferredCommandHelpProvider
     */
    public function testPreferredCommandHelpRetainsCanonicalOptions(array $command, string $usage, string $option): void
    {
        $output = $this->runHelp(...$command);

        $this->assertStringContainsString("Usage: {$usage}", $output);
        $this->assertStringContainsString($option, $output);
    }

    public static function preferredCommandHelpProvider(): array
    {
        return [
            'source inspect' => [['source', 'inspect'], 'reprint source inspect', '--secret=TOKEN'],
            'source check' => [['source', 'check'], 'reprint source check', '--cached'],
            'files pull' => [['files', 'pull'], 'reprint files pull', '--only=SOURCE'],
            'files stats' => [['files', 'stats'], 'reprint files stats', '--fs-root=DIR'],
            'database dump' => [['database', 'dump'], 'reprint database dump', '--sql-output=MODE'],
            'database index' => [['database', 'index'], 'reprint database index', '--secret=TOKEN'],
            'database domains' => [['database', 'domains'], 'reprint database domains', '--state-dir=DIR'],
            'database apply' => [['database', 'apply'], 'reprint database apply', '--target-engine=ENGINE'],
            'layout flatten' => [['layout', 'flatten'], 'reprint layout flatten', '--flatten-to=PATH'],
            'runtime prepare' => [['runtime', 'prepare'], 'reprint runtime prepare', '--runtime=RUNTIME'],
            'status' => [['status'], 'reprint status', '--porcelain=VERSION'],
        ];
    }

    /**
     * @dataProvider hiddenCommandHelpProvider
     */
    public function testHiddenCommandHelpUsesPreferredSpelling(string $hidden, string $preferred_usage): void
    {
        $output = $this->runHelp($hidden);

        $first_line = strtok($output, "\n");
        $this->assertStringStartsWith("Usage: {$preferred_usage}", $first_line);
        $this->assertStringNotContainsString("reprint {$hidden}", $first_line);
    }

    public static function hiddenCommandHelpProvider(): array
    {
        return [
            'preflight' => ['preflight', 'reprint source inspect'],
            'preflight-assert' => ['preflight-assert', 'reprint source check'],
            'files-pull' => ['files-pull', 'reprint files pull'],
            'files-sync' => ['files-sync', 'reprint files pull'],
            'files-stats' => ['files-stats', 'reprint files stats'],
            'db-pull' => ['db-pull', 'reprint database dump'],
            'db-sync' => ['db-sync', 'reprint database dump'],
            'db-index' => ['db-index', 'reprint database index'],
            'db-domains' => ['db-domains', 'reprint database domains'],
            'db-apply' => ['db-apply', 'reprint database apply'],
            'import-metadata' => ['import-metadata', 'reprint status'],
            'flat-docroot' => ['flat-docroot', 'reprint layout flatten'],
            'flat-document-root' => ['flat-document-root', 'reprint layout flatten'],
            'flatten-docroot' => ['flatten-docroot', 'reprint layout flatten'],
            'apply-runtime' => ['apply-runtime', 'reprint runtime prepare'],
        ];
    }
}
