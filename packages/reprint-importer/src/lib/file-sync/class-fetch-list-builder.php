<?php

namespace Reprint\Importer\FileSync;

use Reprint\Importer\Index\IndexLineParser;
use RuntimeException;

final class FetchListBuilder
{
    private const SAVE_PROGRESS_EVERY_N_ITEMS = 200;

    private FetchListBuildContext $context;

    public function __construct(FetchListBuildContext $context)
    {
        $this->context = $context;
    }

    public function build(
        string $remoteIndexFile,
        string $localIndexFile,
        string $downloadListFile,
        string $skippedDownloadListFile,
        string $filter,
        ?string $uploadsBasedir,
        int $remoteOffset,
        ?string $localAfter
    ): bool {
        if (!file_exists($remoteIndexFile)) {
            throw new RuntimeException("Remote index file not found");
        }

        $downloadMode = $remoteOffset > 0 ? "a" : "w";
        $this->auditDownloadListOpen($downloadListFile, $downloadMode);
        $downloadHandle = fopen($downloadListFile, $downloadMode);
        if (!$downloadHandle) {
            throw new RuntimeException("Failed to open download list file");
        }

        $skippedHandle = null;
        if ($filter === "essential-files") {
            $this->auditSkippedListOpen($skippedDownloadListFile, $downloadMode);
            $skippedHandle = fopen($skippedDownloadListFile, $downloadMode);
            if (!$skippedHandle) {
                fclose($downloadHandle);
                throw new RuntimeException("Failed to open skipped download list file");
            }
            $this->context->recordFetchListAudit(
                "FILTER | essential-files | uploads_basedir=" . ($uploadsBasedir ?? "(fallback: wp-content/uploads/)"),
            );
        }

        $remoteHandle = fopen($remoteIndexFile, "r");
        if (!$remoteHandle) {
            fclose($downloadHandle);
            if ($skippedHandle !== null) {
                fclose($skippedHandle);
            }
            throw new RuntimeException("Failed to open remote index file");
        }
        if ($remoteOffset > 0) {
            fseek($remoteHandle, $remoteOffset);
        }

        $localHandle = file_exists($localIndexFile)
            ? fopen($localIndexFile, "r")
            : null;
        $local = $this->context->readFetchListLocalIndexLine($localHandle);
        if ($localAfter) {
            while (
                $local !== null &&
                strcmp($local["path"], $localAfter) <= 0
            ) {
                $local = $this->context->readFetchListLocalIndexLine($localHandle);
            }
        }

        $this->context->beginFetchListIndexUpdates();
        $processed = 0;

        while (($line = fgets($remoteHandle)) !== false) {
            if ($this->context->shouldStopFetchListBuild()) {
                break;
            }

            if (function_exists("pcntl_signal_dispatch")) {
                pcntl_signal_dispatch();
            }

            $position = ftell($remoteHandle);
            if ($position !== false) {
                $remoteOffset = (int) $position;
            }

            $remote = IndexLineParser::parse($line);
            if (!$remote) {
                continue;
            }

            while (
                $local !== null &&
                strcmp($local["path"], $remote["path"]) < 0
            ) {
                $this->deleteLocalIfSelected($local["path"]);
                $localAfter = $local["path"];
                $local = $this->context->readFetchListLocalIndexLine($localHandle);
            }

            if ($local !== null && $local["path"] === $remote["path"]) {
                if ($this->remoteChanged($local, $remote)) {
                    $this->appendDownloadPath(
                        $remote["path"],
                        $this->targetDownloadHandle(
                            $downloadHandle,
                            $skippedHandle,
                            $remote["path"],
                            $uploadsBasedir,
                        ),
                    );
                }
                $localAfter = $local["path"];
                $local = $this->context->readFetchListLocalIndexLine($localHandle);
            } elseif (
                $local === null ||
                strcmp($local["path"], $remote["path"]) > 0
            ) {
                $this->appendNewRemotePath(
                    $remote["path"],
                    $this->targetDownloadHandle(
                        $downloadHandle,
                        $skippedHandle,
                        $remote["path"],
                        $uploadsBasedir,
                    ),
                );
            }

            $processed++;
            if ($processed % self::SAVE_PROGRESS_EVERY_N_ITEMS === 0) {
                $this->context->saveFetchListProgress($remoteOffset, $localAfter);
                $this->context->tickFetchListProgress();
            }
        }

        while ($local !== null) {
            $this->deleteLocalIfSelected($local["path"]);
            $localAfter = $local["path"];
            $local = $this->context->readFetchListLocalIndexLine($localHandle);
        }

        if ($localHandle) {
            fclose($localHandle);
        }
        fclose($remoteHandle);
        fclose($downloadHandle);
        if ($skippedHandle !== null) {
            fclose($skippedHandle);
        }

        $this->context->saveFetchListProgress($remoteOffset, $localAfter);
        $this->context->finalizeFetchListIndexUpdates();

        return !$this->context->shouldStopFetchListBuild();
    }

    private function remoteChanged(array $local, array $remote): bool
    {
        return
            $local["ctime"] !== $remote["ctime"] ||
            $local["size"] !== $remote["size"] ||
            $local["type"] !== $remote["type"];
    }

    private function appendNewRemotePath(string $path, $targetHandle): void
    {
        $skipReason = $this->context->shouldSkipFetchListRemotePath($path);
        if ($skipReason) {
            $this->context->recordFetchListAudit($skipReason, true);
            $this->context->emitFetchListSkip($path);
            return;
        }

        $this->appendDownloadPath($path, $targetHandle);
    }

    private function deleteLocalIfSelected(string $path): void
    {
        if (!$this->context->shouldDeleteFetchListLocalPath($path)) {
            return;
        }

        $this->context->deleteFetchListLocalPath($path);
        $this->context->deleteFetchListIndexEntry($path);
    }

    /**
     * @param resource $downloadHandle
     * @param resource|null $skippedHandle
     * @return resource
     */
    private function targetDownloadHandle(
        $downloadHandle,
        $skippedHandle,
        string $path,
        ?string $uploadsBasedir
    ) {
        if (
            $skippedHandle !== null &&
            $this->isUploadsPath($path, $uploadsBasedir)
        ) {
            return $skippedHandle;
        }

        return $downloadHandle;
    }

    private function isUploadsPath(string $path, ?string $uploadsBasedir): bool
    {
        if ($uploadsBasedir !== null) {
            return strpos($path, $uploadsBasedir) !== false;
        }

        return strpos($path, "wp-content/uploads/") !== false;
    }

    /**
     * @param resource $handle
     */
    private function appendDownloadPath(string $path, $handle): void
    {
        DownloadList::appendPath($path, $handle);
        $this->context->recordFetchListAudit(
            "Added to the download list: {$path}",
            false,
        );
    }

    private function auditDownloadListOpen(string $path, string $mode): void
    {
        if ($mode === "w") {
            $this->context->recordFetchListAudit(
                "FILE CREATE | {$path} | building download list",
            );
            return;
        }

        $this->context->recordFetchListAudit(
            "FILE APPEND | {$path} | resuming download list build",
        );
    }

    private function auditSkippedListOpen(string $path, string $mode): void
    {
        if ($mode === "w") {
            $this->context->recordFetchListAudit(
                "FILE CREATE | {$path} | building skipped download list (uploads)",
            );
            return;
        }

        $this->context->recordFetchListAudit(
            "FILE APPEND | {$path} | resuming skipped download list build",
        );
    }
}
