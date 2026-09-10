<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Match the existing importer test namespace.
namespace ImportTests;

/** Makes the actual fwrite() call accept zero bytes, as a full device can do. */
final class FailingProgressLogStream {
    /** @var resource|null Context supplied by PHP's stream API. */
    public $context;

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- PHP defines the stream callback signature.
    public function stream_open($path, $mode, $options, &$opened_path): bool
    {
        return true;
    }

    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Reject every write regardless of its contents.
    public function stream_write($data): int
    {
        return 0;
    }
}
