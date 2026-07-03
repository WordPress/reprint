<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;
use UploadChunkSizer;

require_once __DIR__ . '/../../importer/import.php';

class UploadChunkSizerTest extends TestCase
{
    private const MIB = 1024 * 1024;

    // ---------------------------------------------------------------
    // Bootstrap and reported limits
    // ---------------------------------------------------------------

    public function testStartsAtConservativeBootstrapSizeWithoutLimits(): void
    {
        $sizer = new UploadChunkSizer();

        $this->assertSame(32 * self::MIB, $sizer->chunk_bytes());
    }

    public function testReportedLimitAboveChunkKeepsCurrentSize(): void
    {
        $sizer = new UploadChunkSizer();

        $decision = $sizer->apply_reported_limits([256 * self::MIB, null, 128 * self::MIB]);

        $this->assertSame('steady', $decision['action']);
        $this->assertSame(32 * self::MIB, $sizer->chunk_bytes());
    }

    public function testSmallestReportedLimitClampsChunkWithSafetyMargin(): void
    {
        $sizer = new UploadChunkSizer();

        $decision = $sizer->apply_reported_limits([64 * self::MIB, 8 * self::MIB]);

        $this->assertSame('shrink', $decision['action']);
        $this->assertSame((int) (8 * self::MIB * 0.9), $sizer->chunk_bytes());
    }

    public function testUnknownLimitValuesAreIgnored(): void
    {
        $sizer = new UploadChunkSizer();

        $decision = $sizer->apply_reported_limits([null, 0, -1]);

        $this->assertSame('steady', $decision['action']);
        $this->assertSame(32 * self::MIB, $sizer->chunk_bytes());
    }

    public function testLaterHigherReportedLimitDoesNotRaiseTheCeiling(): void
    {
        $sizer = new UploadChunkSizer();
        $sizer->apply_reported_limits([8 * self::MIB]);

        // A re-preflight reporting a raised limit must not undo what the
        // session already learned about this host.
        $sizer->apply_reported_limits([64 * self::MIB]);
        for ($i = 0; $i < 8; $i++) {
            $sizer->record_success();
        }

        $this->assertSame((int) (8 * self::MIB * 0.9), $sizer->chunk_bytes());
    }

    public function testReportedLimitBelowFloorGivesUp(): void
    {
        $sizer = new UploadChunkSizer();

        $decision = $sizer->apply_reported_limits([512 * 1024]);

        $this->assertSame('give_up', $decision['action']);
    }

    // ---------------------------------------------------------------
    // Growth
    // ---------------------------------------------------------------

    public function testSuccessDoublesTowardReportedLimit(): void
    {
        // A host reporting 256M must not stay capped at the 32 MiB bootstrap.
        $sizer = new UploadChunkSizer();
        $sizer->apply_reported_limits([256 * self::MIB]);

        $this->assertSame('grow', $sizer->record_success()['action']);
        $this->assertSame(64 * self::MIB, $sizer->chunk_bytes());

        $sizer->record_success();
        $sizer->record_success();

        $ceiling = (int) (256 * self::MIB * 0.9);
        $this->assertSame($ceiling, $sizer->chunk_bytes());
        $this->assertSame('steady', $sizer->record_success()['action']);
        $this->assertSame($ceiling, $sizer->chunk_bytes());
    }

    public function testGrowthWithoutLimitsStopsAtConfiguredMax(): void
    {
        $sizer = new UploadChunkSizer(["max_bytes" => 64 * self::MIB]);

        $sizer->record_success();
        $this->assertSame(64 * self::MIB, $sizer->chunk_bytes());
        $this->assertSame('steady', $sizer->record_success()['action']);
    }

    public function testHardCapAppliesEvenWhenHostReportsALargerLimit(): void
    {
        $sizer = new UploadChunkSizer();
        $sizer->apply_reported_limits([4 * 1024 * self::MIB]);

        for ($i = 0; $i < 10; $i++) {
            $sizer->record_success();
        }

        $this->assertSame(1024 * self::MIB, $sizer->chunk_bytes());
    }

    // ---------------------------------------------------------------
    // Size-related rejections
    // ---------------------------------------------------------------

    public function testTooLargeWithServerReportedLimitDropsBelowIt(): void
    {
        $sizer = new UploadChunkSizer();

        $decision = $sizer->record_too_large(16 * self::MIB);

        $this->assertSame('shrink', $decision['action']);
        $this->assertSame((int) (16 * self::MIB * 0.9), $sizer->chunk_bytes());
    }

    public function testTooLargeWithReportedLimitAboveCurrentChunkStillShrinks(): void
    {
        // A proxy can reject at a lower bound than the limit PHP reports, so
        // a rejected size must never be retried unchanged.
        $sizer = new UploadChunkSizer();

        $decision = $sizer->record_too_large(512 * self::MIB);

        $this->assertSame('shrink', $decision['action']);
        $this->assertSame(16 * self::MIB, $sizer->chunk_bytes());
    }

    public function testTooLargeWithoutReportedLimitHalves(): void
    {
        $sizer = new UploadChunkSizer();

        $decision = $sizer->record_too_large();

        $this->assertSame('shrink', $decision['action']);
        $this->assertSame(16 * self::MIB, $sizer->chunk_bytes());
    }

    public function testGrowthNeverRetriesARefusedSize(): void
    {
        $sizer = new UploadChunkSizer(["growth_holdoff_successes" => 1]);
        $sizer->record_too_large(); // 32 MiB refused, ceiling capped at 16 MiB

        $sizer->record_success(); // absorbs the holdoff
        for ($i = 0; $i < 5; $i++) {
            $sizer->record_success();
        }

        $this->assertSame(16 * self::MIB, $sizer->chunk_bytes());
    }

    public function testTooLargeBetweenFloorAndTwiceFloorStillTriesTheFloor(): void
    {
        $sizer = new UploadChunkSizer(["start_bytes" => 3 * 512 * 1024]); // 1.5 MiB

        $decision = $sizer->record_too_large();

        $this->assertSame('shrink', $decision['action']);
        $this->assertSame(self::MIB, $sizer->chunk_bytes());
    }

    public function testTooLargeAtFloorGivesUp(): void
    {
        $sizer = new UploadChunkSizer(["start_bytes" => self::MIB]);

        $decision = $sizer->record_too_large();

        $this->assertSame('give_up', $decision['action']);
        $this->assertSame(self::MIB, $sizer->chunk_bytes());
    }

    // ---------------------------------------------------------------
    // Transport failures
    // ---------------------------------------------------------------

    public function testTransportFailureHalvesWithoutCappingTheCeiling(): void
    {
        $sizer = new UploadChunkSizer(["growth_holdoff_successes" => 2]);

        $decision = $sizer->record_transport_failure();
        $this->assertSame('shrink', $decision['action']);
        $this->assertSame(16 * self::MIB, $sizer->chunk_bytes());

        // Two successes absorb the holdoff, then growth resumes past the
        // failed size — a transient timeout must not clamp the session.
        $this->assertSame('steady', $sizer->record_success()['action']);
        $this->assertSame('steady', $sizer->record_success()['action']);
        $this->assertSame('grow', $sizer->record_success()['action']);
        $sizer->record_success();
        $this->assertSame(64 * self::MIB, $sizer->chunk_bytes());
    }

    public function testTransportFailureAtFloorGivesUp(): void
    {
        $sizer = new UploadChunkSizer(["start_bytes" => self::MIB]);

        $this->assertSame('give_up', $sizer->record_transport_failure()['action']);
    }

    // ---------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------

    public function testStateSurvivesRoundTrip(): void
    {
        $sizer = new UploadChunkSizer();
        $sizer->apply_reported_limits([64 * self::MIB]);
        $sizer->record_too_large();

        $resumed = new UploadChunkSizer([], $sizer->get_state());

        $this->assertSame($sizer->chunk_bytes(), $resumed->chunk_bytes());
        $this->assertSame($sizer->get_state(), $resumed->get_state());

        // The learned ceiling still constrains growth after the resume.
        for ($i = 0; $i < 8; $i++) {
            $resumed->record_success();
        }
        $this->assertSame(16 * self::MIB, $resumed->chunk_bytes());
    }

    public function testRestoredStateIsClampedToConfiguredBounds(): void
    {
        $sizer = new UploadChunkSizer(
            ["max_bytes" => 64 * self::MIB],
            ["chunk_bytes" => PHP_INT_MAX, "ceiling_bytes" => -5, "growth_holdoff_remaining" => -2],
        );

        $this->assertSame(64 * self::MIB, $sizer->chunk_bytes());
        $state = $sizer->get_state();
        $this->assertNull($state['ceiling_bytes']);
        $this->assertSame(0, $state['growth_holdoff_remaining']);
    }
}
