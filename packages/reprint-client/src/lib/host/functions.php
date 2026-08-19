<?php
/**
 * Host analyzer functions.
 *
 * Registry, detection logic, and shared preflight extraction helpers.
 */

use function WordPress\Reprint\Server\path_is_same_as_or_descendant_of;
use function WordPress\Reprint\Server\trim_right_slash;

/**
 * All known host analyzers.
 *
 * @return array<string, class-string<HostAnalyzer>>
 */
function host_analyzer_registry(): array
{
    return [
        'wpcloud' => WpcloudHostAnalyzer::class,
        'siteground' => SitegroundHostAnalyzer::class,
    ];
}

/**
 * Detect the source host from preflight data using likelihood scoring.
 *
 * Each registered host analyzer scores the preflight data independently.
 * The host with the highest score wins, provided it reaches the minimum
 * threshold of 0.5. Returns "other" if no host qualifies.
 */
function detect_host(array $preflight_data): string
{
    $threshold = 0.5;
    $best_host = 'other';
    $best_score = 0.0;

    foreach (host_analyzer_registry() as $name => $class) {
        $score = $class::score($preflight_data);
        if ($score >= $threshold && $score > $best_score) {
            $best_host = $name;
            $best_score = $score;
        }
    }

    return $best_host;
}

/**
 * Instantiate the right analyzer for a detected host name.
 */
function host_analyzer_for(string $webhost): HostAnalyzer
{
    $registry = host_analyzer_registry();
    if (isset($registry[$webhost])) {
        return new $registry[$webhost]();
    }
    return new DefaultHostAnalyzer();
}

/**
 * Extract selected INI directives from preflight's ini_get_all.
 * Only includes values that are likely to affect whether a migrated
 * site works or breaks.
 */
function extract_php_ini(array $preflight_data): array
{
    $ini_all = $preflight_data['runtime']['ini_get_all'] ?? [];
    if (empty($ini_all)) {
        return [];
    }

    $interesting_keys = [
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'max_input_vars',
        'max_input_time',
    ];

    $result = [];
    foreach ($interesting_keys as $key) {
        if (isset($ini_all[$key]) && $ini_all[$key] !== '') {
            $result[$key] = (string) $ini_all[$key];
        }
    }
    return $result;
}

/**
 * Extract PHP constants from preflight that need to be defined on the
 * target. Reads paths_urls from the preflight response.
 *
 * Returns only constants where the source value is a path that differs
 * from the standard WordPress layout (meaning WordPress won't derive
 * the right value on its own).
 */
function extract_constants(array $preflight_data): array
{
    $paths_urls = $preflight_data['database']['wp']['paths_urls'] ?? [];
    $abspath = $paths_urls['abspath'] ?? '';
    if ($abspath !== '') {
        $abspath = trim_right_slash($abspath);
    }
    $content_dir = $paths_urls['content_dir'] ?? '';

    $result = [];

    // WP_CONTENT_DIR: if wp-content lives outside ABSPATH on the source
    // (e.g. wpcloud has ABSPATH at /wordpress/core/X.Y.Z/ but wp-content
    // at /srv/htdocs/wp-content), we need to explicitly set it.
    if (
        $content_dir !== ''
        && $abspath !== ''
        && !path_is_same_as_or_descendant_of($content_dir, $abspath)
    ) {
        $result['WP_CONTENT_DIR'] = '{fs-root}/wp-content';
    }

    return $result;
}
