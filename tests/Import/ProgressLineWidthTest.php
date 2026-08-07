<?php

namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/client.php';

/**
 * Verify that progress line output never exceeds terminal width.
 *
 * Line wrapping in a terminal causes \r\033[K to only clear the last
 * physical line, leaving ghost text above. This test ensures the
 * truncation logic correctly limits all output to the terminal width.
 */
class ProgressLineWidthTest extends TestCase
{
    /**
     * Create a TerminalProgress with a forced terminal width.
     */
    private function createProgress(int $terminal_width = 80): \TerminalProgress
    {
        // Anonymous subclass to override the width detection.
        return new class(true, STDOUT, false, $terminal_width) extends \TerminalProgress {
            private int $forced_width;

            public function __construct(bool $is_tty, $progress_fd, bool $verbose_mode, int $width)
            {
                parent::__construct($is_tty, $progress_fd, $verbose_mode);
                $this->forced_width = $width;
            }

            protected function get_terminal_width_override(): ?int
            {
                return $this->forced_width;
            }
        };
    }

    private function displayWidth(string $s): int
    {
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $s);
        return function_exists('mb_strwidth')
            ? mb_strwidth($stripped, 'UTF-8')
            : strlen($stripped);
    }

    public function testPlainTextTruncation(): void
    {
        $progress = $this->createProgress(40);
        $long = str_repeat('a', 60);
        $result = $progress->truncate_for_terminal($long);
        $this->assertLessThanOrEqual(40, $this->displayWidth($result));
        $this->assertStringEndsWith('...', $result);
    }

    public function testShortTextUnchanged(): void
    {
        $progress = $this->createProgress(80);
        $short = 'Hello world';
        $result = $progress->truncate_for_terminal($short);
        $this->assertEquals($short, $result);
    }

    public function testAnsiCodesPreservedInTruncation(): void
    {
        $progress = $this->createProgress(30);
        $msg = "  \033[36m⠋\033[0m Hello world more text here blah blah blah";
        $result = $progress->truncate_for_terminal($msg);
        $this->assertLessThanOrEqual(30, $this->displayWidth($result));
        $this->assertStringContainsString("\033[", $result);
    }

    public function testProgressBarTruncation(): void
    {
        $progress = $this->createProgress(60);
        $bar = str_repeat("━", 10) . str_repeat("░", 10);
        $msg = "  \033[36m{$bar}\033[0m  Downloading — 1,234 / 43,378 files \033[2m28%\033[0m";
        $result = $progress->truncate_for_terminal($msg);
        $this->assertLessThanOrEqual(60, $this->displayWidth($result),
            "Progress bar line exceeds terminal width. Display width: " .
            $this->displayWidth($result) . ", max: 60. Output: " . $result);
    }

    public function testProgressBarPercentageComesFirstAndUsesDefaultOutputColor(): void
    {
        $progress = $this->createProgress();
        $result = $progress->render_progress_bar('Pushing', 0.6);

        $this->assertStringContainsString('60% Pushing', $result);
        $this->assertStringNotContainsString("\033[2m", $result);
    }

    public function testUnicodeSpinnerWidth(): void
    {
        $progress = $this->createProgress(80);
        $this->assertEquals(1, $progress->display_width("⠋"));
        $this->assertEquals(1, $progress->display_width("━"));
        $this->assertEquals(1, $progress->display_width("░"));
    }

    public function testAnsiCodesHaveZeroWidth(): void
    {
        $progress = $this->createProgress(80);
        $this->assertEquals(0, $progress->display_width("\033[36m"));
        $this->assertEquals(0, $progress->display_width("\033[0m"));
        $this->assertEquals(5, $progress->display_width("\033[36mhello\033[0m"));
    }

    public function testNarrowTerminal(): void
    {
        $progress = $this->createProgress(20);
        $msg = "  \033[36m⠋\033[0m Scanning remote files — 376,000 scanned";
        $result = $progress->truncate_for_terminal($msg);
        $width = $this->displayWidth($result);
        $this->assertLessThanOrEqual(20, $width,
            "Output exceeds 20-col terminal. Width: {$width}. Output: {$result}");
    }

    public function testExactWidthNotTruncated(): void
    {
        $progress = $this->createProgress(40);
        $msg = str_repeat('x', 40);
        $result = $progress->truncate_for_terminal($msg);
        $this->assertEquals($msg, $result);
    }

    public function testUtf8NotSplitMidCharacter(): void
    {
        $progress = $this->createProgress(10);
        $msg = str_repeat("━", 15);
        $result = $progress->truncate_for_terminal($msg);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'),
            "Truncated string is not valid UTF-8");
        $this->assertLessThanOrEqual(10, $this->displayWidth($result));
    }
}
