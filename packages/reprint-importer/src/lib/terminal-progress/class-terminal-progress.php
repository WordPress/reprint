<?php
/**
 * Renders progress and lifecycle output to the terminal.
 *
 * Two categories of output:
 *
 * - Progress line: a single line that overwrites in place. In default
 *   mode it just shows the message; in "pipeline progress" mode it also
 *   prepends an animated Braille spinner and (when a fraction is given)
 *   renders a Unicode progress bar. Use show_progress_line().
 *
 * - Lifecycle line: a regular line that announces a phase transition,
 *   e.g. "Starting db-pull". Use show_lifecycle_line(). When
 *   pipeline progress is enabled (typically by `pull`), these are
 *   suppressed so the parent command can provide its own framing
 *   without sub-command noise.
 *
 * Both methods are no-ops when stdout is not a TTY (so machine
 * consumers reading JSONL don't get progress noise interleaved) or
 * when verbose_mode is on (so debug output isn't visually disturbed).
 */
class TerminalProgress
{
    /** @var bool Whether the progress stream is a TTY. */
    private bool $is_tty;

    /** @var resource Stream to write progress to (STDOUT or STDERR). */
    private $progress_fd;

    /** @var bool When true, all progress output is suppressed. */
    private bool $verbose_mode;

    /** @var int|null Cached terminal column count (lazy-loaded via tput). */
    private ?int $terminal_width_cache = null;

    /**
     * @var string Output mode. Pipeline mode suppresses lifecycle messages
     * and decorates the progress line with a spinner / progress bar. Used by
     * parent commands (e.g. pull) that want to provide their own framing.
     */
    private string $mode = 'default';

    /** @var int Spinner frame counter. */
    private int $spinner_tick = 0;

    /** @var float Last spinner draw timestamp (microtime), for rate-limiting. */
    private float $spinner_last_draw = 0.0;

    /** @var string|null Active stage label, shown when no progress message has arrived yet. */
    private ?string $active_label = null;

    /** @var string|null Last progress message rendered (without spinner prefix), for spinner replays. */
    private ?string $last_progress_message = null;

    /** @var float|null Fraction from the last progress call, or null for no bar. */
    private ?float $last_progress_fraction = null;

    public function __construct(bool $is_tty, $progress_fd, bool $verbose_mode = false)
    {
        $this->is_tty = $is_tty;
        $this->progress_fd = $progress_fd;
        $this->verbose_mode = $verbose_mode;
    }

    public function set_is_tty(bool $is_tty): void
    {
        $this->is_tty = $is_tty;
    }

    public function set_progress_fd($progress_fd): void
    {
        $this->progress_fd = $progress_fd;
    }

    public function set_verbose_mode(bool $verbose_mode): void
    {
        $this->verbose_mode = $verbose_mode;
    }

    /**
     * Set the output mode. Pipeline mode suppresses lifecycle messages,
     * adds a spinner/bar prefix to progress lines, and lets tick_spinner()
     * animate the active line.
     */
    public function set_mode(string $mode): void
    {
        if (!in_array($mode, ['default', 'pipeline'], true)) {
            throw new InvalidArgumentException("Unknown progress mode: {$mode}");
        }
        $this->mode = $mode;
    }

    public function is_mode(string $mode): bool
    {
        return $this->mode === $mode;
    }

    /**
     * Set the label shown alongside the spinner when no detailed
     * progress message is available yet (called when a new stage starts).
     */
    public function set_active_label(?string $label): void
    {
        $this->active_label = $label;
        $this->last_progress_message = null;
        $this->last_progress_fraction = null;
    }

    /**
     * Show progress in a single refreshing line.
     *
     * In pipeline progress mode the message is decorated with either a
     * progress bar (when $fraction is provided) or a Braille spinner.
     * Rate-limited to ~20fps so the terminal can keep up.
     */
    public function show_progress_line(string $message, ?float $fraction = null): void
    {
        if (!$this->is_tty || $this->verbose_mode) {
            return;
        }
        if ($this->is_mode('pipeline')) {
            // Rate-limit in pull mode to avoid flooding the terminal.
            // Hundreds of updates per second cause visual artifacts
            // because the terminal can't redraw fast enough — \r writes
            // pile up and appear as scrolling lines.
            $now = microtime(true);
            if ($now - $this->spinner_last_draw < 0.05) {
                return;
            }
            $this->spinner_tick++;
            $this->spinner_last_draw = $now;
            // Remember raw content so tick_spinner can redraw with an
            // updated frame without flickering back to the bare label.
            $this->last_progress_message = $message;
            $this->last_progress_fraction = $fraction;
            $message = $this->decorate($message, $fraction);
        }

        $message = $this->truncate_for_terminal($message);
        fwrite($this->progress_fd, "\r\033[K" . $message);
    }

    /**
     * Print a lifecycle message announcing a phase transition.
     *
     * Suppressed when pipeline progress is enabled, so the parent command
     * can render its own framing without sub-command noise.
     */
    public function show_lifecycle_line(string $message): void
    {
        if (!$this->is_tty || $this->verbose_mode || $this->is_mode('pipeline')) {
            return;
        }
        fwrite($this->progress_fd, $message);
    }

    /**
     * Always print a line (no pipeline progress suppression). Use for
     * parent-command output like stage headers / checkmarks.
     */
    public function print_line(string $message): void
    {
        if (!$this->is_tty || $this->verbose_mode) {
            return;
        }
        fwrite($this->progress_fd, $message);
    }

    /**
     * Clear the current progress line (TTY mode only).
     */
    public function clear_progress_line(): void
    {
        if (!$this->is_tty || $this->verbose_mode) {
            return;
        }
        fwrite($this->progress_fd, "\r\033[K");
    }

    /**
     * Advance the spinner without updating the message. Called from
     * curl progress callbacks and other tight loops to keep the
     * spinner alive when no new data has arrived. Rate-limited to
     * ~12fps to avoid CPU waste on terminal writes.
     */
    public function tick_spinner(): void
    {
        if (!$this->is_mode('pipeline') || !$this->is_tty || $this->verbose_mode) {
            return;
        }
        if ($this->active_label === null && $this->last_progress_message === null) {
            return;
        }
        $now = microtime(true);
        if ($now - $this->spinner_last_draw < 0.08) {
            return;
        }
        $this->spinner_last_draw = $now;
        $this->spinner_tick++;

        if ($this->last_progress_message !== null) {
            $line = $this->decorate($this->last_progress_message, $this->last_progress_fraction);
        } else {
            $line = $this->decorate($this->active_label, null);
        }
        $line = $this->truncate_for_terminal($line);
        fwrite($this->progress_fd, "\r\033[K{$line}");
    }

    /**
     * Decorate a message with the current spinner frame (or progress bar
     * when a fraction is provided). The spinner_tick advances inside
     * show_progress_line / tick_spinner; this method just reads it.
     */
    private function decorate(string $message, ?float $fraction): string
    {
        if ($fraction !== null && $fraction >= 0) {
            return $this->render_progress_bar($message, $fraction);
        }
        $frames = "⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏";
        $idx = ($this->spinner_tick % 10) * 3;
        $char = substr($frames, $idx, 3);
        return "  \033[36m{$char}\033[0m {$message}";
    }

    /**
     * Render a progress bar line:
     *   ━━━━━━━━━━━━░░░░░░  Downloading files — 1,234 / 5,091 24%
     */
    public function render_progress_bar(string $label, float $fraction): string
    {
        $fraction = max(0.0, min(1.0, $fraction));
        $bar_width = 20;
        $filled = (int) round($fraction * $bar_width);
        $empty = $bar_width - $filled;
        $bar = str_repeat("━", $filled) . str_repeat("░", $empty);
        $pct = (int) round($fraction * 100);
        return "  \033[36m{$bar}\033[0m  {$label} \033[2m{$pct}%\033[0m";
    }

    /**
     * Return the terminal width in columns. Tries `tput cols` once
     * and caches the result; falls back to 80.
     */
    public function get_terminal_width(): int
    {
        $override = $this->get_terminal_width_override();
        if ($override !== null) {
            return $override;
        }
        if ($this->terminal_width_cache !== null) {
            return $this->terminal_width_cache;
        }
        $width = 80;
        if (function_exists("exec")) {
            $tput_cols = @exec("tput cols 2>/dev/null");
            if ($tput_cols && is_numeric($tput_cols)) {
                $width = (int) $tput_cols;
            }
        }
        $this->terminal_width_cache = $width;
        return $width;
    }

    /** @internal Override point for tests — return non-null to bypass tput. */
    protected function get_terminal_width_override(): ?int
    {
        return null;
    }

    /**
     * Measure the display width of a string, ignoring ANSI escape codes.
     * Uses mb_strwidth when available for correct multi-byte widths.
     */
    public function display_width(string $message): int
    {
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $message);
        return function_exists('mb_strwidth')
            ? mb_strwidth($stripped, 'UTF-8')
            : strlen($stripped);
    }

    /**
     * Truncate a message to fit the terminal width.
     *
     * Preserves ANSI escape codes (zero display width) while truncating
     * the visible text. Walks character by character so multi-byte
     * UTF-8 sequences and Unicode bar characters are handled correctly.
     */
    public function truncate_for_terminal(string $message): string
    {
        $width = $this->get_terminal_width();
        if ($this->display_width($message) <= $width) {
            return $message;
        }
        $limit = $width - 3; // room for "..."
        $result = '';
        $cols = 0;
        $len = strlen($message);
        for ($i = 0; $i < $len; ) {
            if ($message[$i] === "\033" && $i + 1 < $len && $message[$i + 1] === '[') {
                $end = $i + 2;
                while ($end < $len && !ctype_alpha($message[$end])) {
                    $end++;
                }
                if ($end < $len) $end++;
                $result .= substr($message, $i, $end - $i);
                $i = $end;
                continue;
            }
            $byte = ord($message[$i]);
            if ($byte < 0x80) { $char_len = 1; }
            elseif ($byte < 0xE0) { $char_len = 2; }
            elseif ($byte < 0xF0) { $char_len = 3; }
            else { $char_len = 4; }
            $char = substr($message, $i, $char_len);
            $char_width = function_exists('mb_strwidth')
                ? mb_strwidth($char, 'UTF-8')
                : 1;
            if ($cols + $char_width > $limit) {
                break;
            }
            $result .= $char;
            $cols += $char_width;
            $i += $char_len;
        }
        return $result . "\033[0m...";
    }
}
