<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing importer test namespace.
namespace ImportTests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../packages/reprint-client/src/lib/terminal-progress/class-terminal-progress.php';

class TerminalProgressTest extends TestCase {
    /** @dataProvider precedingDraws */
    public function testThrottledUpdatesKeepLatestMessageAndFraction(bool $spinner_draw): void
    {
        $stream = fopen('php://memory', 'w+b');
        $progress = new class(true, $stream) extends \TerminalProgress {
            protected function get_terminal_width_override(): ?int
            {
                return 120;
            }
        };
        $progress->set_mode('pipeline');

        try {
            $progress->show_progress_line('Downloading — 15 / 64 files', 15 / 64);
            if ($spinner_draw) {
                usleep(100000);
                $progress->tick_spinner();
            }
            $previous_length = ftell($stream);

            $progress->show_progress_line('Downloading — 16 / 64 files', 16 / 64);
            $progress->show_progress_line('Downloading — 32 / 64 files', 32 / 64);
            $this->assertSame($previous_length, ftell($stream), 'Updates inside the redraw interval must not write to the terminal.');

            usleep(100000);
            $progress->tick_spinner();
            fseek($stream, $previous_length);
            $redraw = stream_get_contents($stream);

            $this->assertStringContainsString('50% Downloading — 32 / 64 files', $redraw);
            $this->assertStringNotContainsString('15 / 64', $redraw);
            $this->assertStringNotContainsString('16 / 64', $redraw);
        } finally {
            fclose($stream);
        }
    }

    public static function precedingDraws(): array
    {
        return [
            'progress line' => [false],
            'spinner redraw' => [true],
        ];
    }
}
