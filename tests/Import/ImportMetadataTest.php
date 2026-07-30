<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../importer/import.php';

class ImportMetadataTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;

    /**
     * Creates an isolated state directory for each metadata scenario.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-import-metadata-' . uniqid('', true);
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        mkdir($this->stateDir, 0755, true);
        mkdir($this->fsRoot, 0755, true);
    }

    /**
     * Removes the temporary state and filesystem roots created for the test.
     */
    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    /**
     * Deletes a directory tree while preserving symlink boundaries.
     */
    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Writes importer state directly so each test can model one lifecycle shape.
     */
    private function writeState(array $state): void
    {
        file_put_contents(
            $this->stateDir . '/.import-state.json',
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Runs the metadata command and returns its decoded JSON response.
     */
    private function readMetadata(): array
    {
        $client = new \ImportClient(
            'http://example.invalid',
            $this->stateDir,
            $this->fsRoot,
            'import-metadata'
        );

        ob_start();
        $client->run(['command' => 'import-metadata']);
        $output = ob_get_clean();

        $metadata = json_decode($output, true);
        $this->assertIsArray($metadata, $output);

        return $metadata;
    }

    /**
     * Verifies a missing state file is reported as a never-completed pull.
     */
    public function testImportMetadataReportsNoCompletedPullForFreshState(): void
    {
        $metadata = $this->readMetadata();

        $this->assertFalse($metadata['hasCompletedOnce']);
        $this->assertFileDoesNotExist($this->stateDir . '/.import-state.json');
        $this->assertNull($metadata['pullStage']);
    }

    /**
     * Verifies completed low-level commands do not imply a completed pull.
     */
    public function testImportMetadataDoesNotTreatCompletedSubcommandAsCompletedPull(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'command_name' => 'files-pull',
                'completion_state' => 'complete',
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertFalse($metadata['hasCompletedOnce']);
        $this->assertNull($metadata['pullStage']);
    }

    /**
     * Verifies a completed pull pipeline reports completion.
     */
    public function testImportMetadataReportsCompletedPullState(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'completion_state' => 'complete',
            ],
            'pull_pipeline' => [
                'stage_sequence' => ['preflight', 'files-pull', 'db-pull', 'db-apply'],
                'last_completed_stage' => 'db-apply',
                'files_filter' => 'essential-files',
                'skipped_pending' => true,
                'has_completed_once' => true,
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertTrue($metadata['hasCompletedOnce']);
        $this->assertSame('db-apply', $metadata['pullStage']);
    }

    public function testImportMetadataReadsTheOnlyRelationshipPullState(): void
    {
        $this->writeState([
            'pull_pipeline' => [
                'stage_sequence' => ['preflight', 'files-pull'],
                'last_completed_stage' => 'files-pull',
                'has_completed_once' => true,
            ],
        ]);
        $remoteStateDirectory = $this->stateDir . '/remote-one';
        mkdir($remoteStateDirectory);
        rename(
            $this->stateDir . '/.import-state.json',
            $remoteStateDirectory . '/pull-state.json'
        );

        $metadata = $this->readMetadata();

        $this->assertTrue($metadata['hasCompletedOnce']);
        $this->assertSame('files-pull', $metadata['pullStage']);
    }

    /**
     * Verifies delta re-pull state preserves that a pull completed previously.
     */
    public function testImportMetadataReportsPriorCompletionDuringRepull(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'completion_state' => null,
            ],
            'pull_pipeline' => [
                'last_completed_stage' => null,
                'files_filter' => null,
                'skipped_pending' => false,
                'has_completed_once' => true,
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertTrue($metadata['hasCompletedOnce']);
        $this->assertNull($metadata['pullStage']);
    }
}
