#!/usr/bin/env php
<?php
// Compatibility entry point: the wrapper moved to client/import.php when the
// importer package became reprint-client. Kept so existing scripts and
// documentation that invoke importer/import.php keep working.
require_once __DIR__ . '/../client/import.php';
