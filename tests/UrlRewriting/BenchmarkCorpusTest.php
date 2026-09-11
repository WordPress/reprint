<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../packages/reprint-client/src/lib/url-rewrite/load.php';
require_once __DIR__ . '/../e2e/benchmark/url-rewrite-fixtures.php';

class BenchmarkCorpusTest extends TestCase {
    /** Each measured case must produce its complete expected target value. */
    #[DataProvider('benchmarkCases')]
    public function testBenchmarkCorpusRewritesWithoutLosingContent(string $scenario): void
    {
        $fixture = reprint_url_rewrite_benchmark_fixture($scenario, 7);
        $rewriter = new StructuredDataUrlRewriter(['https://source.example' => 'https://destination.example']);
        $output = $scenario === 'serialized-options'
            ? $rewriter->rewrite($fixture['input'])
            : $rewriter->rewrite_known_block_markup_value($fixture['input']);

        reprint_check_url_rewrite_benchmark_output($output, $fixture['expected']);
        $next = reprint_url_rewrite_benchmark_fixture($scenario, 8);
        $this->assertNotSame($fixture['input'], $next['input'], 'Rows must not become whole-value cache hits.');
    }

    /** Skipped rewrites and changed non-URL text cannot earn a fast result. */
    #[DataProvider('benchmarkCases')]
    public function testBenchmarkRejectsIncorrectOutput(string $scenario): void
    {
        $fixture = reprint_url_rewrite_benchmark_fixture($scenario, 7);
        $incorrect = $scenario === 'blocks-no-source-urls'
            ? str_replace('Article', 'Changed', $fixture['input'])
            : $fixture['input'];
        $this->expectException(RuntimeException::class);
        reprint_check_url_rewrite_benchmark_output($incorrect, $fixture['expected']);
    }

    /** Duplicate or omitted blocks are invalid even when their URLs are correct. */
    public function testBenchmarkRejectsDuplicateAndMissingBlocks(): void
    {
        $fixture = reprint_url_rewrite_benchmark_fixture('blocks-no-source-urls', 7);
        foreach (['', $fixture['input'] . $fixture['input']] as $output) {
            try {
                reprint_check_url_rewrite_benchmark_output($output, $fixture['expected']);
                $this->fail('The benchmark accepted missing or duplicate blocks.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('expected target content', $error->getMessage());
            }
        }
    }

    /** Keep the runner's case list and the corpus checks aligned. */
    public static function benchmarkCases(): array
    {
        $output = [];
        foreach (REPRINT_URL_REWRITE_BENCHMARK_CASES as $scenario) {
            $output[$scenario] = [$scenario];
        }
        return $output;
    }
}
