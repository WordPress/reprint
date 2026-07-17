<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;

final class FileChunkApplier
{
    private int $filesImported;
    private LocalFileApplyContext $local;

    public function __construct(
        int $filesImported,
        LocalFileApplyContext $local
    ) {
        $this->filesImported = $filesImported;
        $this->local = $local;
    }

    public function filesImported(): int
    {
        return $this->filesImported;
    }

    public function handle(array $chunk, \StreamingContext $context): void
    {
        $headers = $chunk["headers"];
        $rawHeader = $headers["x-file-path"] ?? "";
        $path = base64_decode($rawHeader, true);
        $isFirst = ($headers["x-first-chunk"] ?? "0") === "1";
        $isLast = ($headers["x-last-chunk"] ?? "0") === "1";

        if ($path === false || $path === "") {
            if ($rawHeader !== "") {
                $this->local->recordFileSyncAudit(
                    "Warning: base64_decode failed for x-file-path header: " .
                    substr($rawHeader, 0, 100),
                    true,
                );
            }
            return;
        }

        $localPath = $this->local->localPathForRemotePath($path);

        if ($isFirst) {
            $this->startFile($path, $localPath, $headers, $context);
        }

        if ($context->skip_current_file) {
            return;
        }

        if ($isFirst) {
            $this->openFileForWriting($path, $localPath, $headers, $context);
        }

        $this->writeBody($chunk, $context);

        if ($isLast && $context->file_handle) {
            $this->finishFile($path, $headers, $context);
        }
    }

    private function startFile(
        string $path,
        string $localPath,
        array $headers,
        \StreamingContext $context
    ): void {
        $context->skip_current_file = false;

        if (
            (file_exists($localPath) || is_link($localPath)) &&
            (!is_file($localPath) || is_link($localPath))
        ) {
            if (!$this->local->removeLocalPathWithoutFollowingSymlinks($localPath)) {
                throw new RuntimeException(
                    "Failed to replace path with file: {$path}",
                );
            }
        }

        $existsLocally = file_exists($localPath);
        $localSize = $existsLocally ? filesize($localPath) : 0;
        $fileSize = (int) ($headers["x-file-size"] ?? 0);

        $this->local->recordFileSyncAudit(
            sprintf(
                "File: %s (remote_size=%d, ctime=%d, local_exists=%s, local_size=%d)",
                $path,
                $fileSize,
                (int) ($headers["x-file-ctime"] ?? 0),
                $existsLocally ? "yes" : "no",
                $localSize,
            ),
            false,
        );

        $this->local->showFileFetchProgress($path, $fileSize);
    }

    private function openFileForWriting(
        string $path,
        string $localPath,
        array $headers,
        \StreamingContext $context
    ): void {
        if ($context->file_handle) {
            fclose($context->file_handle);
            if ($context->file_ctime && $context->file_path) {
                touch($context->file_path, $context->file_ctime);
            }
        }

        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            try {
                $this->local->ensureDirectoryPath($dir);
            } catch (\PreserveLocalSkipException $e) {
                $context->skip_current_file = true;
                $this->local->recordFileSyncAudit($e->getMessage(), true);
                $this->local->emitSkipProgress($path);
                return;
            }
        }

        $context->file_handle = fopen($localPath, "wb");
        if (!$context->file_handle) {
            $error = error_get_last();
            throw new RuntimeException(
                "Failed to open file for writing: {$localPath}\n" .
                "Parent directory: {$dir}\n" .
                "Directory exists: " .
                (is_dir($dir) ? "yes" : "no") .
                "\n" .
                "Error: " .
                ($error["message"] ?? "unknown"),
            );
        }
        $context->file_path = $localPath;
        $context->file_ctime = (int) ($headers["x-file-ctime"] ?? 0);
        $context->file_bytes_written = 0;
    }

    private function writeBody(array $chunk, \StreamingContext $context): void
    {
        if (!isset($chunk["body"]) || $chunk["body"] === "") {
            return;
        }

        if (!$context->file_handle) {
            return;
        }

        $data = $chunk["body"];
        $bytes = fwrite($context->file_handle, $data);
        if ($bytes === false || $bytes !== strlen($data)) {
            throw new RuntimeException(
                "Write failed for {$context->file_path}: wrote " .
                ($bytes === false ? "0" : $bytes) . "/" . strlen($data) .
                " bytes (disk full?)"
            );
        }
        $context->file_bytes_written += $bytes;
    }

    private function finishFile(
        string $path,
        array $headers,
        \StreamingContext $context
    ): void {
        fclose($context->file_handle);

        if ($context->file_ctime && $context->file_path) {
            touch($context->file_path, $context->file_ctime);
        }

        $fileSize = (int) ($headers["x-file-size"] ?? 0);
        $finalSize = file_exists($context->file_path)
            ? filesize($context->file_path)
            : 0;
        $fileChanged = ($headers["x-file-changed"] ?? "0") === "1";

        if ($context->file_ctime && !$fileChanged) {
            $this->local->upsertFileIndexEntry(
                $path,
                $context->file_ctime,
                $fileSize,
                "file",
            );
            $this->filesImported++;
            $this->local->clearVolatileFile($path);
            $this->local->recordFileSyncAudit(
                sprintf("  Indexed (wrote %d bytes)", $finalSize),
                false,
            );
        } elseif ($fileChanged) {
            $this->local->recordFileSyncAudit(
                "  File changed during stream; index not updated",
                true,
            );
        }

        $context->file_handle = null;
        $context->file_path = null;
        $context->file_ctime = null;
        $context->file_bytes_written = 0;
        $this->local->setCurrentFileCheckpoint(null, null);
    }
}
