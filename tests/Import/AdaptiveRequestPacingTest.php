<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Shared importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Tuning\AdaptiveTuner;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/tuning/class-adaptive-tuner.php';

final class AdaptiveRequestPacingTest extends TestCase {
    public function testRateLimitSlowsRequestsWithoutShrinkingBatches(): void {
        $tuner = new AdaptiveTuner([]);
        $before = $tuner->get_state();
        $result = $tuner->tune_after_error('file_index', ['http_code' => 429]);

        $this->assertSame($before['index_batch_size'], $tuner->get_state()['index_batch_size']);
        $this->assertSame($before['error_backoff_remaining'], $tuner->get_state()['error_backoff_remaining']);
        $this->assertSame('rate_limited', $result['decision']);
        $this->assertSame(1.0, $result['request_interval_seconds']);
    }

    public function testRepeatedRateLimitsDoubleTheGapAcrossEndpointsAndBoundIt(): void {
        $tuner = new AdaptiveTuner([]);
        $tuner->record_request_start(1000.0);
        foreach (['file_index' => 1.0, 'file_fetch' => 2.0, 'sql_chunk' => 4.0, 'db_index' => 8.0] as $endpoint => $interval) {
            $tuner->tune_after_error($endpoint, ['http_code' => 429]);
            $this->assertSame($interval, $tuner->get_request_wait_seconds(1000.0));
        }
        for ($failure = 0; $failure < 10; ++$failure) {
            $tuner->tune_after_error('file_index', ['http_code' => 429]);
        }
        $this->assertSame(60.0, $tuner->get_request_wait_seconds(1000.0));
    }

    public function testSuccessfulCompletionWithoutServerTimingKeepsTheLearnedGap(): void {
        $tuner = new AdaptiveTuner([]);
        $tuner->record_request_start(1000.0);
        $tuner->tune_after_error('file_index', ['http_code' => 429]);
        $tuner->tune_after_response('file_fetch', ['status' => 'complete', 'wall_time' => 0.05]);
        $this->assertEqualsWithDelta(0.95, $tuner->get_request_wait_seconds(1000.05), 0.0001);
    }

    public function testTransferTimeAndExistingWaitsCountTowardTheGap(): void {
        $tuner = new AdaptiveTuner([]);
        $tuner->record_request_start(1000.0);
        $tuner->tune_after_error('file_index', ['http_code' => 429]);
        $tuner->tune_after_error('file_index', ['http_code' => 429]);
        $this->assertSame(1.5, $tuner->get_request_wait_seconds(1000.5));
        $this->assertSame(0.0, $tuner->get_request_wait_seconds(1002.0));
        $this->assertSame(0.0, $tuner->get_request_wait_seconds(1100.0));
    }

    public function testStateRoundTripKeepsTheGapAndLastRequestStart(): void {
        $tuner = new AdaptiveTuner([]);
        $tuner->record_request_start(1000.0);
        $tuner->tune_after_error('file_index', ['http_code' => 429]);
        $resumed = new AdaptiveTuner($tuner->get_config(), $tuner->get_state());
        $this->assertSame(0.5, $resumed->get_request_wait_seconds(1000.5));
        $this->assertSame(0.0, ( new AdaptiveTuner([]) )->get_request_wait_seconds(1000.5));
    }

    public function testNoAdaptiveDisablesPacingWithoutDiscardingTheLearnedGap(): void {
        $state = ['request_interval_seconds' => 4.0, 'last_request_started_at' => 1000.0];
        $tuner = new AdaptiveTuner(['enabled' => false], $state);
        $tuner->tune_after_error('file_index', ['http_code' => 429]);
        $this->assertSame(0.0, $tuner->get_request_wait_seconds(1000.0));
        $this->assertSame(4.0, $tuner->get_state()['request_interval_seconds']);
    }

    public function testOtherFailuresKeepExistingSizingAndDoNotSetARequestGap(): void {
        $tuner = new AdaptiveTuner([]);
        $tuner->record_request_start(1000.0);
        $tuner->tune_after_error('file_index', ['http_code' => 503]);
        $this->assertSame(2500, $tuner->get_state()['index_batch_size']);
        $this->assertSame(0.0, $tuner->get_request_wait_seconds(1000.0));
    }
}
