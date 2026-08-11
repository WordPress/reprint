<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The recording application belongs to this test fixture.

namespace ImportTests;

use Reprint\Importer\Cli\CliArgumentParser;
use Reprint\Importer\Cli\CliCommandRegistry;
use Reprint\Importer\Cli\CliHelpRenderer;
use Reprint\Importer\Cli\CliInvocation;
use Reprint\Importer\Cli\CliOutput;
use Reprint\Importer\Cli\ImporterCliApplication;
use Reprint\Importer\Cli\ImporterVersionProvider;
use RuntimeException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class ImporterCliApplicationTest extends TestCase {

    /** @var resource */
    private $standardOutput;

    /** @var resource */
    private $standardError;

    protected function setUp(): void
    {
        parent::setUp();
        $this->standardOutput = fopen('php://temp', 'w+');
        $this->standardError  = fopen('php://temp', 'w+');
        $this->assertIsResource($this->standardOutput);
        $this->assertIsResource($this->standardError);
    }

    protected function tearDown(): void
    {
        fclose($this->standardOutput);
        fclose($this->standardError);
        parent::tearDown();
    }

    public function testValidatedInvocationIsTheOnlyInputPassedToBusinessExecution(): void
    {
        $application = $this->createApplication();
        $application->exit_code = 2;

        $exitCode = $application->run([
            'reprint',
            'files-sync',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
            '--fs-root=/tmp/reprint-files',
            '--include=:wp-content:',
            '--include',
            ':wp-uploads:/2026',
            '--exclude=:wp-content:/cache',
            '--progress=jsonl',
            '--index-batch-max=9000',
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $application->run_count);
        $invocation = $application->last_invocation;
        $this->assertInstanceOf(CliInvocation::class, $invocation);
        $this->assertSame('files-pull', $invocation->command);
        $this->assertSame('https://example.test', $invocation->remote_reprint_api_url);
        $this->assertSame('/tmp/reprint-state', $invocation->state_directory);
        $this->assertSame('/tmp/reprint-files', $invocation->filesystem_root);
        $this->assertSame(
            [':wp-content:', ':wp-uploads:/2026'],
            $invocation->options['include']
        );
        $this->assertSame([':wp-content:/cache'], $invocation->options['exclude']);
        $this->assertSame('jsonl', $invocation->options['progress']);
        $this->assertSame(9000, $invocation->options['tuning_config']['index_batch_max']);
        $this->assertSame('', $this->readStream($this->standardOutput));
        $this->assertSame('', $this->readStream($this->standardError));
    }

    public function testInvalidInputNeverReachesBusinessExecution(): void
    {
        $application = $this->createApplication();

        $exitCode = $application->run([
            'reprint',
            'files-push',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
            '--fs-root=/tmp/reprint-files',
            '--secret=token',
            '--docroot=/tmp/other-files',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $application->run_count);
        $this->assertSame('', $this->readStream($this->standardOutput));
        $this->assertSame(
            "Error: files-push does not accept --docroot.\n",
            $this->readStream($this->standardError)
        );
    }

    public function testCommandRejectsAnOptionDeclaredByAnotherCommand(): void
    {
        $application = $this->createApplication();

        $exitCode = $application->run([
            'reprint',
            'files-pull',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
            '--fs-root=/tmp/reprint-files',
            '--port=9000',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $application->run_count);
        $this->assertSame(
            "Error: files-pull does not accept --port.\n",
            $this->readStream($this->standardError)
        );
    }

    public function testCommandAcceptsAndCastsItsOwnOption(): void
    {
        $application = $this->createApplication();

        $exitCode = $application->run([
            'reprint',
            'apply-runtime',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
            '--fs-root=/tmp/reprint-files',
            '--port=9000',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $application->run_count);
        $invocation = $application->last_invocation;
        $this->assertInstanceOf(CliInvocation::class, $invocation);
        $this->assertSame('apply-runtime', $invocation->command);
        $this->assertSame(9000, $invocation->options['port']);
    }

    public function testLocalCommandUsesRemoteUrlToSelectState(): void
    {
        $application = $this->createApplication();

        $exitCode = $application->run([
            'reprint',
            'import-metadata',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
        ]);

        $this->assertSame(0, $exitCode);
        $invocation = $application->last_invocation;
        $this->assertInstanceOf(CliInvocation::class, $invocation);
        $this->assertSame('https://example.test', $invocation->remote_reprint_api_url);
        $this->assertSame('/tmp/reprint-state', $invocation->filesystem_root);
    }

    public function testLocalCommandRequiresRemoteUrlToSelectState(): void
    {
        $application = $this->createApplication();

        $exitCode = $application->run([
            'reprint',
            'import-metadata',
            '--state-dir=/tmp/reprint-state',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(0, $application->run_count);
        $this->assertSame(
            "Error: <remote-reprint-api-url> is required\n"
                . "Usage: reprint pull-metadata <remote-reprint-api-url> --state-dir=DIR\n",
            $this->readStream($this->standardError)
        );
    }

    public function testExecutionFailureIsRenderedWithoutLeakingIntoInputHandling(): void
    {
        $application = $this->createApplication();
        $application->error                = new RuntimeException('Operation failed.');
        $application->execution_error_code = 'OPERATION_FAILED';

        $exitCode = $application->run([
            'reprint',
            'import-metadata',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
        ]);

        $this->assertSame(1, $exitCode);
        $error = json_decode(trim($this->readStream($this->standardError)), true);
        $this->assertIsArray($error);
        $this->assertSame('Operation failed.', $error['error']);
        $this->assertSame('OPERATION_FAILED', $error['error_code']);
        $this->assertSame(RuntimeException::class, $error['exception']);
    }

    public function testStructuredFailureOutputRemainsValidJsonForInvalidUtf8(): void
    {
        $application = $this->createApplication();
        $application->error = new RuntimeException("Invalid byte: \xff");

        $exitCode = $application->run([
            'reprint',
            'import-metadata',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
        ]);

        $this->assertSame(1, $exitCode);
        $error = json_decode(
            trim($this->readStream($this->standardError)),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($error);
        $this->assertSame(RuntimeException::class, $error['exception']);
        $this->assertStringStartsWith('Invalid byte: ', $error['error']);
    }

    public function testEmbeddingCallerCanReceiveTheOriginalExecutionFailure(): void
    {
        $failure = new RuntimeException('Embedding caller failure.');
        $application = $this->createApplication();
        $application->error = $failure;

        try {
            $application->run([
                'reprint',
                'import-metadata',
                'https://example.test',
                '--state-dir=/tmp/reprint-state',
            ], true);
            $this->fail('The original execution failure was not rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($failure, $caught);
        }

        $error = json_decode(
            trim($this->readStream($this->standardError)),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('Embedding caller failure.', $error['error']);
    }

    public function testFilesDiffTtyExecutionFailureHasNoLeadingBlankLine(): void
    {
        $application = $this->createApplication();
        $application->error = new RuntimeException('Diff failed.');

        $exitCode = $application->run([
            'reprint',
            'files-diff',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
            '--fs-root=/tmp/reprint-files',
            '--progress=tty',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame("Error: Diff failed.\n", $this->readStream($this->standardError));
    }

    public function testVerboseExecutionFailureKeepsStructuredOutputWithTtyProgress(): void
    {
        $application = $this->createApplication();
        $application->error = new RuntimeException('Push failed.');

        $exitCode = $application->run([
            'reprint',
            'files-push',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
            '--fs-root=/tmp/reprint-files',
            '--secret=token',
            '--progress=tty',
            '--verbose',
        ]);

        $this->assertSame(1, $exitCode);
        $error = json_decode(trim($this->readStream($this->standardError)), true);
        $this->assertIsArray($error);
        $this->assertSame('Push failed.', $error['error']);
    }

    public function testAutoProgressUsesStandardOutputTerminalForRegularCommands(): void
    {
        $output = new CliTerminalOutput(
            $this->standardOutput,
            $this->standardError,
            true,
            false
        );
        $application = $this->createApplication($output);
        $application->error = new RuntimeException('Metadata failed.');

        $exitCode = $application->run([
            'reprint',
            'pull-metadata',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame("\nError: Metadata failed.\n", $this->readStream($this->standardError));
    }

    public function testAutoProgressUsesStandardErrorTerminalForSqlOutput(): void
    {
        $output = new CliTerminalOutput(
            $this->standardOutput,
            $this->standardError,
            true,
            false
        );
        $application = $this->createApplication($output);
        $application->error = new RuntimeException('Database pull failed.');

        $exitCode = $application->run([
            'reprint',
            'db-pull',
            'https://example.test',
            '--state-dir=/tmp/reprint-state',
            '--fs-root=/tmp/reprint-files',
            '--secret=token',
            '--sql-output=stdout',
        ]);

        $this->assertSame(1, $exitCode);
        $error = json_decode(trim($this->readStream($this->standardError)), true);
        $this->assertIsArray($error);
        $this->assertSame('Database pull failed.', $error['error']);
    }

    private function createApplication(?CliOutput $output = null): CliRecordingImporterApplication
    {
        $commandRegistry = CliCommandRegistry::create_default();
        $versionProvider = new ImporterVersionProvider(
            __DIR__ . '/../../packages/reprint-client/src'
        );
        return new CliRecordingImporterApplication(
            $commandRegistry,
            new CliArgumentParser(),
            new CliHelpRenderer($commandRegistry, $versionProvider),
            $versionProvider,
            $output ?? new CliOutput($this->standardOutput, $this->standardError)
        );
    }

    /**
     * @param resource $stream Open temporary stream.
     */
    private function readStream($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }
}

final class CliTerminalOutput extends CliOutput {

    private bool $standardOutputIsTerminal;

    private bool $standardErrorIsTerminal;

    /**
     * @param resource $standardOutput Standard output stream.
     * @param resource $standardError Standard error stream.
     */
    public function __construct(
        $standardOutput,
        $standardError,
        bool $standardOutputIsTerminal,
        bool $standardErrorIsTerminal
    ) {
        parent::__construct($standardOutput, $standardError);
        $this->standardOutputIsTerminal = $standardOutputIsTerminal;
        $this->standardErrorIsTerminal  = $standardErrorIsTerminal;
    }

    public function standard_output_is_terminal(): bool
    {
        return $this->standardOutputIsTerminal;
    }

    public function standard_error_is_terminal(): bool
    {
        return $this->standardErrorIsTerminal;
    }
}

final class CliRecordingImporterApplication extends ImporterCliApplication {

    public int $run_count = 0;

    public int $exit_code = 0;

    public ?CliInvocation $last_invocation = null;

    public ?RuntimeException $error = null;

    public ?string $execution_error_code = null;

    protected function execute_invocation(CliInvocation $invocation): int
    {
        $this->last_error_code = $this->execution_error_code;
        ++$this->run_count;
        $this->last_invocation = $invocation;
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->exit_code;
    }
}
