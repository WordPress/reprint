<?php

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Import tests place class braces on the following line.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Tests share this namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/import.php';

final class RemoteIndexTraversalJournalTest extends TestCase
{
    private $tempDir;
    private $journalPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/remote-index-journal-' . uniqid();
        mkdir($this->tempDir);
        $this->journalPath = $this->tempDir . '/traversals.jsonl';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
        parent::tearDown();
    }

    public function testCompletionStoresOnlyReplayFieldsAndKeepsPathsByteSafe(): void
    {
        $listDirectory = "/remote/\x80-target";
        $requestedDirectories = [$listDirectory, '/remote/plain'];
        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset(0);
        $durableByteOffset = $journal->complete_traversal(
            17,
            43,
            $listDirectory,
            $requestedDirectories
        );

        $this->assertTrue(
            $journal->covers_canonical_path(
                $listDirectory . '/nested',
                $durableByteOffset
            )
        );
        $this->assertFalse(
            $journal->covers_canonical_path(
                $listDirectory . '-old/nested',
                $durableByteOffset
            )
        );
        $journal->close();

        $rawJournal = file_get_contents($this->journalPath);
        $this->assertIsString($rawJournal);
        $this->assertStringNotContainsString("\x80", $rawJournal);
        $record = json_decode(trim($rawJournal), true);
        $this->assertSame(
            [
                'type' => 'traversal_complete',
                'indexed_roots_b64' => array_map(
                    'base64_encode',
                    $requestedDirectories
                ),
                'next_remote_index_start_byte_offset' => 17,
                'next_remote_index_end_byte_offset' => 43,
            ],
            $record
        );
        $this->assertSame($durableByteOffset, strlen($rawJournal));

        $marker = json_decode(
            file_get_contents(
                $this->markersDirectory() . '/'
                . hash('sha256', $listDirectory) . '.json'
            ),
            true
        );
        $this->assertSame(
            ['completion_record_byte_offset' => 0],
            $marker
        );
    }

    public function testFreshBoundaryStreamsMarkerCleanup(): void
    {
        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset(0);
        $journal->complete_traversal(
            0,
            10,
            '/remote/site',
            ['/remote/site']
        );
        $journal->close();
        $this->assertDirectoryExists($this->markersDirectory());

        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset(0);
        $this->assertSame(0, filesize($this->journalPath));
        $this->assertDirectoryDoesNotExist($this->markersDirectory());
        $this->assertFalse(
            $journal->covers_canonical_path('/remote/site/nested', 0)
        );
        $journal->close();
    }

    public function testMarkerBeyondSavedCompletionBoundaryIsIgnored(): void
    {
        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset(0);
        $savedBoundary = $journal->complete_traversal(
            0,
            10,
            '/remote/site',
            ['/remote/site']
        );
        $journal->complete_traversal(
            10,
            20,
            '/remote/outside',
            ['/remote/outside']
        );
        $journal->close();

        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset($savedBoundary);
        $this->assertTrue(
            $journal->covers_canonical_path('/remote/site/child', $savedBoundary)
        );
        $this->assertFalse(
            $journal->covers_canonical_path('/remote/outside/child', $savedBoundary)
        );
        $journal->close();
    }

    public function testCompletionRequiresCanonicalListDirectoryAsFirstRoot(): void
    {
        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset(0);
        try {
            $journal->complete_traversal(
                0,
                10,
                '/remote/site',
                ['/remote/unrequested', '/remote/site']
            );
            $this->fail('Expected completion roots in the wrong order to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'roots or byte range are invalid',
                $exception->getMessage()
            );
        } finally {
            $journal->close();
        }
    }

    public function testUncommittedMarkerCannotCoverARegrownJournal(): void
    {
        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset(0);
        $savedBoundary = $journal->complete_traversal(
            0,
            10,
            '/remote/site',
            ['/remote/site']
        );
        $journal->complete_traversal(
            10,
            20,
            '/remote/outside',
            ['/remote/outside']
        );
        $journal->close();

        // A process stopped after the second marker was written but before
        // its completion boundary reached state. Resume truncates that record,
        // then another completion reuses the same journal byte offset.
        $journal = new \RemoteIndexTraversalJournal($this->journalPath);
        $journal->open_and_truncate_to_saved_byte_offset($savedBoundary);
        $regrownBoundary = $journal->complete_traversal(
            10,
            20,
            '/remote/other',
            ['/remote/other']
        );

        $this->assertFalse(
            $journal->covers_canonical_path(
                '/remote/outside/child',
                $regrownBoundary
            )
        );
        $journal->close();
    }

    private function markersDirectory(): string
    {
        return $this->tempDir . '/remote-index-traversal-roots.next';
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
