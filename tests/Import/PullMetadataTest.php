<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/bin/reprint-client';

class PullMetadataTest extends TestCase
{
    private string $tempDir;
    private string $stateDir;
    private string $fsRoot;
    private string $pullStateDirectory;

    /**
     * Creates an isolated state directory for each metadata scenario.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/reprint-pull-metadata-' . uniqid('', true);
        $this->stateDir = $this->tempDir . '/state';
        $this->fsRoot = $this->tempDir . '/fs-root';
        $this->pullStateDirectory =
            $this->stateDir
            . '/remotes/'
            . md5('http://example.invalid')
            . '/pull';
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
     * Writes pull state directly so each test can model one lifecycle shape.
     */
    private function writeState(
        array $state,
        string $remoteReprintApiUrl = 'http://example.invalid'
    ): void
    {
        \write_current_pull_state(
            new \ImportClient($remoteReprintApiUrl, $this->stateDir, $this->fsRoot),
            $state
        );
    }

    /**
     * Runs the metadata command and returns its decoded JSON response.
     */
    private function readMetadata(
        string $command = 'pull-metadata',
        string $remoteReprintApiUrl = 'http://example.invalid'
    ): array
    {
        $client = new \ImportClient($remoteReprintApiUrl, $this->stateDir, $this->fsRoot);

        ob_start();
        $client->run(['command' => $command]);
        $output = ob_get_clean();

        $metadata = json_decode($output, true);
        $this->assertIsArray($metadata, $output);

        return $metadata;
    }

    /**
     * Verifies the previous command name remains an alias for pull metadata.
     */
    public function testImportMetadataAliasReportsPullMetadata(): void
    {
        $this->assertSame(
            $this->readMetadata(),
            $this->readMetadata('import-metadata')
        );
    }

    /**
     * Verifies a missing state file is reported as a never-completed pull.
     */
    public function testPullMetadataReportsNoCompletedPullForFreshState(): void
    {
        $metadata = $this->readMetadata();

        $this->assertFalse($metadata['hasCompletedOnce']);
        $this->assertFileDoesNotExist($this->pullStateDirectory . '/state.json');
        $this->assertNull($metadata['pullStage']);
        $this->assertSame([
            'homeUrl' => null,
            'siteUrl' => null,
            'tablePrefix' => null,
            'wordpressDatabaseCharset' => null,
            'serverDatabaseCharset' => null,
        ], $metadata['sourceSite']);
    }

    /**
     * Verifies source-site values are exposed without leaking the preflight schema.
     */
    public function testPullMetadataReportsSourceSiteFields(): void
    {
        $this->writeState([
            'preflight' => [
                'data' => [
                    'database' => [
                        'server_charset' => 'latin1',
                        'wp' => [
                            'home' => 'https://example.com',
                            'siteurl' => 'https://example.com/wordpress',
                            'table_prefix' => 'wp_4_',
                            'wpdb_charset' => 'utf8mb3',
                        ],
                    ],
                ],
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertSame([
            'homeUrl' => 'https://example.com',
            'siteUrl' => 'https://example.com/wordpress',
            'tablePrefix' => 'wp_4_',
            'wordpressDatabaseCharset' => 'utf8mb3',
            'serverDatabaseCharset' => 'latin1',
        ], $metadata['sourceSite']);
    }

    /**
     * Verifies completed low-level commands do not imply a completed pull.
     */
    public function testPullMetadataDoesNotTreatCompletedSubcommandAsCompletedPull(): void
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
    public function testPullMetadataReportsCompletedPullState(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'completion_state' => 'complete',
            ],
            'pull_pipeline' => [
                'stage_sequence' => ['preflight', 'files-pull', 'db-pull', 'db-apply'],
                'last_completed_stage' => 'db-apply',
                'has_completed_once' => true,
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertTrue($metadata['hasCompletedOnce']);
        $this->assertSame('db-apply', $metadata['pullStage']);
    }

    /**
     * Verifies delta re-pull state preserves that a pull completed previously.
     */
    public function testPullMetadataReportsPriorCompletionDuringRepull(): void
    {
        $this->writeState([
            'active_resumable_command' => [
                'completion_state' => null,
            ],
            'pull_pipeline' => [
                'last_completed_stage' => null,
                'has_completed_once' => true,
            ],
        ]);

        $metadata = $this->readMetadata();

        $this->assertTrue($metadata['hasCompletedOnce']);
        $this->assertNull($metadata['pullStage']);
    }

    /**
     * Verifies each remote Reprint API URL reads only its own pull state.
     */
    public function testPullMetadataReadsTheSelectedRemoteStateDirectory(): void
    {
        $firstRemoteReprintApiUrl = 'https://first.example/?reprint-api';
        $secondRemoteReprintApiUrl = 'https://second.example/?reprint-api';
        $this->writeState([
            'pull_pipeline' => [
                'last_completed_stage' => 'files-pull',
            ],
        ], $firstRemoteReprintApiUrl);
        $this->writeState([
            'pull_pipeline' => [
                'last_completed_stage' => 'db-apply',
            ],
        ], $secondRemoteReprintApiUrl);

        $this->assertSame(
            'files-pull',
            $this->readMetadata('pull-metadata', $firstRemoteReprintApiUrl)['pullStage']
        );
        $this->assertSame(
            'db-apply',
            $this->readMetadata('pull-metadata', $secondRemoteReprintApiUrl)['pullStage']
        );
    }
}
