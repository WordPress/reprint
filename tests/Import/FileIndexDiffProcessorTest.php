<?php

declare(strict_types=1);

// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test classes.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-file-index-diff-processor.php';

/**
 * Covers FileIndexDiffProcessor alignment and interruption boundaries.
 *
 * These tests use small real JSONL indexes so byte offsets, retained entries,
 * decoded-path ordering, EOF, close(), and resume() exercise the same stream
 * operations used by pull and push.
 */
final class FileIndexDiffProcessorTest extends TestCase
{
    private string $temp_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temp_dir = sys_get_temp_dir()
            . '/file-index-diff-processor-'
            . bin2hex(random_bytes(6));
        mkdir($this->temp_dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->temp_dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->temp_dir . '/' . $entry);
            }
        }
        rmdir($this->temp_dir);
        parent::tearDown();
    }

    public function testAlignsEarlierLaterAndSharedPaths(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', [
            $this->entry('b-earlier-only.txt', 10),
            $this->entry('shared.txt', 20),
        ]);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('a-later-only.txt', 30),
            $this->entry('shared.txt', 40),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);

        $this->assertTrue($processor->next_path());
        $this->assertSame(
            ['a-later-only.txt', null, 'file', 'b-earlier-only.txt', 'a-later-only.txt', null],
            $this->current_path_summary($processor)
        );
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertSame(
            ['b-earlier-only.txt', 'file', null, 'b-earlier-only.txt', 'shared.txt', 'a-later-only.txt'],
            $this->current_path_summary($processor)
        );
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertSame(
            ['shared.txt', 'file', 'file', 'shared.txt', 'shared.txt', 'a-later-only.txt'],
            $this->current_path_summary($processor)
        );
        $processor->consume_current_path();

        $this->assertFalse($processor->next_path());
        $this->assertFalse($processor->next_path());
        $processor->close();
    }

    public function testComparesDecodedPathBytesInsteadOfBase64Text(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', [
            $this->entry("\xD0-earlier"),
        ]);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('A-later'),
        ]);
        $this->assertLessThan(
            0,
            strcmp(base64_encode("\xD0-earlier"), base64_encode('A-later'))
        );
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);

        $this->assertTrue($processor->next_path());
        $this->assertSame('A-later', $processor->get_path());
        $this->assertNull($processor->get_earlier_path_type());
        $this->assertSame('file', $processor->get_later_path_type());
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertSame("\xD0-earlier", $processor->get_path());
        $this->assertSame('file', $processor->get_earlier_path_type());
        $this->assertNull($processor->get_later_path_type());

        $processor->close();
    }

    public function testCurrentPathAndCursorRemainStableUntilConsumption(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', [
            $this->entry('same.txt', 10),
        ]);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('same.txt', 20),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);
        $initial_cursor = $processor->get_cursor();

        $this->assertTrue($processor->next_path());
        $first_read = $this->current_path_summary($processor);
        $this->assertSame($first_read, $this->current_path_summary($processor));
        $this->assertSame($initial_cursor, $processor->get_cursor());

        $processor->consume_current_path();
        $this->assertNotSame($initial_cursor, $processor->get_cursor());
        $processor->close();
    }

    public function testConsumptionAdvancesOnlyTheIndexesRepresentedByTheCurrentPath(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', [
            $this->entry('a-earlier'),
            $this->entry('c-shared'),
        ]);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('b-later'),
            $this->entry('c-shared'),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);

        $this->assertTrue($processor->next_path());
        $this->assertSame('file', $processor->get_earlier_path_type());
        $this->assertNull($processor->get_later_path_type());
        $processor->consume_current_path();
        $cursor_after_earlier_only_path = $processor->get_cursor();
        $this->assertGreaterThan(0, $cursor_after_earlier_only_path['earlier_index_byte_offset']);
        $this->assertSame(0, $cursor_after_earlier_only_path['later_index_byte_offset']);
        $this->assertNull($cursor_after_earlier_only_path['previous_later_index_entry_path_b64']);

        $this->assertTrue($processor->next_path());
        $this->assertNull($processor->get_earlier_path_type());
        $this->assertSame('file', $processor->get_later_path_type());
        $processor->consume_current_path();
        $cursor_after_later_only_path = $processor->get_cursor();
        $this->assertSame(
            $cursor_after_earlier_only_path['earlier_index_byte_offset'],
            $cursor_after_later_only_path['earlier_index_byte_offset']
        );
        $this->assertGreaterThan(0, $cursor_after_later_only_path['later_index_byte_offset']);
        $this->assertSame(
            base64_encode('b-later'),
            $cursor_after_later_only_path['previous_later_index_entry_path_b64']
        );

        $this->assertTrue($processor->next_path());
        $this->assertSame('file', $processor->get_earlier_path_type());
        $this->assertSame('file', $processor->get_later_path_type());
        $processor->consume_current_path();
        $cursor_after_shared_path = $processor->get_cursor();
        $this->assertGreaterThan(
            $cursor_after_later_only_path['earlier_index_byte_offset'],
            $cursor_after_shared_path['earlier_index_byte_offset']
        );
        $this->assertGreaterThan(
            $cursor_after_later_only_path['later_index_byte_offset'],
            $cursor_after_shared_path['later_index_byte_offset']
        );
        $processor->close();
    }

    public function testResumeRepeatsTheCurrentPathWhoseConsumptionWasNotStored(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', [
            $this->entry('a.txt'),
            $this->entry('c.txt'),
        ]);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('b.txt'),
            $this->entry('c.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);
        $this->assertTrue($processor->next_path());
        $processor->consume_current_path();
        $stored_cursor = $processor->get_cursor();
        $this->assertTrue($processor->next_path());
        $unconsumed_path = $this->current_path_summary($processor);
        $processor->close();

        $resumed_processor = FileIndexDiffProcessor::resume(
            $earlier_index_file,
            $later_index_file,
            $stored_cursor
        );
        $this->assertTrue($resumed_processor->next_path());
        $this->assertSame($unconsumed_path, $this->current_path_summary($resumed_processor));
        $this->assertSame($stored_cursor, $resumed_processor->get_cursor());
        $resumed_processor->close();
    }

    public function testPreviousLaterPathSurvivesEarlierOnlyPathsAndResume(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', [
            $this->entry('b-earlier'),
            $this->entry('c-shared'),
        ]);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('a-later'),
            $this->entry('c-shared'),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);
        $this->assertTrue($processor->next_path());
        $processor->consume_current_path();

        $this->assertTrue($processor->next_path());
        $this->assertSame('a-later', $processor->get_previous_later_path());
        $processor->consume_current_path();
        $cursor = $processor->get_cursor();
        $processor->close();

        $resumed_processor = FileIndexDiffProcessor::resume(
            $earlier_index_file,
            $later_index_file,
            $cursor
        );
        $this->assertTrue($resumed_processor->next_path());
        $this->assertSame('file', $resumed_processor->get_earlier_path_type());
        $this->assertSame('file', $resumed_processor->get_later_path_type());
        $this->assertSame(
            'a-later',
            $resumed_processor->get_previous_later_path()
        );
        $resumed_processor->close();
    }

    public function testMissingEarlierIndexRepresentsAnEmptyEarlierSnapshot(): void
    {
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('first.txt'),
            $this->entry('second.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start(
            $this->temp_dir . '/missing-earlier.jsonl',
            $later_index_file
        );

        $this->assertTrue($processor->next_path());
        $this->assertNull($processor->get_earlier_path_type());
        $this->assertSame('file', $processor->get_later_path_type());
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertNull($processor->get_earlier_path_type());
        $this->assertSame('file', $processor->get_later_path_type());
        $processor->consume_current_path();
        $this->assertFalse($processor->next_path());
        $processor->close();
    }

    public function testDirectoryEntryKeepsItsEmptyMarker(): void
    {
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('empty-directory', 10, 0, 'dir', true),
        ]);
        $processor = FileIndexDiffProcessor::start(
            $this->temp_dir . '/missing-earlier.jsonl',
            $later_index_file
        );

        $this->assertTrue($processor->next_path());
        $this->assertSame('empty-directory', $processor->get_path());
        $this->assertSame('dir', $processor->get_later_path_type());
        $this->assertSame(0, $processor->get_later_size());
        $this->assertSame(10, $processor->get_later_ctime());
        $this->assertTrue($processor->get_later_directory_is_empty());
        $processor->close();
    }

    public function testMissingLaterIndexIsRejected(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to open the later file index');
        FileIndexDiffProcessor::start(
            $earlier_index_file,
            $this->temp_dir . '/missing-later.jsonl'
        );
    }

    public function testConsumingWhenBothIndexesReachedEofIsRejected(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', []);
        $later_index_file = $this->write_index('later.jsonl', []);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);
        $this->assertFalse($processor->next_path());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No current file-index path');
        try {
            $processor->consume_current_path();
        } finally {
            $processor->close();
        }
    }

    public function testCloseIsIdempotentAndMakesTheProcessorTerminal(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', []);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('later.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);
        $this->assertTrue($processor->next_path());
        $processor->close();
        $processor->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot use a closed file-index diff processor');
        $processor->next_path();
    }

    public function testGettersDoNotSelectAPath(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', []);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('later.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Call next_path() first');
        try {
            $processor->get_path();
        } finally {
            $processor->close();
        }
    }

    public function testNextPathRejectsAnUnconsumedCurrentPath(): void
    {
        $earlier_index_file = $this->write_index('earlier.jsonl', []);
        $later_index_file = $this->write_index('later.jsonl', [
            $this->entry('later.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($earlier_index_file, $later_index_file);
        $this->assertTrue($processor->next_path());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before consuming the current path');
        try {
            $processor->next_path();
        } finally {
            $processor->close();
        }
    }

    /**
     * @param list<array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool}> $entries
     */
    private function write_index(string $filename, array $entries): string
    {
        $index_file = $this->temp_dir . '/' . $filename;
        $lines = '';
        foreach ($entries as $entry) {
            $encoded_entry = $entry;
            $encoded_entry['path'] = base64_encode($entry['path']);
            $lines .= json_encode(
                $encoded_entry,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        }
        file_put_contents($index_file, $lines);
        return $index_file;
    }

    /**
     * @return array{path:string,ctime:int,size:int,type:'file'|'link'|'dir',empty?:bool}
     */
    private function entry(
        string $path,
        int $ctime = 1,
        int $size = 1,
        string $type = 'file',
        ?bool $directory_is_empty = null
    ): array {
        $entry = [
            'path' => $path,
            'ctime' => $ctime,
            'size' => $size,
            'type' => $type,
        ];
        if ($directory_is_empty !== null) {
            $entry['empty'] = $directory_is_empty;
        }
        return $entry;
    }

    /** @return array{string,string|null,string|null,string|null,string|null,string|null} */
    private function current_path_summary(FileIndexDiffProcessor $processor): array
    {
        return [
            $processor->get_path(),
            $processor->get_earlier_path_type(),
            $processor->get_later_path_type(),
            $processor->get_earlier_lookahead_path(),
            $processor->get_later_lookahead_path(),
            $processor->get_previous_later_path(),
        ];
    }
}
