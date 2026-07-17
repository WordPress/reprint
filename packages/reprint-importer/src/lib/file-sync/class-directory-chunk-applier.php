<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;

final class DirectoryChunkApplier
{
    private bool $preserveLocal;
    private LocalFileApplyContext $local;

    public function __construct(
        bool $preserveLocal,
        LocalFileApplyContext $local
    ) {
        $this->preserveLocal = $preserveLocal;
        $this->local = $local;
    }

    public function handle(array $chunk): void
    {
        $headers = $chunk["headers"];
        $rawHeader = $headers["x-directory-path"] ?? "";
        $path = base64_decode($rawHeader, true);
        $ctime = (int) ($headers["x-directory-ctime"] ?? 0);

        if ($path === false || $path === "") {
            if ($rawHeader !== "") {
                $this->local->recordFileSyncAudit(
                    "Warning: base64_decode failed for x-directory-path header: " .
                    substr($rawHeader, 0, 100),
                    true,
                );
            }
            return;
        }

        $localPath = $this->local->localPathForRemotePath($path);

        if ($this->preserveLocal) {
            if (is_dir($localPath)) {
                $this->skipAndIndex(
                    $path,
                    $ctime,
                    "PRESERVE-LOCAL skip directory (exists): {$path}",
                );
                return;
            }
            if ($this->local->pathTraversesSymlink($localPath)) {
                $this->skipAndIndex(
                    $path,
                    $ctime,
                    "PRESERVE-LOCAL skip directory (symlink in path): {$path}",
                );
                return;
            }
        }

        if (
            (file_exists($localPath) || is_link($localPath)) &&
            (!is_dir($localPath) || is_link($localPath))
        ) {
            if (!$this->local->removeLocalPathWithoutFollowingSymlinks($localPath)) {
                throw new RuntimeException(
                    "Failed to replace path with directory: {$path}",
                );
            }
        }

        try {
            $this->local->ensureDirectoryPath($localPath);
        } catch (\PreserveLocalSkipException $e) {
            $this->local->recordFileSyncAudit($e->getMessage(), true);
            $this->local->emitSkipProgress($path);
            return;
        }

        $this->local->recordFileSyncAudit("Directory: {$path}", false);

        if ($ctime > 0) {
            $this->local->upsertFileIndexEntry($path, $ctime, 0, "dir");
        }
    }

    private function skipAndIndex(string $path, int $ctime, string $message): void
    {
        $this->local->recordFileSyncAudit($message, true);
        $this->local->emitSkipProgress($path);
        if ($ctime > 0) {
            $this->local->upsertFileIndexEntry($path, $ctime, 0, "dir");
        }
    }
}
