<?php

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions contain local filesystem paths, never HTML output.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Importer classes use unprefixed domain names.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Importer classes place braces on the following line.

/**
 * Prevents concurrent Reprint processes from using one state directory.
 */
final class ReprintProcessLock
{
    /** @var resource|null */
    private $handle;

    public function __construct(string $state_directory)
    {
        if (
            !is_dir($state_directory)
            && !mkdir($state_directory, 0755, true)
            && !is_dir($state_directory)
        ) {
            throw new RuntimeException(
                'Failed to create the Reprint state directory: '
                . $state_directory . '.'
            );
        }
        $lock_path = rtrim($state_directory, '/') . '/.reprint.lock';
        $this->handle = fopen($lock_path, 'c+b');
        if (!is_resource($this->handle)) {
            throw new RuntimeException('Failed to open the Reprint process lock: ' . $lock_path . '.');
        }
        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            throw new RuntimeException(
                'Another Reprint process is using the state directory: '
                . rtrim($state_directory, '/') . '.'
            );
        }
    }

    public function is_held(): bool
    {
        return is_resource($this->handle);
    }

    public function close(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
