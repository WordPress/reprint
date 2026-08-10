#!/usr/bin/env php
<?php
// Signal to import.php's CLI guard that we are the entry point.
define('IMPORTER_PHAR_ENTRY', true);
Phar::mapPhar('reprint.phar');
require 'phar://reprint.phar/packages/reprint-client/src/import.php';
__HALT_COMPILER();
