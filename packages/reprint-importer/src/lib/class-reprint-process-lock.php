<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions contain local filesystem paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Serializes local Reprint commands which share one state directory.
 *
 * Construction acquires the non-blocking exclusive lock at
 * `<state-dir>/process.lock`. The caller retains this object for the
 * complete command and calls close() when the command ends.
 */
final class ReprintProcessLock
{
    /** @var resource|null Open lock handle, or null after close(). */
    private $handle;

    /**
     * Acquires the state directory's Reprint process lock.
     *
     * The state directory is created when absent. Lock acquisition is
     * non-blocking, so construction fails while another process owns it.
     *
     * @param string $state_dir Caller-selected state directory.
     *
     * @throws RuntimeException When the directory or lock cannot be created,
     *                          opened, or acquired.
     */
    public function __construct(string $state_dir)
    {
        if (
            !is_dir($state_dir)
            && !mkdir($state_dir, 0755, true)
            && !is_dir($state_dir)
        ) {
            throw new RuntimeException(
                'Failed to create the Reprint state directory: '
                . $state_dir . '.'
            );
        }
        $process_lock_path = rtrim($state_dir, '/') . '/process.lock';
        $this->handle = fopen($process_lock_path, 'c+b');
        if (!is_resource($this->handle)) {
            throw new RuntimeException('Failed to open the Reprint process lock: ' . $process_lock_path . '.');
        }
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            throw new RuntimeException(
                'Another Reprint process is using the state directory: '
                . rtrim($state_dir, '/') . '.'
            );
        }
    }

    /**
     * Indicates whether this object still holds the process lock.
     *
     * @return bool True while the lock handle remains open.
     */
    public function is_held(): bool
    {
        return is_resource($this->handle);
    }

    /**
     * Releases the process lock and closes its handle.
     *
     * Repeated calls have no effect.
     */
    public function close(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Releases the process lock when the owner leaves scope.
     */
    public function __destruct()
    {
        $this->close();
    }
}
