<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-server/src/utils.php';
require_once __DIR__ . '/../../packages/reprint-server/src/class-file-index-processor.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/index/class-fresh-local-index-processor.php';

final class FreshLocalIndexProcessorTest extends TestCase {
    private string $temporary_directory;
    private string $filesystem_root;
    private string $fresh_local_index_file;
    private string $storage_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporary_directory = sys_get_temp_dir()
            . '/fresh-local-index-processor-test-'
            . uniqid();
        $this->filesystem_root =
            $this->temporary_directory . '/filesystem-root';
        $this->fresh_local_index_file =
            $this->temporary_directory . '/fresh-local-index.jsonl';
        $this->storage_path = $this->temporary_directory . '/state';
        mkdir($this->filesystem_root, 0755, true);
        mkdir($this->storage_path, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove_path($this->temporary_directory);
        parent::tearDown();
    }

    public function testBuildsASortedLocalRelativeIndex(): void
    {
        mkdir($this->filesystem_root . '/empty');
        mkdir($this->filesystem_root . '/full');
        file_put_contents($this->filesystem_root . '/full/child.txt', 'child');
        file_put_contents($this->filesystem_root . '/z.txt', 'z');
        file_put_contents($this->filesystem_root . '/a.txt', 'a');

        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );
        $this->run_to_completion($processor);

        $entries = $this->read_index_entries();
        $this->assertSame(
            ['a.txt', 'empty', 'full/child.txt', 'z.txt'],
            array_column($entries, 'decoded_path')
        );
        $this->assertTrue($entries[1]['empty']);
        $this->assertArrayNotHasKey('empty', $entries[2]);
        $this->assertArrayNotHasKey('decoded_path', $entries[0]['stored']);
        $this->assertSame(base64_encode('a.txt'), $entries[0]['stored']['path']);
    }

    public function testResumeDiscardsBytesWrittenAfterTheSavedCursor(): void
    {
        file_put_contents($this->filesystem_root . '/a.txt', 'a');
        file_put_contents($this->filesystem_root . '/b.txt', 'b');

        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );
        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $saved_cursor = $processor->get_cursor();

        $this->assertTrue($processor->next_step());
        $processor->close();
        $this->assertGreaterThan(
            $saved_cursor['position']['fresh_local_index_byte_offset'],
            filesize($this->fresh_local_index_file)
        );

        $resumed_processor = FreshLocalIndexProcessor::resume($saved_cursor);
        $this->run_to_completion($resumed_processor);

        $this->assertSame(
            ['a.txt', 'b.txt'],
            array_column($this->read_index_entries(), 'decoded_path')
        );
    }

    public function testSortingIsASeparateResumableStep(): void
    {
        file_put_contents($this->filesystem_root . '/value.txt', 'value');
        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );

        while ($processor->get_phase() === 'indexing') {
            $this->assertTrue($processor->next_step());
        }
        $this->assertSame('sorting', $processor->get_phase());
        $sorting_cursor = $processor->get_cursor();
        $processor->close();

        $resumed_processor = FreshLocalIndexProcessor::resume($sorting_cursor);
        $this->assertFalse($resumed_processor->next_step());
        $this->assertSame('complete', $resumed_processor->get_phase());
        $this->assertFalse($resumed_processor->next_step());
        $resumed_processor->close();
    }

    public function testIncludeCachesIsRetainedByTheCursor(): void
    {
        mkdir($this->filesystem_root . '/node_modules');
        file_put_contents(
            $this->filesystem_root . '/node_modules/package.js',
            'package'
        );
        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            true
        );
        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $cursor = $processor->get_cursor();
        $processor->close();

        $resumed_processor = FreshLocalIndexProcessor::resume($cursor);
        $this->run_to_completion($resumed_processor);

        $this->assertSame(
            ['node_modules/package.js'],
            array_column($this->read_index_entries(), 'decoded_path')
        );
    }

    public function testSelectedDefaultSkippedPathsAddPresentEntriesAndOmitMissingPaths(): void
    {
        mkdir($this->filesystem_root . '/.git');
        mkdir($this->filesystem_root . '/.git/present');
        symlink('target.txt', $this->filesystem_root . '/.git/link');
        $selected_paths_file = $this->write_selected_paths([
            '.git/link',
            '.git/missing',
            '.git/no-parent/value',
            '.git/present/missing/deeper/value',
        ]);

        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        $this->run_to_completion($processor);

        $entries = $this->read_index_entries();
        $this->assertSame(['.git/link'], array_column($entries, 'decoded_path'));
        $this->assertSame('link', $entries[0]['type']);
    }

    public function testSelectedFilesAndDirectoriesAreIndexedWithoutDescent(): void
    {
        mkdir($this->filesystem_root . '/.git');
        mkdir($this->filesystem_root . '/.git/empty');
        file_put_contents($this->filesystem_root . '/.git/file.txt', 'file');
        mkdir($this->filesystem_root . '/.git/full');
        file_put_contents(
            $this->filesystem_root . '/.git/full/unselected.txt',
            'child'
        );
        mkdir($this->filesystem_root . '/.DS_Store');
        file_put_contents(
            $this->filesystem_root . '/.DS_Store/selected.txt',
            'selected'
        );
        $selected_paths_file = $this->write_selected_paths([
            '.DS_Store/selected.txt',
            '.git/empty',
            '.git/file.txt',
            '.git/full',
        ]);

        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        $this->run_to_completion($processor);

        $entries = $this->read_index_entries();
        $this->assertSame(
            [
                '.DS_Store/selected.txt',
                '.git/empty',
                '.git/file.txt',
                '.git/full',
            ],
            array_column($entries, 'decoded_path')
        );
        $this->assertSame('file', $entries[0]['type']);
        $this->assertTrue($entries[1]['empty']);
        $this->assertSame('file', $entries[2]['type']);
        $this->assertFalse($entries[3]['empty']);
        $this->assertNotContains(
            '.git/full/unselected.txt',
            array_column($entries, 'decoded_path')
        );
    }

    public function testOnlySelectedUnsupportedPathsAreIndexed(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('This PHP build cannot create a FIFO.');
        }
        mkdir($this->filesystem_root . '/.git');
        posix_mkfifo($this->filesystem_root . '/.git/unowned.pipe', 0600);
        $empty_selected_paths_file = $this->write_selected_paths([]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $empty_selected_paths_file
        );
        $this->run_to_completion($processor);
        $this->assertSame([], $this->read_index_entries());

        posix_mkfifo($this->filesystem_root . '/.git/selected.pipe', 0600);
        $selected_paths_file = $this->write_selected_paths(
            ['.git/selected.pipe'],
            'selected-fifo.jsonl'
        );
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        $this->run_to_completion($processor);
        $entries = $this->read_index_entries();
        $this->assertSame(
            ['.git/selected.pipe'],
            array_column($entries, 'decoded_path')
        );
        $this->assertSame('other', $entries[0]['type']);
    }

    public function testOrdinaryTraversalStillRejectsUnsupportedPaths(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('This PHP build cannot create a FIFO.');
        }
        $fifo_path = $this->filesystem_root . '/ordinary.pipe';
        posix_mkfifo($fifo_path, 0600);
        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );
        try {
            $this->expectException(RuntimeException::class);
            $canonical_fifo_path = realpath($this->filesystem_root)
                . '/ordinary.pipe';
            $this->expectExceptionMessage(
                'Cannot index the unsupported local path: '
                . base64_encode($canonical_fifo_path)
                . '.'
            );
            $this->run_to_completion($processor);
        } finally {
            $processor->close();
        }
    }

    public function testSelectedPathParentMayResolveWithinButNotOutsideTheRoot(): void
    {
        mkdir($this->filesystem_root . '/.git');
        mkdir($this->filesystem_root . '/.git/target');
        file_put_contents(
            $this->filesystem_root . '/.git/target/value.txt',
            'inside'
        );
        symlink('target', $this->filesystem_root . '/.git/inside');
        $selected_paths_file = $this->write_selected_paths([
            '.git/inside/value.txt',
        ]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        $this->run_to_completion($processor);
        $this->assertSame(
            ['.git/inside/value.txt'],
            array_column($this->read_index_entries(), 'decoded_path')
        );

        $outside_directory = $this->temporary_directory . '/outside';
        mkdir($outside_directory);
        file_put_contents($outside_directory . '/value.txt', 'outside');
        symlink($outside_directory, $this->filesystem_root . '/.git/outside');
        $selected_paths_file = $this->write_selected_paths(
            ['.git/outside/value.txt'],
            'outside-parent.jsonl'
        );
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Selected path parent prefix resolves outside the filesystem root:'
            );
            $this->run_to_completion($processor);
        } finally {
            $processor->close();
        }
    }

    public function testSelectedPathRejectsAnUnresolvableExistingParent(): void
    {
        mkdir($this->filesystem_root . '/.git');
        symlink(
            $this->temporary_directory . '/missing-target',
            $this->filesystem_root . '/.git/broken'
        );
        $selected_paths_file = $this->write_selected_paths([
            '.git/broken/value.txt',
        ]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Selected path parent prefix does not resolve to a directory:'
            );
            $this->run_to_completion($processor);
        } finally {
            $processor->close();
        }
    }

    public function testSelectedPathRejectsANonDirectoryParentPrefix(): void
    {
        mkdir($this->filesystem_root . '/.git');
        file_put_contents($this->filesystem_root . '/.git/file.txt', 'file');
        $selected_paths_file = $this->write_selected_paths([
            '.git/file.txt/child.txt',
        ]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Selected path parent prefix is not a directory:'
            );
            $this->run_to_completion($processor);
        } finally {
            $processor->close();
        }
    }

    /**
     * @dataProvider unsearchableSelectedPathParents
     */
    public function testSelectedPathRejectsAnUnsearchableParentPrefix(
        string $locked_parent,
        string $selected_path
    ): void {
        $absolute_locked_parent = $this->filesystem_root
            . '/' . $locked_parent;
        mkdir($absolute_locked_parent, 0755, true);
        $remaining_parent = dirname($selected_path);
        if ($remaining_parent !== $locked_parent) {
            mkdir(
                $this->filesystem_root . '/' . $remaining_parent,
                0755,
                true
            );
        }
        chmod($absolute_locked_parent, 0000);
        $selected_paths_file = $this->write_selected_paths([$selected_path]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(
                'Selected path parent prefix is not a searchable directory: '
                . base64_encode(realpath($absolute_locked_parent))
                . '.'
            );
            $this->run_to_completion($processor);
        } finally {
            chmod($absolute_locked_parent, 0700);
            $processor->close();
        }
    }

    /** @return array<string,array{string,string}> */
    public static function unsearchableSelectedPathParents(): array
    {
        return [
            'immediate parent' => [
                '.git/locked',
                '.git/locked/value.txt',
            ],
            'deeper parent' => [
                '.git/locked',
                '.git/locked/deeper/value.txt',
            ],
        ];
    }

    public function testSelectedPathsStillOmitTheStorageSubtree(): void
    {
        $storage_path = $this->filesystem_root . '/.git/reprint-state';
        mkdir($storage_path, 0755, true);
        file_put_contents($storage_path . '/state.json', 'state');
        $selected_paths_file = $this->write_selected_paths([
            '.git/reprint-state/state.json',
        ]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $storage_path,
            $selected_paths_file
        );
        $this->run_to_completion($processor);

        $this->assertSame([], $this->read_index_entries());
    }

    public function testSelectedPathsResumeFromBothDurableOffsets(): void
    {
        mkdir($this->filesystem_root . '/.git');
        file_put_contents($this->filesystem_root . '/.git/a.txt', 'a');
        file_put_contents($this->filesystem_root . '/.git/b.txt', 'b');
        $selected_paths_file = $this->write_selected_paths([
            '.git/a.txt',
            '.git/b.txt',
        ]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        $this->advance_to_phase($processor, 'supplementing');
        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $saved_cursor = $this->serialize_cursor($processor->get_cursor());
        $saved_output_offset = $saved_cursor['position'][
            'fresh_local_index_byte_offset'
        ];
        $saved_input_offset = $saved_cursor['position'][
            'selected_paths_byte_offset'
        ];
        $this->assertGreaterThan(0, $saved_output_offset);
        $this->assertGreaterThan(0, $saved_input_offset);

        $this->assertTrue($processor->next_step());
        $processor->flush_pending_output();
        $this->assertGreaterThan(
            $saved_output_offset,
            filesize($this->fresh_local_index_file)
        );
        $processor->close();

        $resumed = FreshLocalIndexProcessor::resume($saved_cursor);
        $this->assertSame('supplementing', $resumed->get_phase());
        $this->run_to_completion($resumed);
        $this->assertSame(
            ['.git/a.txt', '.git/b.txt'],
            array_column($this->read_index_entries(), 'decoded_path')
        );
    }

    public function testResumeReplaysBothSelectedPathPhaseTransitions(): void
    {
        mkdir($this->filesystem_root . '/.git');
        file_put_contents($this->filesystem_root . '/.git/value.txt', 'value');
        $selected_paths_file = $this->write_selected_paths([
            '.git/value.txt',
        ]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );

        $cursor_before_supplementing = null;
        for ($step = 0; $step < 100; ++$step) {
            $processor->flush_pending_output();
            $cursor_before_step = $this->serialize_cursor(
                $processor->get_cursor()
            );
            $this->assertTrue($processor->next_step());
            if ($processor->get_phase() === 'supplementing') {
                $cursor_before_supplementing = $cursor_before_step;
                break;
            }
        }
        $this->assertIsArray($cursor_before_supplementing);
        $processor->close();

        $resumed = FreshLocalIndexProcessor::resume(
            $cursor_before_supplementing
        );
        $this->assertTrue($resumed->next_step());
        $this->assertSame('supplementing', $resumed->get_phase());
        $this->assertTrue($resumed->next_step());
        $resumed->flush_pending_output();
        $cursor_before_sorting = $this->serialize_cursor(
            $resumed->get_cursor()
        );
        $this->assertTrue($resumed->next_step());
        $this->assertSame('sorting', $resumed->get_phase());
        $resumed->close();

        $resumed = FreshLocalIndexProcessor::resume($cursor_before_sorting);
        $this->assertTrue($resumed->next_step());
        $this->assertSame('sorting', $resumed->get_phase());
        $this->assertFalse($resumed->next_step());
        $this->assertSame('complete', $resumed->get_phase());
        $resumed->close();
        $this->assertSame(
            ['.git/value.txt'],
            array_column($this->read_index_entries(), 'decoded_path')
        );
    }

    public function testSupplementalResumeRequiresItsImmutableInput(): void
    {
        mkdir($this->filesystem_root . '/.git');
        $selected_paths_file = $this->write_selected_paths([
            '.git/missing',
        ]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        $this->advance_to_phase($processor, 'supplementing');
        $processor->flush_pending_output();
        $cursor = $this->serialize_cursor($processor->get_cursor());
        $processor->close();
        unlink($selected_paths_file);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Failed to open the immutable selected-path file at byte 0'
        );
        FreshLocalIndexProcessor::resume($cursor);
    }

    public function testSelectedPathInputCannotBeTheFreshIndexOutput(): void
    {
        $row = json_encode(
            ['path_b64' => base64_encode('.git/value.txt')],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        file_put_contents($this->fresh_local_index_file, $row);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The selected-path input and fresh local index must be different files.'
        );
        try {
            FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
                $this->fresh_local_index_file,
                $this->filesystem_root,
                $this->storage_path,
                $this->fresh_local_index_file
            );
        } finally {
            $this->assertSame($row, file_get_contents($this->fresh_local_index_file));
        }
    }

    public function testSelectedPathCursorSupportsArbitraryBytes(): void
    {
        mkdir($this->filesystem_root . '/.git');
        $selected_path = ".git/value-\xff";
        if (@file_put_contents(
            $this->filesystem_root . '/' . $selected_path,
            'value'
        ) === false) {
            $this->markTestSkipped(
                'This filesystem does not accept non-UTF-8 path components.'
            );
        }
        $selected_paths_file = $this->write_selected_paths([$selected_path]);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        $this->advance_to_phase($processor, 'supplementing');
        $processor->flush_pending_output();
        $cursor = $this->serialize_cursor($processor->get_cursor());
        $processor->close();

        $resumed = FreshLocalIndexProcessor::resume($cursor);
        $this->run_to_completion($resumed);
        $this->assertSame(
            [$selected_path],
            array_column($this->read_index_entries(), 'decoded_path')
        );
    }

    /**
     * @dataProvider invalidSelectedPathRows
     */
    public function testRejectsInvalidSelectedPathRows(
        string $contents,
        string $message
    ): void {
        $selected_paths_file = $this->temporary_directory
            . '/invalid-selected-paths.jsonl';
        file_put_contents($selected_paths_file, $contents);
        $processor = FreshLocalIndexProcessor::start_with_selected_default_skipped_paths(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path,
            $selected_paths_file
        );
        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage($message);
            $this->run_to_completion($processor);
        } finally {
            $processor->close();
        }
    }

    /** @return array<string,array{string,string}> */
    public static function invalidSelectedPathRows(): array
    {
        $row = static function (string $path): string {
            return json_encode(
                ['path_b64' => base64_encode($path)],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
        };
        return [
            'malformed JSON' => ["not-json\n", 'contains an invalid row'],
            'extra field' => [
                '{"path_b64":"LmdpdC9h","extra":true}' . "\n",
                'contains an invalid row',
            ],
            'noncanonical base64' => [
                '{"path_b64":"LmdpdC9hYg"}' . "\n",
                'invalid canonical base64',
            ],
            'absolute path' => [
                $row('/.git/value'),
                'must not be absolute',
            ],
            'ordinary path' => [
                $row('ordinary.txt'),
                'is not omitted by the default local traversal',
            ],
            'duplicate path' => [
                $row('.git/a') . $row('.git/a'),
                'must be sorted and deduplicated',
            ],
            'descending path' => [
                $row('.git/b') . $row('.git/a'),
                'must be sorted and deduplicated',
            ],
            'unterminated row' => [
                rtrim($row('.git/a'), "\n"),
                'is unterminated',
            ],
            'oversized row' => [
                '{"path_b64":"' . str_repeat('A', 70 * 1024) . '"}' . "\n",
                'exceeds the maximum',
            ],
        ];
    }

    public function testCursorSerializesAndResumesEveryPhaseWithArbitraryBytePaths(): void
    {
        $arbitrary_byte_directory =
            $this->temporary_directory . "/paths-\xff";
        if (!@mkdir($arbitrary_byte_directory)) {
            $this->markTestSkipped(
                'This filesystem does not accept non-UTF-8 path components.'
            );
        }
        $this->filesystem_root =
            $arbitrary_byte_directory . "/filesystem-root-\xfe";
        $this->storage_path =
            $arbitrary_byte_directory . "/storage-\xfd";
        $this->fresh_local_index_file =
            $arbitrary_byte_directory . "/fresh-index-\xfc.jsonl";
        mkdir($this->filesystem_root);
        mkdir($this->storage_path);
        file_put_contents($this->filesystem_root . '/value.txt', 'value');

        $processor = FreshLocalIndexProcessor::start(
            $this->fresh_local_index_file,
            $this->filesystem_root,
            $this->storage_path
        );
        $seen_phases = [];
        for ($step = 0; $step < 100; ++$step) {
            $processor->flush_pending_output();
            $cursor = $this->serialize_cursor($processor->get_cursor());
            $phase = $cursor['position']['phase'];
            $seen_phases[$phase] = true;
            $this->assertSame(
                $this->fresh_local_index_file,
                base64_decode($cursor['fresh_local_index_file_b64'], true)
            );
            $this->assertSame(
                realpath($this->filesystem_root),
                base64_decode($cursor['filesystem_root_b64'], true)
            );
            $this->assertSame(
                $this->storage_path,
                base64_decode($cursor['storage_path_b64'], true)
            );
            $processor->close();
            $processor = FreshLocalIndexProcessor::resume($cursor);
            if ($phase === 'complete') {
                $this->assertFalse($processor->next_step());
                $processor->close();
                break;
            }
            $processor->next_step();
        }

        $this->assertSame(
            ['indexing', 'sorting', 'complete'],
            array_keys($seen_phases)
        );
        $this->assertSame(
            ['value.txt'],
            array_column($this->read_index_entries(), 'decoded_path')
        );
    }

    private function run_to_completion(
        FreshLocalIndexProcessor $processor
    ): void {
        for ($step = 0; $step < 100; ++$step) {
            if (!$processor->next_step()) {
                $processor->close();
                return;
            }
        }
        $this->fail('Fresh local index did not complete within 100 steps.');
    }

    private function advance_to_phase(
        FreshLocalIndexProcessor $processor,
        string $phase
    ): void {
        for ($step = 0; $step < 100; ++$step) {
            if ($processor->get_phase() === $phase) {
                return;
            }
            $this->assertTrue($processor->next_step());
        }
        $this->fail("Fresh local index did not reach {$phase} within 100 steps.");
    }

    /**
     * @param array<string,mixed> $cursor
     * @return array<string,mixed>
     */
    private function serialize_cursor(array $cursor): array
    {
        $stored_cursor = json_decode(
            json_encode($cursor, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($stored_cursor);
        return $stored_cursor;
    }

    /** @return list<array<string,mixed>> */
    private function read_index_entries(): array
    {
        $lines = file(
            $this->fresh_local_index_file,
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        $this->assertIsArray($lines);
        $entries = [];
        foreach ($lines as $line) {
            $stored = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $decoded_path = base64_decode($stored['path'], true);
            $this->assertIsString($decoded_path);
            $entry = [
                'decoded_path' => $decoded_path,
                'stored' => $stored,
                'type' => $stored['type'],
            ];
            if (array_key_exists('empty', $stored)) {
                $entry['empty'] = $stored['empty'];
            }
            $entries[] = $entry;
        }
        return $entries;
    }

    /** @param list<string> $paths */
    private function write_selected_paths(
        array $paths,
        string $filename = 'selected-paths.jsonl'
    ): string {
        $lines = array_map(
            static function (string $path): string {
                return json_encode(
                    ['path_b64' => base64_encode($path)],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );
            },
            $paths
        );
        $selected_paths_file = $this->temporary_directory . '/' . $filename;
        file_put_contents(
            $selected_paths_file,
            empty($lines) ? '' : implode("\n", $lines) . "\n"
        );
        return $selected_paths_file;
    }

    private function remove_path(string $path): void
    {
        if (
            is_link($path)
            || is_file($path)
            || ( @lstat($path) !== false && !is_dir($path) )
        ) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $children = scandir($path);
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    $this->remove_path($path . '/' . $child);
                }
            }
        }
        rmdir($path);
    }
}
