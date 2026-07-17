<?php

namespace Reprint\Importer\FileSync;

interface FetchListBuildContext
{
    /**
     * @param resource|null $handle
     * @return array{path: string, ctime: int, size: int, type: string}|null
     */
    public function readFetchListLocalIndexLine($handle): ?array;

    public function beginFetchListIndexUpdates(): void;

    public function finalizeFetchListIndexUpdates(): void;

    public function deleteFetchListIndexEntry(string $path): void;

    public function deleteFetchListLocalPath(string $path): void;

    public function shouldDeleteFetchListLocalPath(string $path): bool;

    public function shouldSkipFetchListRemotePath(string $path): ?string;

    public function emitFetchListSkip(string $path): void;

    public function saveFetchListProgress(int $remoteOffset, ?string $localAfter): void;

    public function shouldStopFetchListBuild(): bool;

    public function tickFetchListProgress(): void;

    public function recordFetchListAudit(string $message, bool $toConsole = true): void;
}
