<?php

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../packages/reprint-client/src/lib/url-rewrite/load.php';

// Six bytes leave the quote open whether the preceding candidate opened it or not.
$reprint_repeated_candidates = str_repeat('[a "\\"', 175000) . ']';
$reprint_processor = new \WordPress\DataLiberation\Shortcode\ShortcodeProcessor($reprint_repeated_candidates);
if (!$reprint_processor->next_token() || $reprint_processor->get_token_text() !== $reprint_repeated_candidates || $reprint_processor->next_token()) {
    throw new RuntimeException('Incomplete shortcode candidates must remain one unchanged text token.');
}
$reprint_rewriter = new StructuredDataUrlRewriter(['https://old.example' => 'https://new.example']);
foreach (['', '[vc_raw_html]'] as $reprint_prefix) {
    $reprint_input = '<a href="https://old.example/page">Read</a>' . $reprint_prefix . $reprint_repeated_candidates;
    $reprint_expected = str_replace('https://old.example', 'https://new.example', $reprint_input);
    if ($reprint_rewriter->rewrite($reprint_input, StructuredDataUrlRewriter::BLOCK_MARKUP) !== $reprint_expected) {
        throw new RuntimeException('The incomplete candidates must not prevent ordinary URL rewriting.');
    }
}
echo "complete\n";
