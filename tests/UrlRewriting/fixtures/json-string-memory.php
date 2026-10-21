<?php

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Standalone memory-limit fixture.
require_once __DIR__ . '/../../../packages/reprint-client/src/lib/url-rewrite/class-json-string-iterator.php';

$json = str_repeat('{"nested":', 500) . '[' . implode(',', array_fill(0, 16000, '"before"')) . ']' . str_repeat('}', 500);
$iterator = new JsonStringIterator($json);
$count = 0;
while ($iterator->next_value()) {
    if ($iterator->get_value() !== 'before') {
        exit(1);
    }
    $iterator->set_value('after');
    ++$count;
}
if ($count !== 16000 || substr_count($iterator->get_result(), '"after"') !== 16000) {
    exit(2);
}
echo "complete\n";
