<?php

namespace Reprint\Importer\FileSync;

interface FileFetchResponseObserver
{
    public function shouldStopFileFetch(): bool;

    public function saveFileFetchCheckpoint(
        string $stateKey,
        ?string $cursor,
        \StreamingContext $context
    ): void;

    public function handleFileFetchMetadata(array $chunk, \StreamingContext $context): void;

    public function handleFileFetchFile(array $chunk, \StreamingContext $context): void;

    public function handleFileFetchDirectory(array $chunk): void;

    public function handleFileFetchSymlink(array $chunk): void;

    public function handleFileFetchMissingPath(string $path): void;

    public function handleFileFetchError(array $chunk, string $phase, \StreamingContext $context): void;

    public function handleFileFetchProgress(array $chunk, string $phase): void;

    /**
     * @param array<string, mixed> $progress
     */
    public function handleFileFetchCompletionProgress(array $progress): void;
}
