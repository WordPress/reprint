<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-importer/src/lib/class-external-merge-sort-processor.php';

final class ExternalMergeSortProcessorTest extends TestCase {
    private string $tempDir;

    /** @var callable(string): ?string */
    private $keyExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/external-merge-sort-processor-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->keyExtractor = static function (string $line): ?string {
            if (strpos($line, 'skip:') === 0) {
                return null;
            }
            return explode(':', $line, 2)[0];
        };
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
        parent::tearDown();
    }

    public function testResumeAfterEveryStepMatchesOneOpenProcessorAcrossSeveralRounds(): void
    {
        $input = $this->multiRoundInput();
        $one_open = $this->runSort($input, "one-open", false);
        $resumed = $this->runSort($input, "resumed", true);

        $this->assertSame($one_open["output"], $resumed["output"]);
        $this->assertSame(
            "a:a0\nb:b0\nc:c0\nd:d0\ne:e0\nf:f0\ng:g0\n",
            $resumed["output"]
        );
        $this->assertGreaterThanOrEqual(3, max($resumed["merge_rounds"]));
        $this->assertContains("splitting", $resumed["phases"]);
        $this->assertContains("merging", $resumed["phases"]);
        $this->assertContains("removing_input_round", $resumed["phases"]);
        $this->assertContains("publishing", $resumed["phases"]);
    }

    public function testReplayingEveryStepFromItsPriorCursorDiscardsUncommittedBytes(): void
    {
        $scenario_directory = $this->tempDir . "/replayed";
        mkdir($scenario_directory, 0755, true);
        $input_path = $scenario_directory . "/input.txt";
        $output_path = $scenario_directory . "/output.txt";
        $work_directory = $scenario_directory . "/work";
        file_put_contents($input_path, $this->multiRoundInput());

        $processor = ExternalMergeSortProcessor::start(
            $input_path,
            $output_path,
            $work_directory,
            $this->keyExtractor,
            6,
            true
        );
        $step_count = 0;
        while (true) {
            $prior_cursor = $processor->get_cursor();

            // The first call represents a process stopping after its file
            // action but before its owning caller stored the returned cursor.
            $processor->next_step();
            $processor->close();

            $processor = ExternalMergeSortProcessor::resume(
                $prior_cursor,
                $this->keyExtractor
            );
            $has_next_step = $processor->next_step();
            ++$step_count;
            $this->assertLessThan(1000, $step_count);
            if (!$has_next_step) {
                break;
            }

            $stored_cursor = $processor->get_cursor();
            $processor->close();
            $processor = ExternalMergeSortProcessor::resume(
                $stored_cursor,
                $this->keyExtractor
            );
        }

        $this->assertSame(
            "a:a0\nb:b0\nc:c0\nd:d0\ne:e0\nf:f0\ng:g0\n",
            file_get_contents($output_path)
        );
        $this->assertSame("complete", $processor->get_cursor()["position"]["phase"]);
        $this->assertFalse($processor->next_step());
        $processor->close();
        $processor->close();
    }

    public function testEqualKeysRemainInInputOrderWhenDeduplicationIsDisabled(): void
    {
        $result = $this->runSort($this->multiRoundInput(), "stable", true, false);

        $this->assertSame(
            "a:a0\na:a1\na:a2\n"
            . "b:b0\nb:b1\n"
            . "c:c0\nc:c1\n"
            . "d:d0\nd:d1\n"
            . "e:e0\ne:e1\n"
            . "f:f0\nf:f1\n"
            . "g:g0\ng:g1\n",
            $result["output"]
        );
    }

    public function testEmptyInputIsPublishedAtomicallyAndCompleteIsStable(): void
    {
        $scenario_directory = $this->tempDir . "/empty";
        mkdir($scenario_directory, 0755, true);
        $input_path = $scenario_directory . "/input.txt";
        $output_path = $scenario_directory . "/output.txt";
        file_put_contents($input_path, "");
        file_put_contents($output_path, "old output\n");

        $processor = ExternalMergeSortProcessor::start(
            $input_path,
            $output_path,
            $scenario_directory . "/work",
            $this->keyExtractor,
            4,
            true
        );
        $this->assertTrue($processor->next_step());
        $this->assertSame("publishing_empty", $processor->get_cursor()["position"]["phase"]);
        $this->assertSame("old output\n", file_get_contents($output_path));
        $this->assertFalse($processor->next_step());
        $this->assertSame("", file_get_contents($output_path));
        $this->assertFalse($processor->next_step());
        $processor->close();
    }

    /**
     * Runs one sort, optionally closing and resuming after every stored step.
     *
     * @return array{output:string,phases:list<string>,merge_rounds:list<int>}
     */
    private function runSort(
        string $input,
        string $scenario,
        bool $resume_after_every_step,
        bool $deduplicate = true
    ): array {
        $scenario_directory = $this->tempDir . "/" . $scenario;
        mkdir($scenario_directory, 0755, true);
        $input_path = $scenario_directory . "/input.txt";
        $output_path = $scenario_directory . "/output.txt";
        file_put_contents($input_path, $input);

        $processor = ExternalMergeSortProcessor::start(
            $input_path,
            $output_path,
            $scenario_directory . "/work",
            $this->keyExtractor,
            6,
            $deduplicate
        );
        $phases = [];
        $merge_rounds = [];
        $step_count = 0;
        while (true) {
            $cursor = $processor->get_cursor();
            $phase = $cursor["position"]["phase"];
            $phases[] = $phase;
            if ($phase === "merging") {
                $merge_rounds[] = $cursor["position"]["round"];
            }
            $has_next_step = $processor->next_step();
            ++$step_count;
            $this->assertLessThan(1000, $step_count);
            if (!$has_next_step) {
                break;
            }
            if ($resume_after_every_step) {
                $cursor = $processor->get_cursor();
                $this->assertArrayNotHasKey("runs", $cursor);
                $processor->close();
                $processor = ExternalMergeSortProcessor::resume(
                    $cursor,
                    $this->keyExtractor
                );
            }
        }
        $processor->close();

        $output = file_get_contents($output_path);
        $this->assertIsString($output);
        return [
            "output" => $output,
            "phases" => $phases,
            "merge_rounds" => $merge_rounds,
        ];
    }

    /**
     * Returns enough one-line chunks to require four pairwise merge rounds.
     */
    private function multiRoundInput(): string
    {
        return "g:g0\n"
            . "a:a0\n"
            . "f:f0\n"
            . "b:b0\n"
            . "e:e0\n"
            . "c:c0\n"
            . "d:d0\n"
            . "a:a1\n"
            . "g:g1\n"
            . "c:c1\n"
            . "b:b1\n"
            . "f:f1\n"
            . "e:e1\n"
            . "d:d1\n"
            . "a:a2\n";
    }

    private function deleteTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                $this->deleteTree($path . "/" . $entry);
            }
        }
        rmdir($path);
    }
}
