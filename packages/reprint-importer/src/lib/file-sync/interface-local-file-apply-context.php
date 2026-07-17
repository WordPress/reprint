<?php

namespace Reprint\Importer\FileSync;

interface LocalFileApplyContext
{
    public function localPathForRemotePath(string $path): string;

    public function removeLocalPathWithoutFollowingSymlinks(string $localPath): bool;

    public function ensureDirectoryPath(string $dir): void;

    public function pathTraversesSymlink(string $path): bool;

    public function filesystemRootPath(): string;

    public function mapSymlinkTargetForLocalMirror(
        string $path,
        string $localPath,
        string $target
    ): string;

    public function recordFileSyncAudit(string $message, bool $toConsole = true): void;

    public function showFileFetchProgress(string $path, int $fileSize): void;

    public function emitSkipProgress(string $path): void;

    public function upsertFileIndexEntry(string $path, int $ctime, int $size, string $type): void;

    public function clearVolatileFile(string $path): void;

    public function setCurrentFileCheckpoint(?string $path, ?int $bytes): void;

    /**
     * @param array<string, mixed> $progress
     */
    public function outputFileSyncProgress(array $progress, bool $force = false): void;
}
