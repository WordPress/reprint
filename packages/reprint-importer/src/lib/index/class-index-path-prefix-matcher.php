<?php

namespace Reprint\Importer\Index;

use RuntimeException;
use function WordPress\Reprint\Exporter\normalize_path;

final class IndexPathPrefixMatcher
{
    private string $index_file;

    /** @var array<string,bool> */
    private array $cache = [];

    public function __construct(string $index_file)
    {
        $this->index_file = $index_file;
    }

    public function contains(string $path): bool
    {
        $path = rtrim(normalize_path($path), "/");
        if ($path === "") {
            return false;
        }

        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }

        $found = $this->scan($path);
        $this->cache[$path] = $found;

        return $found;
    }

    private function scan(string $path): bool
    {
        if (!file_exists($this->index_file)) {
            return false;
        }

        $handle = fopen($this->index_file, "r");
        if (!$handle) {
            return false;
        }

        $prefix = $path . "/";
        while (($line = fgets($handle)) !== false) {
            try {
                $entry = IndexLineParser::parse($line);
            } catch (RuntimeException $e) {
                continue;
            }
            if ($entry === null) {
                continue;
            }

            $entry_path = $entry["path"];
            if ($entry_path === $path || str_starts_with($entry_path, $prefix)) {
                fclose($handle);
                return true;
            }
        }

        fclose($handle);
        return false;
    }
}
