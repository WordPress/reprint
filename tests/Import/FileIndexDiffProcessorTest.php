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

    public function testAlignsOldNewAndSharedPaths(): void
    {
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry('b-old-only.txt', 10),
            $this->entry('shared.txt', 20),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('a-new-only.txt', 30),
            $this->entry('shared.txt', 40),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);

        $this->assertTrue($processor->next_path());
        $this->assertSame(
            ['a-new-only.txt', null, 'file', 'b-old-only.txt', null, null],
            $this->current_path_summary($processor)
        );
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertSame(
            ['b-old-only.txt', 'file', null, null, 'shared.txt', 'a-new-only.txt'],
            $this->current_path_summary($processor)
        );
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertSame(
            ['shared.txt', 'file', 'file', null, null, 'a-new-only.txt'],
            $this->current_path_summary($processor)
        );
        $processor->consume_current_path();

        $this->assertFalse($processor->next_path());
        $this->assertFalse($processor->next_path());
        $processor->close();
    }

    public function testComparesDecodedPathBytesInsteadOfBase64Text(): void
    {
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry("\xD0-old"),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('A-new'),
        ]);
        $this->assertLessThan(
            0,
            strcmp(base64_encode("\xD0-old"), base64_encode('A-new'))
        );
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);

        $this->assertTrue($processor->next_path());
        $this->assertSame('A-new', $processor->get_path());
        $this->assertNull($processor->get_path_type_in_old_index());
        $this->assertSame('file', $processor->get_path_type_in_new_index());
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertSame("\xD0-old", $processor->get_path());
        $this->assertSame('file', $processor->get_path_type_in_old_index());
        $this->assertNull($processor->get_path_type_in_new_index());

        $processor->close();
    }

    public function testCurrentPathAndCursorRemainStableUntilConsumption(): void
    {
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry('same.txt', 10),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('same.txt', 20),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
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
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry('a-old'),
            $this->entry('c-shared'),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('b-new'),
            $this->entry('c-shared'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);

        $this->assertTrue($processor->next_path());
        $this->assertSame('file', $processor->get_path_type_in_old_index());
        $this->assertNull($processor->get_path_type_in_new_index());
        $processor->consume_current_path();
        $cursor_after_old_only_path = $processor->get_cursor();
        $this->assertGreaterThan(0, $cursor_after_old_only_path['old_index_byte_offset']);
        $this->assertSame(0, $cursor_after_old_only_path['new_index_byte_offset']);
        $this->assertNull($cursor_after_old_only_path['preceding_new_index_entry_path_b64']);

        $this->assertTrue($processor->next_path());
        $this->assertNull($processor->get_path_type_in_old_index());
        $this->assertSame('file', $processor->get_path_type_in_new_index());
        $processor->consume_current_path();
        $cursor_after_new_only_path = $processor->get_cursor();
        $this->assertSame(
            $cursor_after_old_only_path['old_index_byte_offset'],
            $cursor_after_new_only_path['old_index_byte_offset']
        );
        $this->assertGreaterThan(0, $cursor_after_new_only_path['new_index_byte_offset']);
        $this->assertSame(
            base64_encode('b-new'),
            $cursor_after_new_only_path['preceding_new_index_entry_path_b64']
        );

        $this->assertTrue($processor->next_path());
        $this->assertSame('file', $processor->get_path_type_in_old_index());
        $this->assertSame('file', $processor->get_path_type_in_new_index());
        $processor->consume_current_path();
        $cursor_after_shared_path = $processor->get_cursor();
        $this->assertGreaterThan(
            $cursor_after_new_only_path['old_index_byte_offset'],
            $cursor_after_shared_path['old_index_byte_offset']
        );
        $this->assertGreaterThan(
            $cursor_after_new_only_path['new_index_byte_offset'],
            $cursor_after_shared_path['new_index_byte_offset']
        );
        $processor->close();
    }

    public function testResumeRepeatsTheCurrentPathWhoseConsumptionWasNotStored(): void
    {
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry('a.txt'),
            $this->entry('c.txt'),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('b.txt'),
            $this->entry('c.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
        $this->assertTrue($processor->next_path());
        $processor->consume_current_path();
        $stored_cursor = $processor->get_cursor();
        $this->assertTrue($processor->next_path());
        $unconsumed_path = $this->current_path_summary($processor);
        $processor->close();

        $resumed_processor = FileIndexDiffProcessor::resume(
            $old_index_file,
            $new_index_file,
            $stored_cursor
        );
        $this->assertTrue($resumed_processor->next_path());
        $this->assertSame($unconsumed_path, $this->current_path_summary($resumed_processor));
        $this->assertSame($stored_cursor, $resumed_processor->get_cursor());
        $resumed_processor->close();
    }

    public function testPrecedingNewPathSurvivesOldOnlyPathsAndResume(): void
    {
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry('b-old'),
            $this->entry('c-shared'),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('a-new'),
            $this->entry('c-shared'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
        $this->assertTrue($processor->next_path());
        $processor->consume_current_path();

        $this->assertTrue($processor->next_path());
        $this->assertSame('a-new', $processor->get_preceding_path_in_new_index());
        $processor->consume_current_path();
        $cursor = $processor->get_cursor();
        $processor->close();

        $resumed_processor = FileIndexDiffProcessor::resume(
            $old_index_file,
            $new_index_file,
            $cursor
        );
        $this->assertTrue($resumed_processor->next_path());
        $this->assertSame('file', $resumed_processor->get_path_type_in_old_index());
        $this->assertSame('file', $resumed_processor->get_path_type_in_new_index());
        $this->assertSame(
            'a-new',
            $resumed_processor->get_preceding_path_in_new_index()
        );
        $resumed_processor->close();
    }

    public function testMissingOldIndexRepresentsAnEmptyOldSnapshot(): void
    {
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('first.txt'),
            $this->entry('second.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start(
            $this->temp_dir . '/missing-old.jsonl',
            $new_index_file
        );

        $this->assertTrue($processor->next_path());
        $this->assertNull($processor->get_path_type_in_old_index());
        $this->assertSame('file', $processor->get_path_type_in_new_index());
        $processor->consume_current_path();
        $this->assertTrue($processor->next_path());
        $this->assertNull($processor->get_path_type_in_old_index());
        $this->assertSame('file', $processor->get_path_type_in_new_index());
        $processor->consume_current_path();
        $this->assertFalse($processor->next_path());
        $processor->close();
    }

    public function testDirectoryEntryKeepsItsEmptyMarker(): void
    {
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('empty-directory', 10, 0, 'dir', true),
        ]);
        $processor = FileIndexDiffProcessor::start(
            $this->temp_dir . '/missing-old.jsonl',
            $new_index_file
        );

        $this->assertTrue($processor->next_path());
        $this->assertSame('empty-directory', $processor->get_path());
        $this->assertSame('dir', $processor->get_path_type_in_new_index());
        $this->assertSame(0, $processor->get_size_in_new_index());
        $this->assertSame(10, $processor->get_ctime_in_new_index());
        $this->assertTrue($processor->get_directory_is_empty_in_new_index());
        $processor->close();
    }

    public function testMissingNewIndexIsRejected(): void
    {
        $old_index_file = $this->write_index('old.jsonl', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to open the new file index');
        FileIndexDiffProcessor::start(
            $old_index_file,
            $this->temp_dir . '/missing-new.jsonl'
        );
    }

    public function testConsumingWhenBothIndexesReachedEofIsRejected(): void
    {
        $old_index_file = $this->write_index('old.jsonl', []);
        $new_index_file = $this->write_index('new.jsonl', []);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
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
        $old_index_file = $this->write_index('old.jsonl', []);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('new.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
        $this->assertTrue($processor->next_path());
        $processor->close();
        $processor->close();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot use a closed file-index diff processor');
        $processor->next_path();
    }

    public function testGettersDoNotSelectAPath(): void
    {
        $old_index_file = $this->write_index('old.jsonl', []);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('new.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Call next_path() first');
        try {
            $processor->get_path();
        } finally {
            $processor->close();
        }
    }

    public function testFollowingOldPathRequiresTheCurrentPathToBeAbsentFromTheOldIndex(): void
    {
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry('shared.txt'),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('shared.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
        $this->assertTrue($processor->next_path());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('current path occurs in the old index');
        try {
            $processor->get_following_path_in_old_index();
        } finally {
            $processor->close();
        }
    }

    public function testFollowingNewPathRequiresTheCurrentPathToBeAbsentFromTheNewIndex(): void
    {
        $old_index_file = $this->write_index('old.jsonl', [
            $this->entry('shared.txt'),
        ]);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('shared.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
        $this->assertTrue($processor->next_path());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('current path occurs in the new index');
        try {
            $processor->get_following_path_in_new_index();
        } finally {
            $processor->close();
        }
    }

    public function testNextPathRejectsAnUnconsumedCurrentPath(): void
    {
        $old_index_file = $this->write_index('old.jsonl', []);
        $new_index_file = $this->write_index('new.jsonl', [
            $this->entry('new.txt'),
        ]);
        $processor = FileIndexDiffProcessor::start($old_index_file, $new_index_file);
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
        $old_path_type = $processor->get_path_type_in_old_index();
        $new_path_type = $processor->get_path_type_in_new_index();
        return [
            $processor->get_path(),
            $old_path_type,
            $new_path_type,
            $old_path_type === null
                ? $processor->get_following_path_in_old_index()
                : null,
            $new_path_type === null
                ? $processor->get_following_path_in_new_index()
                : null,
            $processor->get_preceding_path_in_new_index(),
        ];
    }
}
