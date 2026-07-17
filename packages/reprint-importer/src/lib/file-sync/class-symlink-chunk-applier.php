<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;

final class SymlinkChunkApplier
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
        $rawPath = $headers["x-symlink-path"] ?? "";
        $path = base64_decode($rawPath, true);
        $target = base64_decode($headers["x-symlink-target"] ?? "", true);
        $ctime = (int) ($headers["x-symlink-ctime"] ?? 0);

        if ($path === false || $path === "" || $target === false || $target === "") {
            if ($rawPath !== "" && ($path === false || $path === "")) {
                $this->local->recordFileSyncAudit(
                    "Warning: base64_decode failed for x-symlink-path header: " .
                    substr($rawPath, 0, 100),
                    true,
                );
            }
            return;
        }

        $localPath = $this->local->localPathForRemotePath($path);
        $targetForLocal = $this->local->mapSymlinkTargetForLocalMirror(
            $path,
            $localPath,
            $target,
        );

        if ($this->preserveLocal) {
            if (file_exists($localPath) || is_link($localPath)) {
                $this->local->recordFileSyncAudit(
                    "PRESERVE-LOCAL skip symlink (path exists): {$path} -> {$target}",
                    true,
                );
                $this->local->emitSkipProgress($path);
                return;
            }
            if ($this->local->pathTraversesSymlink(dirname($localPath))) {
                $this->local->recordFileSyncAudit(
                    "PRESERVE-LOCAL skip symlink (symlink in path): {$path} -> {$target}",
                    true,
                );
                $this->local->emitSkipProgress($path);
                return;
            }
        }

        try {
            FilePlacementRules::assertSymlinkTargetWithinRoot(
                dirname($localPath),
                $targetForLocal,
                $this->local->filesystemRootPath(),
            );
        } catch (RuntimeException $e) {
            $this->local->recordFileSyncAudit($e->getMessage(), true);
            $this->emitSymlinkError($path, $target, $targetForLocal, $e->getMessage());
            return;
        }

        if (file_exists($localPath) || is_link($localPath)) {
            if (!$this->local->removeLocalPathWithoutFollowingSymlinks($localPath)) {
                $this->local->recordFileSyncAudit(
                    "Failed to remove existing path for symlink: {$localPath}",
                    true,
                );
                $this->emitSymlinkError(
                    $path,
                    $target,
                    $targetForLocal,
                    "Failed to replace existing path",
                );
                return;
            }
        }

        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            try {
                $this->local->ensureDirectoryPath($dir);
            } catch (\PreserveLocalSkipException $e) {
                $this->local->recordFileSyncAudit($e->getMessage(), true);
                $this->local->emitSkipProgress($path);
                return;
            } catch (RuntimeException $e) {
                $this->local->recordFileSyncAudit(
                    "Failed to create directory for symlink: {$dir}",
                    true,
                );
                $this->emitSymlinkError(
                    $path,
                    $target,
                    $targetForLocal,
                    "Failed to create parent directory",
                );
                return;
            }
        }

        $symlinkResult = symlink($targetForLocal, $localPath);
        if (true !== $symlinkResult || !is_link($localPath)) {
            $this->local->recordFileSyncAudit(
                "Failed to create symlink: {$localPath} -> {$targetForLocal}",
                true,
            );
            $this->emitSymlinkError(
                $path,
                $target,
                $targetForLocal,
                "Failed to create symlink",
            );
            return;
        }

        if ($ctime > 0) {
            @touch($localPath, $ctime);
        }

        $this->local->recordFileSyncAudit(
            "Symlink: {$path} -> {$targetForLocal}",
            false,
        );

        if ($ctime > 0) {
            $this->local->upsertFileIndexEntry($path, $ctime, 0, "link");
        }

        $this->local->outputFileSyncProgress([
            "type" => "symlink",
            "path" => $path,
            "target" => $targetForLocal,
            "message" => "Symlink: {$path} -> {$target}",
        ]);
    }

    private function emitSymlinkError(
        string $path,
        string $target,
        string $targetForLocal,
        string $error
    ): void {
        $this->local->outputFileSyncProgress([
            "type" => "symlink_error",
            "path" => $path,
            "target" => $targetForLocal,
            "error" => $error,
            "message" => "Symlink error: {$path} -> {$target}",
        ]);
    }
}
