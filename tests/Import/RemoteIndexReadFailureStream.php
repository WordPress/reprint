<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test classes.

namespace ImportTests;

/** Stream wrapper whose reads fail before EOF. */
final class RemoteIndexReadFailureStream
{
    /** @var resource|null Stream context supplied by PHP. */
    public $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$opened_path
    ): bool {
        unset($path, $mode, $options, $opened_path);
        return true;
    }

    public function stream_read(int $count): string
    {
        unset($count);
        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /** @return array{mode:int,size:int} */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => 1];
    }

    /** @return array{mode:int,size:int} */
    public function url_stat(string $path, int $flags): array
    {
        unset($path, $flags);
        return ['mode' => 0100644, 'size' => 1];
    }
}
