<?php
/**
 * Interface for host analyzers.
 *
 * A host analyzer does two things:
 *
 * 1. Scores how likely it is that the source site runs on its hosting
 *    platform, based on preflight data (the static score() method).
 * 2. Reads preflight data and produces a RuntimeConfiguration describing
 *    what the site needs to run (the analyze() method).
 *
 * detect_host() selects the primary source label. runtime_configuration_for()
 * uses that same current-host match to choose the target configuration.
 * Plugin exclusions are calculated separately by excluded_plugins().
 */
interface HostAnalyzer
{
    /**
     * Score how likely this host matches the given preflight data.
     *
     * Examine preflight signals relevant to your platform and return a
     * float between 0.0 (no match) and 1.0 (certain match).
     * A score >= 0.5 is considered a viable candidate.
     *
     * @param array $preflight_data The preflight response data.
     * @return float Likelihood score between 0.0 and 1.0.
     */
    public static function score(array $preflight_data): float;

    /**
     * Analyze preflight data and produce a runtime configuration.
     *
     * @param array $preflight_data The preflight response data.
     * @return RuntimeConfiguration
     */
    public function analyze(array $preflight_data): RuntimeConfiguration;
}
