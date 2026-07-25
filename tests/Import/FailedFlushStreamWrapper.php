<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing importer test namespace.
// phpcs:disable Generic.Classes.OpeningBraceSameLine.BraceOnNewLine -- Match the existing importer test class style.

namespace ImportTests;

final class FailedFlushStreamWrapper
{
    /** @var resource|null */
    public $context;

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required stream wrapper signature.
    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath
    ): bool {
        return true;
    }

    public function stream_write(string $data): int
    {
        return strlen($data);
    }

    public function stream_flush(): bool
    {
        return false;
    }
}
