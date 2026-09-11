<?php
/**
 * Time one corpus against an explicit checkout or PHAR. The harness and inputs
 * stay outside that build, so both sides use exactly the same benchmark.
 * Usage: php bench-url-rewrite.php /absolute/path/to/reprint.phar blocks-nested-html
 */

require __DIR__ . '/url-rewrite-fixtures.php';
if (( $argv[1] ?? '' ) === '--list') {
    echo json_encode(REPRINT_URL_REWRITE_BENCHMARK_CASES) . "\n";
    exit;
}
reprint_benchmark_url_rewrite($argv[1] ?? '', $argv[2] ?? '');

/** Load one build and report five checked samples for one named corpus. */
function reprint_benchmark_url_rewrite(string $build_path, string $scenario): void
{
    $build = realpath($build_path);
    if (!$build) {
        throw new InvalidArgumentException('The URL benchmark needs an existing checkout or PHAR path.');
    }
    $root = is_dir($build) ? $build : 'phar://' . $build;
    require $root . '/vendor/autoload.php';
    require $root . '/packages/reprint-client/src/lib/url-rewrite/load.php';

    // Fixed-size corpus; generation, loading PHP and output checks are not timed.
    // Each sample uses fresh caches, then reuses one rewriter across distinct rows,
    // as SQL apply does. Repeating one value would mostly benchmark cache hits.
    $fixtures = [];
    $input_bytes = 0;
    for ($row = 0; $row < 128; ++$row) {
        $fixture = reprint_url_rewrite_benchmark_fixture($scenario, $row);
        $input_bytes += strlen($fixture['input']);
        $fixtures[] = $fixture;
    }
    $samples = [];
    for ($sample = 0; $sample < 5; ++$sample) {
        $outputs = [];
        $start = hrtime(true);
        $rewriter = new StructuredDataUrlRewriter(['https://source.example' => 'https://destination.example']);
        foreach ($fixtures as $fixture) {
            $outputs[] = $scenario === 'serialized-options'
                ? $rewriter->rewrite($fixture['input'])
                : $rewriter->rewrite_known_block_markup_value($fixture['input']);
        }
        $samples[] = ( hrtime(true) - $start ) / 1e6;
        foreach ($fixtures as $row => $fixture) {
            reprint_check_url_rewrite_benchmark_output($outputs[$row], $fixture['expected']);
        }
        unset($rewriter, $outputs);
    }
    $sorted_samples = $samples;
    sort($sorted_samples);
    echo json_encode([
        'stage' => 'url-rewrite-' . $scenario,
        'elapsedMs' => $sorted_samples[2],
        'samplesMs' => $samples,
        'attempts' => 1,
        'ok' => true,
        'phpVersion' => PHP_VERSION,
        'details' => [
            'rows' => count($fixtures),
            'input_MiB' => round($input_bytes / 1048576, 2),
            'MiB_per_second' => round($input_bytes / 1048576 / ( $sorted_samples[2] / 1000 ), 2),
            'sample_ms' => implode(', ', array_map(static function ($ms) { return round($ms, 1); }, $samples)),
            'peak_MiB' => round(memory_get_peak_usage(true) / 1048576, 2),
        ],
        'implementation' => ( new ReflectionClass(StructuredDataUrlRewriter::class) )->getFileName(),
    ], JSON_THROW_ON_ERROR) . "\n";
}
