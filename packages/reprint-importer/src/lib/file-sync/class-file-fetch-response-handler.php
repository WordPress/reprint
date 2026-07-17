<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;

final class FileFetchResponseHandler
{
    private ?string $cursor;
    private string $stateKey;
    private \StreamingContext $context;
    private int $saveEvery;
    private int $chunksSinceSave = 0;
    private bool $complete = false;
    private FileFetchResponseObserver $observer;

    public function __construct(
        ?string $cursor,
        string $stateKey,
        \StreamingContext $context,
        int $saveEvery,
        FileFetchResponseObserver $observer
    ) {
        $this->cursor = $cursor;
        $this->stateKey = $stateKey;
        $this->context = $context;
        $this->saveEvery = $saveEvery;
        $this->observer = $observer;
    }

    public function __invoke(array $chunk): void
    {
        $this->handle($chunk);
    }

    public function cursor(): ?string
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function handle(array $chunk): void
    {
        if ($this->observer->shouldStopFileFetch()) {
            throw new RuntimeException("Shutdown requested");
        }

        if (function_exists("pcntl_signal_dispatch")) {
            pcntl_signal_dispatch();
        }

        $this->checkpointIfNeeded($chunk);

        if (isset($chunk["headers"]["x-cursor"])) {
            $this->cursor = $chunk["headers"]["x-cursor"];
        }

        $chunkType = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunkType === "metadata") {
            $this->observer->handleFileFetchMetadata($chunk, $this->context);
        } elseif ($chunkType === "file") {
            $this->observer->handleFileFetchFile($chunk, $this->context);
        } elseif ($chunkType === "directory") {
            $this->observer->handleFileFetchDirectory($chunk);
        } elseif ($chunkType === "symlink") {
            $this->observer->handleFileFetchSymlink($chunk);
        } elseif ($chunkType === "missing") {
            $path = base64_decode($chunk["headers"]["x-file-path"] ?? "");
            if ($path) {
                $this->observer->handleFileFetchMissingPath($path);
            }
        } elseif ($chunkType === "error") {
            $this->observer->handleFileFetchError($chunk, "files", $this->context);
        } elseif ($chunkType === "progress") {
            $this->observer->handleFileFetchProgress($chunk, "files");
        } elseif ($chunkType === "completion") {
            $this->handleCompletion($chunk);
        }
    }

    private function checkpointIfNeeded(array $chunk): void
    {
        $isStreamingBody = !empty($chunk["is_streaming_body"]);
        $isStreamingClose = !empty($chunk["is_streaming_close"]);
        if ($isStreamingBody) {
            return;
        }

        $this->chunksSinceSave++;
        if (!$isStreamingClose && $this->chunksSinceSave < $this->saveEvery) {
            return;
        }

        $this->observer->saveFileFetchCheckpoint(
            $this->stateKey,
            $this->cursor,
            $this->context,
        );
        $this->chunksSinceSave = 0;
    }

    private function handleCompletion(array $chunk): void
    {
        $headers = $chunk["headers"];
        $this->complete = ($headers["x-status"] ?? "") === "complete";
        $this->context->saw_completion = true;
        $this->context->response_stats = [
            "status" => $headers["x-status"] ?? null,
            "bytes_processed" =>
                isset($headers["x-bytes-processed"])
                    ? (int) $headers["x-bytes-processed"]
                    : null,
            "server_time" =>
                isset($headers["x-time-elapsed"])
                    ? (float) $headers["x-time-elapsed"]
                    : null,
            "memory_used" =>
                isset($headers["x-memory-used"])
                    ? (int) $headers["x-memory-used"]
                    : null,
            "memory_limit" =>
                isset($headers["x-memory-limit"])
                    ? (int) $headers["x-memory-limit"]
                    : null,
        ];

        $this->observer->handleFileFetchCompletionProgress([
            "phase" => "files",
            "status" => $headers["x-status"] ?? "unknown",
            "files_completed" => (int) ($headers["x-files-completed"] ?? 0),
            "bytes_processed" => (int) ($headers["x-bytes-processed"] ?? 0),
        ]);
    }
}
