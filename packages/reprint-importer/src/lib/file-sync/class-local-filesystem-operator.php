<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;
use function WordPress\Reprint\Exporter\path_is_within_root;

final class LocalFilesystemOperator
{
    private string $filesystemRoot;
    private string $fsRootNonemptyBehavior;
    private LocalFilesystemAuditLogger $audit;

    public function __construct(
        string $filesystemRoot,
        string $fsRootNonemptyBehavior,
        LocalFilesystemAuditLogger $audit
    ) {
        $this->filesystemRoot = $filesystemRoot;
        $this->fsRootNonemptyBehavior = $fsRootNonemptyBehavior;
        $this->audit = $audit;
    }

    public function removePathWithoutFollowingSymlinks(string $localPath): bool
    {
        if (!file_exists($localPath) && !is_link($localPath)) {
            return true;
        }

        if (is_link($localPath) || is_file($localPath)) {
            return true === @unlink($localPath);
        }

        if (is_dir($localPath)) {
            $entries = @scandir($localPath);
            if ($entries === false) {
                return false;
            }
            foreach ($entries as $entry) {
                if ($entry === "." || $entry === "..") {
                    continue;
                }
                if (
                    !$this->removePathWithoutFollowingSymlinks(
                        $localPath . "/" . $entry
                    )
                ) {
                    return false;
                }
            }
            return true === @rmdir($localPath);
        }

        return true === @unlink($localPath);
    }

    public function pathTraversesSymlink(string $path): bool
    {
        $root = $this->filesystemRoot;
        $relative = ltrim(substr($path, strlen($root)), "/");
        if ($relative === "") {
            return false;
        }

        $current = $root;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;
            if (is_link($current)) {
                return true;
            }
            if (!file_exists($current)) {
                break;
            }
        }

        return false;
    }

    public function ensureDirectoryPath(string $dir): void
    {
        $realFilesystemRoot = $this->filesystemRoot;
        $checkPath = $dir;
        while (
            !file_exists($checkPath) &&
            $checkPath !== dirname($checkPath)
        ) {
            $checkPath = dirname($checkPath);
        }

        if (file_exists($checkPath)) {
            $realCheck = realpath($checkPath);
            if (
                $realCheck === false ||
                !path_is_within_root($realCheck, $realFilesystemRoot)
            ) {
                if ($this->preserveLocal()) {
                    throw new \PreserveLocalSkipException(
                        "PRESERVE-LOCAL: path resolves outside fs root via symlink: {$dir}",
                    );
                }
                throw new RuntimeException(
                    "Security: Refusing to create directory outside fs root: {$dir}",
                );
            }
        }

        if (is_dir($dir) && !is_link($dir)) {
            if ($this->preserveLocal() && !is_writable($dir)) {
                throw new \PreserveLocalSkipException(
                    "PRESERVE-LOCAL: directory not writable: {$dir}",
                );
            }
            return;
        }

        if (
            $dir !== $realFilesystemRoot &&
            !str_starts_with($dir, $realFilesystemRoot . "/")
        ) {
            throw new RuntimeException(
                "Security: Refusing to create directory outside fs root: {$dir}",
            );
        }

        $relative = ltrim(substr($dir, strlen($realFilesystemRoot)), "/");
        if ($relative === "") {
            return;
        }

        $current = $realFilesystemRoot;
        foreach (explode("/", $relative) as $part) {
            if ($part === "") {
                continue;
            }
            $current .= "/" . $part;
            $this->replaceBlockingPathWithDirectory($current);
            $this->assertDirectoryWithinRoot($current, $realFilesystemRoot);
        }
    }

    private function replaceBlockingPathWithDirectory(string $current): void
    {
        if (is_link($current)) {
            if ($this->preserveLocal()) {
                throw new \PreserveLocalSkipException(
                    "PRESERVE-LOCAL: symlink in directory path: {$current}",
                );
            }
            $this->audit->logLocalFilesystemEvent(
                "Removing symlink blocking directory: {$current}",
                true,
            );
            if (!unlink($current)) {
                throw new RuntimeException(
                    "Failed to remove symlink blocking directory: {$current}",
                );
            }
            clearstatcache(true, $current);
        }

        if (is_file($current)) {
            if ($this->preserveLocal()) {
                throw new \PreserveLocalSkipException(
                    "PRESERVE-LOCAL: file blocks directory creation: {$current}",
                );
            }
            $this->audit->logLocalFilesystemEvent(
                "Removing file blocking directory: {$current}",
                true,
            );
            if (!unlink($current)) {
                throw new RuntimeException(
                    "Failed to remove file blocking directory: {$current}",
                );
            }
        }

        if (is_dir($current)) {
            if ($this->preserveLocal() && !is_writable($current)) {
                throw new \PreserveLocalSkipException(
                    "PRESERVE-LOCAL: directory not writable: {$current}",
                );
            }
            return;
        }

        if (!mkdir($current, 0755) && !is_dir($current)) {
            throw new RuntimeException(
                "Failed to create directory: {$current}\n" .
                "Error: " .
                (error_get_last()["message"] ?? "unknown"),
            );
        }
    }

    private function assertDirectoryWithinRoot(string $current, string $root): void
    {
        $resolved = realpath($current);
        if ($resolved === false || !path_is_within_root($resolved, $root)) {
            throw new RuntimeException(
                "Security: Refusing to create directory outside fs root: {$current}",
            );
        }
    }

    private function preserveLocal(): bool
    {
        return $this->fsRootNonemptyBehavior === 'preserve-local';
    }
}
