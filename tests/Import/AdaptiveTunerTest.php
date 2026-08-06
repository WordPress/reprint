<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use Reprint\Importer\Tuning\AdaptiveTuner;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/tuning/class-adaptive-tuner.php';

class AdaptiveTunerTest extends TestCase
{
    private function makeTuner(array $config = [], array $state = []): AdaptiveTuner
    {
        return new AdaptiveTuner(array_merge([
            "max_execution_time" => 5,
            "duty" => 0.5,
        ], $config), $state);
    }

    /**
     * Simulate N successful requests and return the tuner + last result.
     */
    private function runRequests(
        AdaptiveTuner $tuner,
        string $endpoint,
        int $count,
        float $serverTime = 1.0,
        int $workDone = 1000
    ): array {
        $workKey = [
            "file_fetch" => "bytes_processed",
            "file_index" => "entries_processed",
            "sql_chunk" => "sql_bytes",
        ][$endpoint];

        $result = null;
        for ($i = 0; $i < $count; $i++) {
            $result = $tuner->record_result($endpoint, [
                "wall_time" => $serverTime + 0.1,
                "server_time" => $serverTime,
                "status" => "continue",
                $workKey => $workDone,
            ]);
        }
        return [$tuner, $result];
    }

    // ---------------------------------------------------------------
    // Initialization
    // ---------------------------------------------------------------

    public function testDefaultsApplied(): void
    {
        $tuner = $this->makeTuner();
        $config = $tuner->get_config();
        $state = $tuner->get_state();

        $this->assertTrue($config["enabled"]);
        $this->assertSame(5, $config["max_execution_time"]);
        $this->assertSame(0.5, $config["duty"]);
        $this->assertSame(5 * 1024 * 1024, $state["file_chunk_size"]);
        $this->assertSame(5000, $state["index_batch_size"]);
        $this->assertSame(1000, $state["sql_fragments_per_batch"]);
        $this->assertNull($state["file_throughput_ema"]);
        $this->assertSame(0, $state["error_backoff_remaining"]);
    }

    public function testStartSizesUsedAsInitialState(): void
    {
        $tuner = $this->makeTuner([
            "file_chunk_start" => 1024,
            "file_chunk_min" => 100,
            "index_batch_start" => 200,
            "index_batch_min" => 50,
            "sql_fragments_start" => 50,
            "sql_fragments_min" => 10,
        ]);
        $state = $tuner->get_state();

        $this->assertSame(1024, $state["file_chunk_size"]);
        $this->assertSame(200, $state["index_batch_size"]);
        $this->assertSame(50, $state["sql_fragments_per_batch"]);
    }

    public function testRestoredStateClamped(): void
    {
        $tuner = $this->makeTuner(
            ["file_chunk_min" => 1000, "file_chunk_max" => 5000],
            ["file_chunk_size" => 999999],
        );
        $this->assertSame(5000, $tuner->get_state()["file_chunk_size"]);

        $tuner2 = $this->makeTuner(
            ["file_chunk_min" => 1000, "file_chunk_max" => 5000],
            ["file_chunk_size" => 1],
        );
        $this->assertSame(1000, $tuner2->get_state()["file_chunk_size"]);
    }

    // ---------------------------------------------------------------
    // Request params
    // ---------------------------------------------------------------

    public function testRequestParamsIncludeEndpointSize(): void
    {
        $tuner = $this->makeTuner(["file_chunk_start" => 2048, "file_chunk_min" => 100]);
        $params = $tuner->get_request_params("file_fetch");

        $this->assertSame(2048, $params["chunk_size"]);
        $this->assertSame(5, $params["max_execution_time"]);
        $this->assertArrayHasKey("memory_threshold", $params);
        $this->assertArrayNotHasKey("batch_size", $params);
    }

    public function testIndexParamsIncludeBatchSize(): void
    {
        $tuner = $this->makeTuner(["index_batch_start" => 2000]);
        $params = $tuner->get_request_params("file_index");

        $this->assertSame(2000, $params["batch_size"]);
        $this->assertArrayNotHasKey("chunk_size", $params);
    }

    public function testRequestParamsUnknownEndpoint(): void
    {
        $tuner = $this->makeTuner();
        $params = $tuner->get_request_params("nonexistent");

        $this->assertArrayHasKey("max_execution_time", $params);
        $this->assertArrayHasKey("memory_threshold", $params);
        $this->assertArrayNotHasKey("chunk_size", $params);
        $this->assertCount(2, $params);
    }

    public function testSqlParamsIncludeDbOptions(): void
    {
        $tuner = $this->makeTuner([
            "sql_fragments_start" => 500,
            "db_unbuffered" => true,
            "db_query_time_limit" => 3,
        ]);
        $params = $tuner->get_request_params("sql_chunk");

        $this->assertSame(500, $params["fragments_per_batch"]);
        $this->assertSame(1, $params["db_unbuffered"]);
        $this->assertSame(3, $params["db_query_time_limit"]);
    }

    // ---------------------------------------------------------------
    // AIMD: additive increase
    // ---------------------------------------------------------------

    public function testAdditiveIncreaseAfterWarmup(): void
    {
        $tuner = $this->makeTuner([
            "file_chunk_start" => 1000,
            "file_chunk_min" => 100,
            "aimd_increase_file_bytes" => 100,
            "file_chunk_max" => 100000,
        ]);

        // First request seeds the EMA (warmup).
        [$tuner, $r1] = $this->runRequests($tuner, "file_fetch", 1);
        $this->assertSame("warmup", $r1["decision"]);
        $this->assertSame(1000, $tuner->get_state()["file_chunk_size"]);

        // Second request should increase.
        [$tuner, $r2] = $this->runRequests($tuner, "file_fetch", 1);
        $this->assertSame("increase", $r2["decision"]);
        $this->assertSame(1100, $tuner->get_state()["file_chunk_size"]);
    }

    public function testSizeNeverExceedsMax(): void
    {
        $tuner = $this->makeTuner([
            "file_chunk_start" => 9900,
            "file_chunk_min" => 100,
            "file_chunk_max" => 10000,
            "aimd_increase_file_bytes" => 500,
        ]);

        [$tuner] = $this->runRequests($tuner, "file_fetch", 20);
        $this->assertLessThanOrEqual(10000, $tuner->get_state()["file_chunk_size"]);
    }

    // ---------------------------------------------------------------
    // AIMD: multiplicative decrease on throughput drop
    // ---------------------------------------------------------------

    public function testDecreasesOnThroughputDrop(): void
    {
        $tuner = $this->makeTuner([
            "file_chunk_start" => 5000,
            "aimd_drop_ratio" => 0.9,
            "aimd_decrease_factor" => 0.5,
            "file_chunk_min" => 100,
        ]);

        // Warmup + one good request to establish EMA.
        [$tuner] = $this->runRequests($tuner, "file_fetch", 2, 1.0, 10000);
        $sizeBefore = $tuner->get_state()["file_chunk_size"];

        // Now simulate much lower throughput (same time, way less work).
        $result = $tuner->record_result("file_fetch", [
            "server_time" => 1.0,
            "wall_time" => 1.1,
            "status" => "continue",
            "bytes_processed" => 100, // 1% of previous
        ]);

        $this->assertSame("decrease", $result["decision"]);
        $expectedFileChunkSize = (int) round($sizeBefore * 0.5);
        $this->assertSame($expectedFileChunkSize, $tuner->get_state()["file_chunk_size"]);
    }

    public function testSizeNeverDropsBelowMin(): void
    {
        $tuner = $this->makeTuner([
            "file_chunk_start" => 500,
            "file_chunk_min" => 400,
            "aimd_decrease_factor" => 0.1,
        ]);

        // Warmup.
        [$tuner] = $this->runRequests($tuner, "file_fetch", 2, 1.0, 10000);

        // Trigger many decreases.
        for ($i = 0; $i < 10; $i++) {
            $tuner->record_result("file_fetch", [
                "server_time" => 1.0,
                "wall_time" => 1.1,
                "status" => "continue",
                "bytes_processed" => 1,
            ]);
        }

        $this->assertGreaterThanOrEqual(400, $tuner->get_state()["file_chunk_size"]);
    }

    // ---------------------------------------------------------------
    // Error backoff
    // ---------------------------------------------------------------

    public function testErrorTriggersBackoffAndShrink(): void
    {
        $tuner = $this->makeTuner([
            "file_chunk_start" => 5000,
            "error_decrease_factor" => 0.5,
            "error_backoff_requests" => 3,
            "file_chunk_min" => 100,
        ]);

        $result = $tuner->record_error("file_fetch", [
            "http_code" => 500,
            "timeout" => false,
        ]);

        $this->assertSame("backoff", $result["decision"]);
        $this->assertSame(3, $result["error_backoff_remaining"]);
        $this->assertSame(2500, $tuner->get_state()["file_chunk_size"]);
    }

    public function testErrorBackoffDecays(): void
    {
        $tuner = $this->makeTuner(["error_backoff_requests" => 3]);
        $tuner->record_error("file_fetch", ["http_code" => 500]);

        $this->assertSame(3, $tuner->get_state()["error_backoff_remaining"]);

        // Each record_result should decrement.
        [$tuner, $r] = $this->runRequests($tuner, "file_fetch", 1, 1.0, 1000);
        $this->assertSame(2, $tuner->get_state()["error_backoff_remaining"]);

        [$tuner] = $this->runRequests($tuner, "file_fetch", 2, 1.0, 1000);
        $this->assertSame(0, $tuner->get_state()["error_backoff_remaining"]);
    }

    public function testHoldsSteadyDuringBackoff(): void
    {
        $tuner = $this->makeTuner(["error_backoff_requests" => 5]);

        // Warmup first so we have EMA.
        [$tuner] = $this->runRequests($tuner, "file_fetch", 2, 1.0, 10000);

        $tuner->record_error("file_fetch", ["http_code" => 503]);
        $sizeAfterError = $tuner->get_state()["file_chunk_size"];

        // Next successful request should hold steady, not increase.
        [$tuner, $r] = $this->runRequests($tuner, "file_fetch", 1, 1.0, 10000);
        $this->assertSame("error_backoff", $r["decision"]);
        $this->assertSame($sizeAfterError, $tuner->get_state()["file_chunk_size"]);
    }

    public function testTimeoutTriggersBackoff(): void
    {
        $tuner = $this->makeTuner();
        $result = $tuner->record_error("file_fetch", [
            "http_code" => 0,
            "timeout" => true,
        ]);
        $this->assertSame("backoff", $result["decision"]);
    }

    public function testLowStatusCodeIgnored(): void
    {
        $tuner = $this->makeTuner();
        $result = $tuner->record_error("file_fetch", [
            "http_code" => 301,
            "timeout" => false,
        ]);
        $this->assertSame("ignore", $result["decision"]);
    }

    // ---------------------------------------------------------------
    // Sleep / duty cycle
    // ---------------------------------------------------------------

    public function testSleepComputedFromDuty(): void
    {
        $tuner = $this->makeTuner(["duty" => 0.5, "min_sleep" => 0.0]);

        [$tuner, $r] = $this->runRequests($tuner, "file_fetch", 1, 2.0, 1000);

        // duty=0.5 means sleep should equal elapsed time (2s work → 2s sleep).
        $this->assertGreaterThan(0.0, $r["sleep_seconds"]);
        $this->assertEqualsWithDelta(2.0, $r["sleep_seconds"], 0.01);
    }

    public function testNoSleepOnComplete(): void
    {
        $tuner = $this->makeTuner(["duty" => 0.5]);

        $result = $tuner->record_result("file_fetch", [
            "server_time" => 2.0,
            "wall_time" => 2.1,
            "status" => "complete",
            "bytes_processed" => 1000,
        ]);

        $this->assertSame(0.0, $result["sleep_seconds"]);
    }

    public function testFullDutyNoSleep(): void
    {
        $tuner = $this->makeTuner(["duty" => 1.0, "duty_min" => 1.0]);

        [$tuner, $r] = $this->runRequests($tuner, "file_fetch", 1, 2.0, 1000);
        $this->assertSame(0.0, $r["sleep_seconds"]);
    }

    // ---------------------------------------------------------------
    // Disabled tuner
    // ---------------------------------------------------------------

    public function testDisabledReturnsNoSleep(): void
    {
        $tuner = $this->makeTuner(["enabled" => false]);

        $result = $tuner->record_result("file_fetch", [
            "server_time" => 2.0,
            "wall_time" => 2.1,
            "status" => "continue",
            "bytes_processed" => 1000,
        ]);

        $this->assertSame("disabled", $result["decision"]);
        $this->assertSame(0.0, $result["sleep_seconds"]);
    }

    // ---------------------------------------------------------------
    // All three endpoints tune independently
    // ---------------------------------------------------------------

    public function testAllEndpointsTuneIndependently(): void
    {
        $tuner = $this->makeTuner([
            "file_chunk_start" => 1000,
            "file_chunk_min" => 100,
            "index_batch_start" => 2000,
            "index_batch_min" => 100,
            "sql_fragments_start" => 300,
            "sql_fragments_min" => 50,
        ]);

        $tuner->get_request_params("file_fetch");
        $tuner->get_request_params("file_index");
        $tuner->get_request_params("sql_chunk");

        // Run two requests on file_fetch only (warmup + increase).
        [$tuner] = $this->runRequests($tuner, "file_fetch", 2, 1.0, 5000);

        // file_chunk_size should have changed, others should not.
        $state = $tuner->get_state();
        $this->assertNotSame(1000, $state["file_chunk_size"]);
        $this->assertSame(2000, $state["index_batch_size"]);
        $this->assertSame(300, $state["sql_fragments_per_batch"]);
    }

    // ---------------------------------------------------------------
    // State persistence round-trip
    // ---------------------------------------------------------------

    public function testStateRoundTrip(): void
    {
        $tuner = $this->makeTuner(["file_chunk_start" => 2000]);

        // Run a few requests to mutate state.
        [$tuner] = $this->runRequests($tuner, "file_fetch", 3, 1.0, 5000);

        $savedState = $tuner->get_state();

        // Create a new tuner from the saved state.
        $tuner2 = $this->makeTuner(["file_chunk_start" => 2000], $savedState);

        $this->assertSame($savedState, $tuner2->get_state());
    }

    // ---------------------------------------------------------------
    // No work done
    // ---------------------------------------------------------------

    public function testNoWorkDoneReportsNoWork(): void
    {
        $tuner = $this->makeTuner();

        $result = $tuner->record_result("file_fetch", [
            "server_time" => 1.0,
            "wall_time" => 1.1,
            "status" => "continue",
            // No bytes_processed key
        ]);

        $this->assertSame("no_work", $result["decision"]);
    }

    public function testZeroWorkReportsNoWork(): void
    {
        $tuner = $this->makeTuner();

        $result = $tuner->record_result("file_fetch", [
            "server_time" => 1.0,
            "wall_time" => 1.1,
            "status" => "continue",
            "bytes_processed" => 0,
        ]);

        $this->assertSame("no_work", $result["decision"]);
    }

    // ---------------------------------------------------------------
    // Server time fallback
    // ---------------------------------------------------------------

    public function testNoServerTimeSkipsTuning(): void
    {
        $tuner = $this->makeTuner(["use_server_time" => true]);

        $result = $tuner->record_result("file_fetch", [
            "wall_time" => 1.0,
            "server_time" => 0,
            "status" => "continue",
            "bytes_processed" => 1000,
        ]);

        $this->assertSame("no_server_time", $result["decision"]);
    }

    public function testWallTimeFallback(): void
    {
        $tuner = $this->makeTuner(["use_server_time" => false]);

        $result = $tuner->record_result("file_fetch", [
            "wall_time" => 2.0,
            "server_time" => 0,
            "status" => "continue",
            "bytes_processed" => 1000,
        ]);

        // Should proceed with wall_time, not bail out.
        $this->assertContains($result["decision"], ["warmup", "increase", "decrease"]);
        $this->assertEqualsWithDelta(2.0, $result["elapsed"], 0.01);
    }
}
