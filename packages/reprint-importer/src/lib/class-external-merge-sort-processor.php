<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sort failures become CLI values, never HTML output.

/**
 * Sorts a line-oriented file through bounded, resumable merge-sort steps.
 *
 * The caller owns the lifecycle and stores the cursor after every completed
 * step. Split runs and merge rounds use deterministic names, so resume needs
 * only the run counts and byte offsets retained in that cursor.
 *
 * Each split step reads one bounded chunk of complete input lines. Each merge
 * step consumes at most one line from either input run. A single line may
 * exceed the byte limit, but no step accumulates more than one split chunk or
 * two merge lookahead lines in memory.
 *
 * @phpstan-type SplittingPosition array{phase:'splitting',input_byte_offset:int,run_count:int}
 * @phpstan-type MergingPosition array{phase:'merging',round:int,input_run_count:int,pair_index:int,left_byte_offset:int,right_byte_offset:int,output_byte_offset:int,last_output_key_b64:string|null}
 * @phpstan-type RemovingPosition array{phase:'removing_input_round',round:int,run_index:int,next_run_count:int}
 * @phpstan-type PublishingEmptyPosition array{phase:'publishing_empty'}
 * @phpstan-type PublishingPosition array{phase:'publishing',final_run:string}
 * @phpstan-type CompletePosition array{phase:'complete'}
 * @phpstan-type SortPosition SplittingPosition|MergingPosition|RemovingPosition|PublishingEmptyPosition|PublishingPosition|CompletePosition
 * @phpstan-type SortCursor array{input_path:string,output_path:string,work_directory:string,chunk_bytes:int,deduplicate:bool,position:SortPosition}
 */
final class ExternalMergeSortProcessor {
    /**
     * Extracts the byte-string sort key from a line, or returns null to omit it.
     *
     * @var callable(string): ?string
     */
    private $key_extractor;

    /** @var string Immutable line-oriented input path. */
    private string $input_path;

    /** @var string Atomically published output path. */
    private string $output_path;

    /** @var string Directory containing deterministic merge-sort runs. */
    private string $work_directory;

    /** @var int Maximum bytes consumed by one ordinary split or merge step. */
    private int $chunk_bytes;

    /** @var bool Whether consecutive equal keys are omitted from each output run. */
    private bool $deduplicate;

    /** @var SortCursor Current durable continuation cursor. */
    private array $cursor;

    /** @var bool Whether close() has released this processor's handles. */
    private bool $closed = false;

    /** @var resource|null Input retained during the splitting phase. */
    private $input_handle = null;

    /** @var resource|null Left input retained for the current merge pair. */
    private $left_run_handle = null;

    /** @var resource|null Right input retained for the current merge pair. */
    private $right_run_handle = null;

    /** @var resource|null Output retained for the current merge pair. */
    private $output_run_handle = null;

    /**
     * @var array{key:string,line:string,byte_offset_after:int}|null
     */
    private ?array $left_run_entry = null;

    /** @var bool Whether the current left lookahead, including EOF, was read. */
    private bool $left_run_entry_loaded = false;

    /**
     * @var array{key:string,line:string,byte_offset_after:int}|null
     */
    private ?array $right_run_entry = null;

    /** @var bool Whether the current right lookahead, including EOF, was read. */
    private bool $right_run_entry_loaded = false;

    /**
     * Starts a new sort in an empty caller-selected work directory.
     *
     * @param string                    $input_path     Immutable input file.
     * @param string                    $output_path    Output path, different from the input.
     * @param string                    $work_directory Empty or not-yet-created work directory.
     * @param callable(string): ?string $key_extractor Extracts a byte-string key or omits a line.
     * @param int                       $chunk_bytes    Maximum ordinary input bytes per step.
     * @param bool                      $deduplicate    Whether to keep only the first line for each key.
     * @return self Open processor at the first split step.
     */
    public static function start(
        string $input_path,
        string $output_path,
        string $work_directory,
        callable $key_extractor,
        int $chunk_bytes,
        bool $deduplicate
    ): self {
        if (!is_file($input_path)) {
            throw new InvalidArgumentException("The merge-sort input is not a file: {$input_path}");
        }
        if ($input_path === $output_path) {
            throw new InvalidArgumentException("The merge-sort output must differ from its input.");
        }
        if ($chunk_bytes < 1) {
            throw new InvalidArgumentException("chunk_bytes must be at least 1 byte.");
        }

        $work_directory = rtrim($work_directory, "/");
        if ($work_directory === "") {
            throw new InvalidArgumentException("The merge-sort work directory must not be empty.");
        }
        if (file_exists($work_directory)) {
            if (!is_dir($work_directory)) {
                throw new InvalidArgumentException(
                    "The merge-sort work path is not a directory: {$work_directory}"
                );
            }
            $directory_handle = opendir($work_directory);
            if (!is_resource($directory_handle)) {
                throw new RuntimeException(
                    "Failed to inspect the merge-sort work directory: {$work_directory}"
                );
            }
            do {
                $directory_entry = readdir($directory_handle);
            } while (
                $directory_entry === "."
                || $directory_entry === ".."
            );
            closedir($directory_handle);
            if ($directory_entry !== false) {
                throw new InvalidArgumentException(
                    "The merge-sort work directory is not empty: {$work_directory}"
                );
            }
        } elseif (!mkdir($work_directory, 0755, true)) {
            throw new RuntimeException(
                "Failed to create the merge-sort work directory: {$work_directory}"
            );
        }

        $cursor = [
            "input_path" => $input_path,
            "output_path" => $output_path,
            "work_directory" => $work_directory,
            "chunk_bytes" => $chunk_bytes,
            "deduplicate" => $deduplicate,
            "position" => [
                "phase" => "splitting",
                "input_byte_offset" => 0,
                "run_count" => 0,
            ],
        ];
        $processor = new self($cursor, $key_extractor);
        $processor->open_input_for_splitting();
        return $processor;
    }

    /**
     * Resumes a sort from the last cursor stored by its owning caller.
     *
     * Output bytes beyond a merge cursor are discarded before another line is
     * consumed. A split run repeated from an older cursor replaces the same
     * deterministic run name.
     *
     * @param array<string,mixed>        $cursor        Cursor returned by get_cursor().
     * @param callable(string): ?string $key_extractor Same key extractor used by start().
     * @return self Open processor at the retained durable boundary.
     */
    public static function resume(array $cursor, callable $key_extractor): self
    {
        $processor = new self($cursor, $key_extractor);
        $position = $cursor["position"];
        if ($position["phase"] === "splitting") {
            $processor->open_input_for_splitting();
        } elseif (
            $position["phase"] === "merging"
            && $position["pair_index"] < self::merge_pair_count($position["input_run_count"])
        ) {
            $processor->open_merge_pair();
        }
        return $processor;
    }

    /**
     * Returns the exact cursor the caller must store after the latest step.
     *
     * @phpstan-return SortCursor
     */
    public function get_cursor(): array
    {
        return $this->cursor;
    }

    /**
     * Performs one bounded data step or one phase transition.
     *
     * @return bool Whether another step may be attempted. False is stable once complete.
     */
    public function next_step(): bool
    {
        $position = $this->cursor["position"];
        if ($position["phase"] === "complete") {
            return false;
        }
        if ($this->closed) {
            throw new LogicException("Cannot take a merge-sort step after close().");
        }

        switch ($position["phase"]) {
            case "splitting":
                $this->split_next_chunk();
                return true;

            case "merging":
                $this->merge_next_line_or_transition();
                return true;

            case "removing_input_round":
                $this->remove_next_input_run_or_transition();
                return true;

            case "publishing_empty":
                $this->publish_empty_output();
                return false;

            case "publishing":
                $this->publish_final_run();
                return false;
        }
    }

    /**
     * Closes retained handles without discarding cursor state or run files.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->close_input_handle();
        $this->close_merge_pair_handles();
        $this->closed = true;
    }

    /**
     * Initializes fields shared by start() and resume().
     *
     * @phpstan-param SortCursor $cursor
     * @param callable(string): ?string $key_extractor
     */
    private function __construct(array $cursor, callable $key_extractor)
    {
        $this->cursor = $cursor;
        $this->input_path = $cursor["input_path"];
        $this->output_path = $cursor["output_path"];
        $this->work_directory = $cursor["work_directory"];
        $this->chunk_bytes = $cursor["chunk_bytes"];
        $this->deduplicate = $cursor["deduplicate"];
        $this->key_extractor = $key_extractor;
    }

    /**
     * Reads and publishes one stable-sorted split run.
     *
     * Input bytes are settled only after the deterministic run has been
     * flushed and renamed. Empty and omitted lines still consume the split
     * budget and advance the input cursor.
     */
    private function split_next_chunk(): void
    {
        if (!is_resource($this->input_handle)) {
            $this->open_input_for_splitting();
        }

        /** @var SplittingPosition $position */
        $position = $this->cursor["position"];
        $chunk = [];
        $consumed_bytes = 0;
        $line_order = 0;
        $reached_eof = false;

        while ($consumed_bytes < $this->chunk_bytes) {
            $line_byte_offset = ftell($this->input_handle);
            if (!is_int($line_byte_offset)) {
                throw new RuntimeException("Failed to read the merge-sort input byte offset.");
            }
            if ($consumed_bytes === 0) {
                // One input line is the indivisible unit. An oversized first
                // line may exceed the chunk budget, but a later line must not
                // be materialized merely to discover that it belongs to the
                // next step.
                $raw_line = fgets($this->input_handle);
            } else {
                $remaining_bytes = $this->chunk_bytes - $consumed_bytes;
                $raw_line = fgets(
                    $this->input_handle,
                    $remaining_bytes + 1
                );
            }
            if ($raw_line === false) {
                if (!feof($this->input_handle)) {
                    throw new RuntimeException("Failed to read the merge-sort input.");
                }
                $reached_eof = true;
                break;
            }

            $raw_line_bytes = strlen($raw_line);
            if (
                $consumed_bytes > 0
                && substr($raw_line, -1) !== "\n"
            ) {
                $input_stat = fstat($this->input_handle);
                $input_byte_offset_after = ftell($this->input_handle);
                if (
                    $input_stat === false
                    || !is_int($input_byte_offset_after)
                ) {
                    throw new RuntimeException(
                        "Failed to inspect the merge-sort input."
                    );
                }
                if ($input_byte_offset_after === (int) $input_stat["size"]) {
                    $reached_eof = true;
                } else {
                    if (fseek($this->input_handle, $line_byte_offset) !== 0) {
                        throw new RuntimeException(
                            "Failed to retain the next merge-sort input line."
                        );
                    }
                    break;
                }
            }
            if (
                $consumed_bytes > 0
                && $consumed_bytes + $raw_line_bytes > $this->chunk_bytes
            ) {
                if (fseek($this->input_handle, $line_byte_offset) !== 0) {
                    throw new RuntimeException("Failed to retain the next merge-sort input line.");
                }
                break;
            }

            $consumed_bytes += $raw_line_bytes;
            $line = rtrim($raw_line, "\r\n");
            if ($line !== "") {
                $key = $this->extract_key($line);
                if ($key !== null) {
                    $chunk[] = [
                        "key" => $key,
                        "line" => $line,
                        "order" => $line_order,
                    ];
                    ++$line_order;
                }
            }

            if ($raw_line_bytes > $this->chunk_bytes) {
                break;
            }
        }

        if ($consumed_bytes === 0 && $reached_eof) {
            $this->close_input_handle();
            if ($position["run_count"] === 0) {
                $this->cursor["position"] = ["phase" => "publishing_empty"];
            } elseif ($position["run_count"] === 1) {
                $this->cursor["position"] = [
                    "phase" => "publishing",
                    "final_run" => $this->run_path(0, 0),
                ];
            } else {
                $this->cursor["position"] = [
                    "phase" => "merging",
                    "round" => 0,
                    "input_run_count" => $position["run_count"],
                    "pair_index" => 0,
                    "left_byte_offset" => 0,
                    "right_byte_offset" => 0,
                    "output_byte_offset" => 0,
                    "last_output_key_b64" => null,
                ];
            }
            return;
        }

        $run_count = $position["run_count"];
        if (count($chunk) > 0) {
            $this->publish_split_run($chunk, $run_count);
            ++$run_count;
        }
        $input_byte_offset = ftell($this->input_handle);
        if (!is_int($input_byte_offset)) {
            throw new RuntimeException("Failed to settle the merge-sort input byte offset.");
        }
        $this->cursor["position"] = [
            "phase" => "splitting",
            "input_byte_offset" => $input_byte_offset,
            "run_count" => $run_count,
        ];
    }

    /**
     * Stable-sorts and atomically publishes one split chunk.
     *
     * @param array<int,array{key:string,line:string,order:int}> $chunk Keyed input lines.
     * @param int                                                $run_index Deterministic run index.
     */
    private function publish_split_run(array $chunk, int $run_index): void
    {
        usort(
            $chunk,
            static function (array $left, array $right): int {
                $key_comparison = strcmp($left["key"], $right["key"]);
                if ($key_comparison !== 0) {
                    return $key_comparison;
                }
                return $left["order"] <=> $right["order"];
            }
        );

        $this->ensure_round_directory(0);
        $run_path = $this->run_path(0, $run_index);
        $swap_path = $run_path . ".tmp";
        $run_handle = fopen($swap_path, "wb");
        if (!is_resource($run_handle)) {
            throw new RuntimeException("Failed to open a merge-sort split run: {$swap_path}");
        }

        $has_previous_key = false;
        $previous_key = "";
        try {
            foreach ($chunk as $entry) {
                if (
                    $this->deduplicate
                    && $has_previous_key
                    && $entry["key"] === $previous_key
                ) {
                    continue;
                }
                $this->write_all($run_handle, $entry["line"] . "\n", "merge-sort split run");
                $has_previous_key = true;
                $previous_key = $entry["key"];
            }
            if (!fflush($run_handle)) {
                throw new RuntimeException("Failed to flush a merge-sort split run.");
            }
        } catch (Throwable $exception) {
            fclose($run_handle);
            @unlink($swap_path);
            throw $exception;
        }
        fclose($run_handle);

        if (!rename($swap_path, $run_path)) {
            @unlink($swap_path);
            throw new RuntimeException("Failed to publish a merge-sort split run: {$run_path}");
        }
    }

    /**
     * Consumes one line from the current pair, or advances one merge boundary.
     */
    private function merge_next_line_or_transition(): void
    {
        /** @var MergingPosition $position */
        $position = $this->cursor["position"];
        $pair_count = self::merge_pair_count($position["input_run_count"]);
        if ($position["pair_index"] >= $pair_count) {
            $this->close_merge_pair_handles();
            $this->cursor["position"] = [
                "phase" => "removing_input_round",
                "round" => $position["round"],
                "run_index" => 0,
                "next_run_count" => $pair_count,
            ];
            return;
        }

        if (!is_resource($this->left_run_handle) || !is_resource($this->output_run_handle)) {
            $this->open_merge_pair();
        }
        $this->load_merge_lookahead();
        if ($this->left_run_entry === null && $this->right_run_entry === null) {
            $this->close_merge_pair_handles();
            $this->cursor["position"] = [
                "phase" => "merging",
                "round" => $position["round"],
                "input_run_count" => $position["input_run_count"],
                "pair_index" => $position["pair_index"] + 1,
                "left_byte_offset" => 0,
                "right_byte_offset" => 0,
                "output_byte_offset" => 0,
                "last_output_key_b64" => null,
            ];
            return;
        }

        $consume_left = $this->right_run_entry === null
            || (
                $this->left_run_entry !== null
                && strcmp($this->left_run_entry["key"], $this->right_run_entry["key"]) <= 0
            );
        $entry = $consume_left ? $this->left_run_entry : $this->right_run_entry;
        if ($entry === null) {
            throw new LogicException("The selected merge-sort input line is missing.");
        }

        $last_output_key = $this->decode_last_output_key($position["last_output_key_b64"]);
        $write_entry = !$this->deduplicate
            || $position["last_output_key_b64"] === null
            || $entry["key"] !== $last_output_key;
        if ($write_entry) {
            $this->write_all(
                $this->output_run_handle,
                $entry["line"] . "\n",
                "merge-sort output run"
            );
        }
        if (!fflush($this->output_run_handle)) {
            throw new RuntimeException("Failed to flush a merge-sort output run.");
        }
        $output_byte_offset = ftell($this->output_run_handle);
        if (!is_int($output_byte_offset)) {
            throw new RuntimeException("Failed to settle a merge-sort output byte offset.");
        }

        $left_byte_offset = $position["left_byte_offset"];
        $right_byte_offset = $position["right_byte_offset"];
        if ($consume_left) {
            $left_byte_offset = $entry["byte_offset_after"];
            $this->left_run_entry = null;
            $this->left_run_entry_loaded = false;
        } else {
            $right_byte_offset = $entry["byte_offset_after"];
            $this->right_run_entry = null;
            $this->right_run_entry_loaded = false;
        }

        $this->cursor["position"] = [
            "phase" => "merging",
            "round" => $position["round"],
            "input_run_count" => $position["input_run_count"],
            "pair_index" => $position["pair_index"],
            "left_byte_offset" => $left_byte_offset,
            "right_byte_offset" => $right_byte_offset,
            "output_byte_offset" => $output_byte_offset,
            "last_output_key_b64" => $write_entry
                ? base64_encode($entry["key"])
                : $position["last_output_key_b64"],
        ];
    }

    /**
     * Removes one old deterministic run or advances to the next merge round.
     */
    private function remove_next_input_run_or_transition(): void
    {
        /** @var RemovingPosition $position */
        $position = $this->cursor["position"];
        // An odd input count leaves the final possible right-run name absent.
        // Trying at most twice the output count keeps that case deterministic
        // without storing another count in the removal cursor.
        $maximum_input_run_count = $position["next_run_count"] * 2;
        if ($position["run_index"] < $maximum_input_run_count) {
            $run_path = $this->run_path($position["round"], $position["run_index"]);
            if (is_file($run_path)) {
                if (!unlink($run_path)) {
                    throw new RuntimeException("Failed to remove an old merge-sort run: {$run_path}");
                }
            } elseif (file_exists($run_path)) {
                throw new RuntimeException("An old merge-sort run is not a file: {$run_path}");
            }
            $this->cursor["position"] = [
                "phase" => "removing_input_round",
                "round" => $position["round"],
                "run_index" => $position["run_index"] + 1,
                "next_run_count" => $position["next_run_count"],
            ];
            return;
        }

        $round_directory = $this->round_directory($position["round"]);
        if (is_dir($round_directory)) {
            if (!rmdir($round_directory)) {
                throw new RuntimeException(
                    "Failed to remove an empty merge-sort round: {$round_directory}"
                );
            }
        } elseif (file_exists($round_directory)) {
            throw new RuntimeException(
                "The merge-sort round path is not a directory: {$round_directory}"
            );
        }

        $next_round = $position["round"] + 1;
        if ($position["next_run_count"] === 1) {
            $this->cursor["position"] = [
                "phase" => "publishing",
                "final_run" => $this->run_path($next_round, 0),
            ];
            return;
        }
        $this->cursor["position"] = [
            "phase" => "merging",
            "round" => $next_round,
            "input_run_count" => $position["next_run_count"],
            "pair_index" => 0,
            "left_byte_offset" => 0,
            "right_byte_offset" => 0,
            "output_byte_offset" => 0,
            "last_output_key_b64" => null,
        ];
    }

    /**
     * Writes and atomically publishes an empty output file.
     */
    private function publish_empty_output(): void
    {
        $swap_path = $this->output_path . ".swap";
        $swap_handle = fopen($swap_path, "wb");
        if (!is_resource($swap_handle)) {
            throw new RuntimeException("Failed to open the empty merge-sort swap file: {$swap_path}");
        }
        if (!fflush($swap_handle)) {
            fclose($swap_handle);
            @unlink($swap_path);
            throw new RuntimeException("Failed to flush the empty merge-sort swap file.");
        }
        fclose($swap_handle);
        if (!rename($swap_path, $this->output_path)) {
            @unlink($swap_path);
            throw new RuntimeException("Failed to publish the empty merge-sort output.");
        }
        $this->cursor["position"] = ["phase" => "complete"];
    }

    /**
     * Atomically publishes the sole final run.
     *
     * A missing final run with an existing output means an earlier publishing
     * call completed its rename before the owning caller stored the cursor.
     */
    private function publish_final_run(): void
    {
        /** @var PublishingPosition $position */
        $position = $this->cursor["position"];
        $final_run = $position["final_run"];
        if (is_file($final_run)) {
            if (!rename($final_run, $this->output_path)) {
                throw new RuntimeException("Failed to publish the final merge-sort run.");
            }
        } elseif (!is_file($this->output_path)) {
            throw new RuntimeException(
                "The final merge-sort run and published output are both missing."
            );
        }
        $this->cursor["position"] = ["phase" => "complete"];
    }

    /**
     * Opens the immutable input at the split cursor's byte offset.
     */
    private function open_input_for_splitting(): void
    {
        /** @var SplittingPosition $position */
        $position = $this->cursor["position"];
        $this->input_handle = fopen($this->input_path, "rb");
        if (!is_resource($this->input_handle)) {
            throw new RuntimeException("Failed to open the merge-sort input: {$this->input_path}");
        }
        $this->seek_to_offset(
            $this->input_handle,
            $position["input_byte_offset"],
            "merge-sort input"
        );
    }

    /**
     * Opens and positions all handles for the current deterministic merge pair.
     */
    private function open_merge_pair(): void
    {
        /** @var MergingPosition $position */
        $position = $this->cursor["position"];
        $this->close_merge_pair_handles();

        $left_run_index = $position["pair_index"] * 2;
        $left_run_path = $this->run_path($position["round"], $left_run_index);
        $this->left_run_handle = fopen($left_run_path, "rb");
        if (!is_resource($this->left_run_handle)) {
            throw new RuntimeException("Failed to open the left merge-sort run: {$left_run_path}");
        }
        $this->seek_to_offset(
            $this->left_run_handle,
            $position["left_byte_offset"],
            "left merge-sort run"
        );

        $right_run_index = $left_run_index + 1;
        if ($right_run_index < $position["input_run_count"]) {
            $right_run_path = $this->run_path($position["round"], $right_run_index);
            $this->right_run_handle = fopen($right_run_path, "rb");
            if (!is_resource($this->right_run_handle)) {
                $this->close_merge_pair_handles();
                throw new RuntimeException("Failed to open the right merge-sort run: {$right_run_path}");
            }
            $this->seek_to_offset(
                $this->right_run_handle,
                $position["right_byte_offset"],
                "right merge-sort run"
            );
        }

        $this->ensure_round_directory($position["round"] + 1);
        $output_run_path = $this->run_path($position["round"] + 1, $position["pair_index"]);
        $this->output_run_handle = fopen($output_run_path, "c+b");
        if (!is_resource($this->output_run_handle)) {
            $this->close_merge_pair_handles();
            throw new RuntimeException("Failed to open a merge-sort output run: {$output_run_path}");
        }
        $output_stat = fstat($this->output_run_handle);
        if (!is_array($output_stat) || $output_stat["size"] < $position["output_byte_offset"]) {
            $this->close_merge_pair_handles();
            throw new RuntimeException(
                "The merge-sort output run is shorter than its durable byte offset."
            );
        }
        if (!ftruncate($this->output_run_handle, $position["output_byte_offset"])) {
            $this->close_merge_pair_handles();
            throw new RuntimeException("Failed to discard uncommitted merge-sort output bytes.");
        }
        $this->seek_to_offset(
            $this->output_run_handle,
            $position["output_byte_offset"],
            "merge-sort output run"
        );
        $this->left_run_entry = null;
        $this->left_run_entry_loaded = false;
        $this->right_run_entry = null;
        $this->right_run_entry_loaded = false;
    }

    /**
     * Reads at most one lookahead line from each input run.
     */
    private function load_merge_lookahead(): void
    {
        if (!$this->left_run_entry_loaded) {
            $this->left_run_entry = $this->read_run_entry(
                $this->left_run_handle,
                "left merge-sort run"
            );
            $this->left_run_entry_loaded = true;
        }
        if (!$this->right_run_entry_loaded) {
            $this->right_run_entry = $this->read_run_entry(
                $this->right_run_handle,
                "right merge-sort run"
            );
            $this->right_run_entry_loaded = true;
        }
    }

    /**
     * Reads one keyed line from a deterministic internal run.
     *
     * @param resource|null $handle  Open run handle, or null for an absent odd pair.
     * @param string        $description Human-readable run name for failures.
     * @return array{key:string,line:string,byte_offset_after:int}|null
     */
    private function read_run_entry($handle, string $description): ?array
    {
        if (!is_resource($handle)) {
            return null;
        }
        $raw_line = fgets($handle);
        if ($raw_line === false) {
            if (!feof($handle)) {
                throw new RuntimeException("Failed to read the {$description}.");
            }
            return null;
        }
        $line = rtrim($raw_line, "\r\n");
        if ($line === "") {
            throw new RuntimeException("The {$description} contains an empty line.");
        }
        $key = $this->extract_key($line);
        if ($key === null) {
            throw new RuntimeException("The {$description} contains a line without a sort key.");
        }
        $byte_offset_after = ftell($handle);
        if (!is_int($byte_offset_after)) {
            throw new RuntimeException("Failed to read the {$description} byte offset.");
        }
        return [
            "key" => $key,
            "line" => $line,
            "byte_offset_after" => $byte_offset_after,
        ];
    }

    /** Extracts one line's key. */
    private function extract_key(string $line): ?string
    {
        $extract_key = $this->key_extractor;
        return $extract_key($line);
    }

    /**
     * Decodes the preceding output key retained for cross-step deduplication.
     */
    private function decode_last_output_key(?string $last_output_key_b64): ?string
    {
        if ($last_output_key_b64 === null) {
            return null;
        }
        return base64_decode($last_output_key_b64);
    }

    /**
     * Closes the split input when its phase ends or the caller closes the processor.
     */
    private function close_input_handle(): void
    {
        if (is_resource($this->input_handle)) {
            fclose($this->input_handle);
        }
        $this->input_handle = null;
    }

    /**
     * Closes a merge pair and forgets both lookahead entries.
     */
    private function close_merge_pair_handles(): void
    {
        if (is_resource($this->left_run_handle)) {
            fclose($this->left_run_handle);
        }
        if (is_resource($this->right_run_handle)) {
            fclose($this->right_run_handle);
        }
        if (is_resource($this->output_run_handle)) {
            fclose($this->output_run_handle);
        }
        $this->left_run_handle = null;
        $this->right_run_handle = null;
        $this->output_run_handle = null;
        $this->left_run_entry = null;
        $this->left_run_entry_loaded = false;
        $this->right_run_entry = null;
        $this->right_run_entry_loaded = false;
    }

    /**
     * Seeks an open file only when its cursor is within the retained bytes.
     *
     * @param resource $handle      Open file.
     * @param int      $byte_offset Durable byte offset.
     * @param string   $description Human-readable file name for failures.
     */
    private function seek_to_offset($handle, int $byte_offset, string $description): void
    {
        $stat = fstat($handle);
        if (!is_array($stat) || $stat["size"] < $byte_offset) {
            throw new RuntimeException(
                "The {$description} is shorter than byte offset {$byte_offset}."
            );
        }
        if (fseek($handle, $byte_offset) !== 0) {
            throw new RuntimeException(
                "Failed to seek the {$description} to byte offset {$byte_offset}."
            );
        }
    }

    /**
     * Writes every byte or throws before a cursor can advance.
     *
     * @param resource $handle      Open output handle.
     * @param string   $bytes       Bytes to write.
     * @param string   $description Human-readable output name for failures.
     */
    private function write_all($handle, string $bytes, string $description): void
    {
        $written_bytes = 0;
        $byte_count = strlen($bytes);
        while ($written_bytes < $byte_count) {
            $written_now = fwrite($handle, substr($bytes, $written_bytes));
            if ($written_now === false || $written_now === 0) {
                throw new RuntimeException("Failed to write the {$description}.");
            }
            $written_bytes += $written_now;
        }
    }

    /**
     * Returns the deterministic path for one round.
     */
    private function round_directory(int $round): string
    {
        return $this->work_directory . "/" . sprintf("round-%06d", $round);
    }

    /**
     * Creates one deterministic round directory when its first output is opened.
     */
    private function ensure_round_directory(int $round): string
    {
        $round_directory = $this->round_directory($round);
        if (!is_dir($round_directory) && !mkdir($round_directory, 0755)) {
            throw new RuntimeException(
                "Failed to create a merge-sort round directory: {$round_directory}"
            );
        }
        return $round_directory;
    }

    /**
     * Returns the deterministic path for one run.
     */
    private function run_path(int $round, int $run_index): string
    {
        return $this->round_directory($round) . "/" . sprintf("run-%06d", $run_index);
    }

    /**
     * Returns how many pair outputs one input round produces.
     */
    private static function merge_pair_count(int $input_run_count): int
    {
        return intdiv($input_run_count + 1, 2);
    }
}
