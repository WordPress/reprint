<?php

namespace Reprint\Importer\Index;

final class IndexLinePathKeyExtractor
{
    public function __invoke(string $line): ?string
    {
        $entry = IndexLineParser::parse($line);
        return $entry !== null ? $entry["path"] : null;
    }
}
