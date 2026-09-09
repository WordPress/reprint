<?php

namespace WordPress\Reprint\Server;

/** Fail if cursor reporting searches the table list instead of reading its position. */
function array_search() {
    throw new \RuntimeException('Progress must not search the table list.');
}
