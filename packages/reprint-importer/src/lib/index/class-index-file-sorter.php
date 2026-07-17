<?php

namespace Reprint\Importer\Index;

use RuntimeException;
use function WordPress\Reprint\Exporter\parse_size;

final class IndexFileSorter
{
    /** @var callable|null */
    private $audit_logger;

    /** @var callable|null */
    private $progress_ticker;

    public function __construct(
        ?callable $audit_logger = null,
        ?callable $progress_ticker = null
    ) {
        $this->audit_logger = $audit_logger;
        $this->progress_ticker = $progress_ticker;
    }

    /**
     * Sort an index file by path and remove duplicate path entries.
     */
    public function sort(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (filesize($path) === 0) {
            return;
        }

        $tmp = $path . ".sorted";
        if ($this->tryExecSort($path, $tmp)) {
            return;
        }

        $mem_limit_raw = ini_get("memory_limit");
        $mem_limit = ($mem_limit_raw === "-1" || $mem_limit_raw === "" || $mem_limit_raw === "0")
            ? 0
            : parse_size($mem_limit_raw);
        $mem_used = memory_get_usage(true);
        $available = $mem_limit > 0
            ? (int) (($mem_limit - $mem_used) * 0.6)
            : 256 * 1024 * 1024;

        $sorter = new \ExternalMergeSort(
            new IndexLinePathKeyExtractor(),
            max(1024, (int) ($available * 0.8)),
            true,
            dirname($path),
        );
        $sorter->sort($path);
    }

    private function tryExecSort(string $path, string $tmp): bool
    {
        if (!$this->functionAvailable("exec")) {
            return false;
        }

        $keyed = $path . ".keyed";
        $sorted_keyed = $path . ".keyed.sorted";
        $in = fopen($path, "r");
        $out = fopen($keyed, "w");
        if (!$in || !$out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            $this->audit("Failed to prepare keyed index file, falling back to PHP sort");
            return false;
        }

        $lines_read = 0;
        while (($line = fgets($in)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === "") {
                continue;
            }
            $entry = IndexLineParser::parse($line);
            if ($entry === null) {
                continue;
            }
            $key = bin2hex($entry["path"]);
            fwrite($out, $key . "\t" . $line . "\n");
            if (++$lines_read % 500 === 0) {
                $this->tick();
            }
        }
        fclose($in);
        fclose($out);

        $cmd =
            "LC_ALL=C sort -t '\t' -k1,1 " .
            escapeshellarg($keyed) .
            " > " .
            escapeshellarg($sorted_keyed);
        $output = [];
        $code = 0;
        exec($cmd, $output, $code);
        if ($code !== 0) {
            @unlink($keyed);
            @unlink($sorted_keyed);
            $this->audit("exec() sort failed (exit code {$code}), falling back to PHP sort");
            return false;
        }

        $sorted_in = fopen($sorted_keyed, "r");
        $sorted_out = fopen($tmp, "w");
        if (!$sorted_in || !$sorted_out) {
            if ($sorted_in) {
                fclose($sorted_in);
            }
            if ($sorted_out) {
                fclose($sorted_out);
            }
            @unlink($keyed);
            @unlink($sorted_keyed);
            $this->audit("Failed to open sorted index files, falling back to PHP sort");
            return false;
        }

        $prev_key = null;
        $lines_stripped = 0;
        while (($line = fgets($sorted_in)) !== false) {
            $pos = strpos($line, "\t");
            if ($pos === false) {
                continue;
            }
            $key = substr($line, 0, $pos);
            $data = substr($line, $pos + 1);
            if ($data === "") {
                continue;
            }
            if ($key === $prev_key) {
                continue;
            }
            $prev_key = $key;
            fwrite($sorted_out, $data);
            if (++$lines_stripped % 500 === 0) {
                $this->tick();
            }
        }
        fclose($sorted_in);
        fclose($sorted_out);
        @unlink($keyed);
        @unlink($sorted_keyed);

        if (!rename($tmp, $path)) {
            throw new RuntimeException("Failed to replace sorted index file");
        }

        return true;
    }

    private function functionAvailable(string $name): bool
    {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = ini_get("disable_functions");
        if ($disabled === false || trim($disabled) === "") {
            return true;
        }
        $list = array_map("trim", explode(",", $disabled));
        return !in_array($name, $list, true);
    }

    private function audit(string $message): void
    {
        if ($this->audit_logger !== null) {
            $audit_logger = $this->audit_logger;
            $audit_logger($message);
        }
    }

    private function tick(): void
    {
        if ($this->progress_ticker !== null) {
            $progress_ticker = $this->progress_ticker;
            $progress_ticker();
        }
    }
}
