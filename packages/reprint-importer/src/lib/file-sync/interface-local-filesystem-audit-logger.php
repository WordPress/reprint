<?php

namespace Reprint\Importer\FileSync;

interface LocalFilesystemAuditLogger
{
    public function logLocalFilesystemEvent(string $message, bool $toConsole = true): void;
}
