<?php

namespace Reprint\Importer\FileSync;

interface IndexResponseObserver
{
    public function shouldStopIndexDownload(): bool;

    public function saveIndexDownloadCursor(?string $cursor): void;

    public function handleIndexMetadata(array $chunk, \StreamingContext $context): void;

    public function handleIndexError(array $chunk, string $phase, \StreamingContext $context): void;

    public function handleIndexProgress(array $chunk, string $phase): void;

    public function showIndexScanProgress(int $entriesCounted): void;
}
