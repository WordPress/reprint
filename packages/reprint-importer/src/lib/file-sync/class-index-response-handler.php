<?php

namespace Reprint\Importer\FileSync;

use RuntimeException;
use function WordPress\Reprint\Exporter\assert_valid_path;

final class IndexResponseHandler
{
    /** @var resource */
    private $handle;

    private ?string $cursor;
    private \StreamingContext $context;
    private int $saveEvery;
    private int $chunksSinceSave = 0;
    private int $entriesCounted;
    private bool $complete = false;
    private IndexResponseObserver $observer;

    /**
     * @param resource $handle
     */
    public function __construct(
        $handle,
        ?string $cursor,
        \StreamingContext $context,
        int $entriesCounted,
        int $saveEvery,
        IndexResponseObserver $observer
    ) {
        $this->handle = $handle;
        $this->cursor = $cursor;
        $this->context = $context;
        $this->entriesCounted = $entriesCounted;
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

    public function entriesCounted(): int
    {
        return $this->entriesCounted;
    }

    public function handle(array $chunk): void
    {
        if ($this->observer->shouldStopIndexDownload()) {
            throw new RuntimeException("Shutdown requested");
        }

        if (function_exists("pcntl_signal_dispatch")) {
            pcntl_signal_dispatch();
        }

        $this->checkpointIfNeeded();

        if (isset($chunk["headers"]["x-cursor"])) {
            $this->cursor = $chunk["headers"]["x-cursor"];
        }

        $chunkType = $chunk["headers"]["x-chunk-type"] ?? "";

        if ($chunkType === "index_batch") {
            $this->handleIndexBatch($chunk);
        } elseif ($chunkType === "progress") {
            $this->observer->handleIndexProgress($chunk, "index");
        } elseif ($chunkType === "metadata") {
            $this->observer->handleIndexMetadata($chunk, $this->context);
        } elseif ($chunkType === "completion") {
            $this->handleCompletion($chunk);
        } elseif ($chunkType === "error") {
            $this->observer->handleIndexError($chunk, "index", $this->context);
        }
    }

    private function checkpointIfNeeded(): void
    {
        $this->chunksSinceSave++;
        if ($this->chunksSinceSave < $this->saveEvery) {
            return;
        }

        $this->observer->saveIndexDownloadCursor($this->cursor);
        $this->chunksSinceSave = 0;
    }

    private function handleIndexBatch(array $chunk): void
    {
        $body = $chunk["body"] ?? "";
        if ($body === "") {
            return;
        }

        $items = json_decode($body, true);
        if (!is_array($items)) {
            throw new RuntimeException(
                "Invalid index batch JSON received from server",
            );
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $this->writeIndexItem($item);
        }

        $this->observer->showIndexScanProgress($this->entriesCounted);
    }

    private function writeIndexItem(array $item): void
    {
        $pathEncoded = $item["path"] ?? "";
        if (!is_string($pathEncoded) || $pathEncoded === "") {
            throw new RuntimeException(
                "Invalid index batch item: missing path",
            );
        }

        $path = base64_decode($pathEncoded, true);
        if ($path === "" || $path === false) {
            throw new RuntimeException(
                "Invalid index batch item: path base64 decode failed",
            );
        }
        assert_valid_path($path, "index batch path");

        $entry = [
            "path" => base64_encode($path),
            "ctime" => (int) ($item["ctime"] ?? 0),
            "size" => (int) ($item["size"] ?? 0),
            "type" => (string) ($item["type"] ?? "file"),
        ];
        if (isset($item["target"]) && is_string($item["target"]) && $item["target"] !== "") {
            $entry["target"] = $item["target"];
        }
        if (!empty($item["intermediate"])) {
            $entry["intermediate"] = true;
        }
        if (array_key_exists("empty", $item) && !is_bool($item["empty"])) {
            throw new RuntimeException(
                "Invalid index batch item: empty must be a boolean, received "
                . json_encode($item["empty"]),
            );
        }
        if (isset($item["empty"])) {
            $entry["empty"] = $item["empty"];
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return;
        }

        $bytes = fwrite($this->handle, $line . "\n");
        if ($bytes === false) {
            throw new RuntimeException("Failed to write to remote index file (disk full?)");
        }
        $this->entriesCounted++;
    }

    private function handleCompletion(array $chunk): void
    {
        $headers = $chunk["headers"];
        $this->complete = ($headers["x-status"] ?? "") === "complete";
        $this->context->saw_completion = true;
        $this->context->response_stats = [
            "status" => $headers["x-status"] ?? null,
            "entries_processed" =>
                isset($headers["x-total-entries"])
                    ? (int) $headers["x-total-entries"]
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
    }
}
